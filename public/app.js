(() => {
  'use strict';

  const BASE = window.HIKARIXD_BASE || '';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[c]));

  const fmt = n => Intl.NumberFormat('en', {
    notation: 'compact'
  }).format(n || 0);

  const store = {
    get: k => {
      try { return localStorage.getItem(k); } catch { return null; }
    },
    set: (k, v) => {
      try { localStorage.setItem(k, v); } catch {}
    }
  };

  let EPS = [];
  let STATS = { total: 0, hits: {}, days: [] };
  let LIVE = null;
  let liveAt = 0;
  let SHOW = false;
  let KEY = '';
  let HEALTH = null;

  const mask = () => '•'.repeat(Math.max(13, KEY.length));

  const badge = m =>
    `<span class="badge ${m === 'GET' ? 'get' : m.toLowerCase()}">${m}</span>`;

  const hl = o => esc(JSON.stringify(o, null, 2)).replace(
    /(&quot;(?:(?!&quot;).)*&quot;)(\s*:)?|\b(true|false|null)\b|-?\d+\.?\d*/g,
    (m, s, c) =>
      s
        ? (c
          ? `<span class="json-key">${s}</span>${c}`
          : `<span class="json-string">${s}</span>`)
        : `<span class="json-bracket">${m}</span>`
  );

  const copy = async (t, btn) => {
    try {
      await navigator.clipboard.writeText(t);
    } catch {
      const a = document.createElement('textarea');
      a.value = t;
      document.body.appendChild(a);
      a.select();
      document.execCommand('copy');
      a.remove();
    }

    if (btn) {
      const o = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-check"></i>';
      setTimeout(() => btn.innerHTML = o, 1200);
    }
  };

  // ===== DATA =====

  async function load() {
    try {
      const [a, b] = await Promise.all([
        fetch(BASE + '/api/endpoints').then(r => r.json()),
        fetch(BASE + '/api/stats').then(r => r.json())
      ]);

      EPS = a.endpoints || [];
      STATS = b;

      EPS.forEach(e => {
        e.hits = (b.hits[e.id] || {}).count || 0;
      });
    } catch (e) {
      EPS = [];
      console.error(e);
    }
  }

  // ===== VIEWS =====

  const main = $('.main-area');

  const dash = [
    $('.hero-banner'),
    $('.stats-grid'),
    $('.content-columns')
  ];

  const views = {};

  ['docs', 'health', 'apikey'].forEach(n => {
    const s = document.createElement('section');
    s.className = 'view';
    s.id = 'view-' + n;
    s.hidden = true;
    main.appendChild(s);
    views[n] = s;
  });

  const panel = (title, body, extra = '') =>
    `<section class="panel ${extra}">
      <div class="panel-header">
        <div class="panel-title">
          <span class="pink-square"></span>
          <h3>${title}</h3>
        </div>
      </div>
      <div class="panel-body">${body}</div>
    </section>`;

  const ROUTES = {
    '/': 'dashboard',
    '/docs': 'docs',
    '/health': 'health',
    '/apikey': 'apikey'
  };

  const PATHS = {
    dashboard: '/',
    docs: '/docs',
    health: '/health',
    apikey: '/apikey'
  };

  const TITLES = {
    dashboard: 'Hikarixd - Open API Platform',
    docs: 'Documentation - Hikarixd',
    health: 'Health - Hikarixd',
    apikey: 'API Key - Hikarixd'
  };

  const pathName = p => ROUTES[p.replace(/\/+$/, '') || '/'];

  function show(name) {
    if (!(name in views) && name !== 'dashboard') {
      name = 'dashboard';
    }

    setDrawer(false);

    dash.forEach(d => {
      d.hidden = name !== 'dashboard';
    });

    Object.entries(views).forEach(([k, v]) => {
      v.hidden = k !== name;
    });

    $$('.nav-item').forEach(a => {
      a.classList.toggle('active', a.dataset.nav === name);
    });

    $('#topSearch').hidden = name !== 'docs';
    $('#topSpacer').hidden = name === 'docs';

    document.title = TITLES[name] || TITLES.dashboard;

    if (name === 'docs') renderDocs();
    if (name === 'health') { renderHealth(); loadHealth(); }
    if (name === 'apikey') renderApiKey();

    window.scrollTo({ top: 0 });
  }

  function go(name, replace) {
    const p = PATHS[name] || '/';

    if (location.pathname !== p || location.search || location.hash) {
      history[replace ? 'replaceState' : 'pushState'](null, '', p);
    }

    show(name);
  }

  window.addEventListener('popstate', () => {
    show(pathName(location.pathname) || 'dashboard');
  });

  // Link internal (/docs, /health, /apikey) tanpa reload halaman.
  document.addEventListener('click', e => {
    const a = e.target.closest('a[href]');

    if (
      !a || e.defaultPrevented || e.button ||
      e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ||
      a.target === '_blank'
    ) return;

    const u = new URL(a.href, location.href);
    if (u.origin !== location.origin) return;

    const name = pathName(u.pathname);
    if (!name) return;

    e.preventDefault();
    go(name);
  });

  $('.hero-cta').onclick = () => go('docs');

  // ===== POPULAR =====

  let query = '';

  const match = e =>
    !query ||
    (e.path + e.desc + e.category).toLowerCase().includes(query);

  function renderPopular() {
    const box = $('#endpointsList');

    const list = EPS
      .filter(e => e.hits > 0 && match(e))
      .sort((a, b) => b.hits - a.hits)
      .slice(0, 5);

    box.innerHTML = list.length
      ? list.map(e => `
        <div class="endpoint-item" data-id="${e.id}">
          ${badge(e.method)}
          <span class="ep-path">${esc(e.path)}</span>
          <span class="ep-desc">
            ${esc(e.desc)}
            <em class="hits">
              <i class="fa-solid fa-fire"></i>
              ${fmt(e.hits)}
            </em>
          </span>
          <i class="fa-solid fa-chevron-right ep-arrow"></i>
        </div>
      `).join('')
      : `<div class="empty-note">
          ${query
            ? 'Tidak ada endpoint yang cocok.'
            : 'Belum ada endpoint populer. Endpoint akan muncul di sini otomatis setelah sering digunakan — coba di <a href="/docs">Documentation</a>.'}
        </div>`;

    $$('.endpoint-item', box).forEach(el => {
      el.onclick = () => {
        pending = el.dataset.id;
        go('docs');
      };
    });
  }

  $('#docsSearch').oninput = e => setQuery(e.target.value);

  // ===== DOCS =====

  const SESS = { n: 0, ok: 0, ms: 0 };
  const ST = {};

  let pending = null;

  const S = id =>
    ST[id] || (ST[id] = {
      snip: 'curl',
      rtab: 'response',
      last: {
        body: '{ }',
        headers: '',
        raw: '{ }'
      }
    });

  const getEp = c =>
    EPS.find(e => e.id === c.dataset.id);

  const vals = c =>
    Object.fromEntries(
      $$('.prm', c)
        .filter(i => i.value.trim())
        .map(i => [i.name, i.value.trim()])
    );

  const urlOf = (ep, p, ex) => {
    const q = ep.params
      .map(x => [
        x.name,
        p[x.name] || (ex ? x.example : '')
      ])
      .filter(a => a[1])
      .map(([k, v]) => `${k}=${encodeURIComponent(v)}`);

    q.push('apikey=' + (KEY || 'YOUR_API_KEY'));

    return `${location.origin}${BASE}${ep.path}?${q.join('&')}`;
  };

  const snips = (ep, u) => {
    const m = ep.method;
    const o = m === 'GET' ? '' : `, { method: "${m}" }`;

    return {
      curl: `curl -X ${m} "${u}"`,
      node: `const res = await fetch("${u}"${o});\nconst data = await res.json();\nconsole.log(data);`,
      python: `import requests\n\nr = requests.${m.toLowerCase()}("${u}")\nprint(r.json())`,
      php: `<?php\n$res = file_get_contents("${u}");\necho $res;`
    };
  };

  const SN = [
    ['curl', 'cURL'],
    ['node', 'Node.js'],
    ['python', 'Python'],
    ['php', 'PHP']
  ];

  function updSnip(c) {
    const ep = getEp(c);
    const s = S(ep.id);

    const full = snips(
      ep,
      urlOf(ep, vals(c), true)
    )[s.snip];

    $('.snip code', c).textContent =
      SHOW || !KEY
        ? full
        : full.split(KEY).join(mask());

    $$('.stab', c).forEach(b => {
      b.classList.toggle('active', b.dataset.s === s.snip);
    });
  }

  function applyShow() {
    $$('.kin').forEach(i => {
      i.value = SHOW ? KEY : mask();
    });

    $$('.eye i').forEach(i => {
      i.className = 'fa-regular ' +
        (SHOW ? 'fa-eye-slash' : 'fa-eye');
    });

    $$('.ep-card', views.docs).forEach(updSnip);

    if (!views.apikey.hidden) {
      renderApiKey();
    }
  }

  function updG() {
    if (!$('#gs')) return;

    $('#gsReq').textContent = SESS.n;

    $('#gsRate').textContent =
      SESS.n
        ? Math.round(SESS.ok / SESS.n * 100) + '%'
        : '100%';

    $('#gsLat').textContent =
      SESS.n
        ? Math.round(SESS.ms / SESS.n) + 'ms'
        : '-';
  }

  function showResp(c) {
    const s = S(c.dataset.id);

    $('.out code', c).innerHTML =
      s.rtab === 'response'
        ? s.last.body
        : esc(s.last.headers);
  }

  const card = e => `
    <article class="ep-card" data-id="${e.id}">
      <header class="ep-head">
        <span class="mbadge m-${e.method.toLowerCase()}">
          ${e.method}
        </span>

        <div class="ep-ttl">
          <div class="ep-p">${esc(e.path)}</div>
          <span class="pill">${esc(e.category)}</span>
        </div>

        <i class="fa-solid fa-code ep-ic"></i>
        <i class="fa-solid fa-caret-down ep-car"></i>
      </header>

      <div class="ep-body">
        <p class="ep-d">${esc(e.desc)}</p>

        <div class="fld">
          <span>APIKEY</span>
          <div class="keyrow">
            <input
              class="inp kin"
              readonly
              value="${esc(SHOW ? KEY : mask())}"
            >
            <button
              type="button"
              class="bx eye"
              title="Tampilkan/sembunyikan"
            >
              <i class="fa-regular ${SHOW ? 'fa-eye-slash' : 'fa-eye'}"></i>
            </button>
          </div>
          <small>${KEY ? 'API Key otomatis (tekan ikon mata untuk melihat)' : 'API key belum tersedia, buka menu API Key'}</small>
        </div>

        ${e.params.map(p => `
          <label class="fld">
            <span>
              ${esc(p.name.toUpperCase())}${p.required ? ' *' : ''}
            </span>
            <input
              class="inp prm"
              name="${esc(p.name)}"
              placeholder="Input ${esc(p.name)}"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
            >
            <small>${esc(p.desc)}</small>
          </label>
        `).join('')}

        <div class="btn-row">
          <button class="bx run">Execute</button>
          <button class="bx copyurl">Copy URL</button>
          <button class="bx clear">Clear</button>
        </div>

        <div class="snipbox">
          <div class="stabs">
            ${SN.map(([k, l]) => `
              <button class="stab" data-s="${k}">
                ${l}
              </button>
            `).join('')}
          </div>

          <div class="snip-head">
            <span>SNIPPET</span>
            <button class="bx sm copysnip">Copy</button>
          </div>

          <pre class="snip"><code></code></pre>
        </div>

        <div class="resp" hidden>
          <div class="response-viewer">
            <div class="response-tabs">
              <div class="tabs-left">
                <button class="tab-btn rtab active" data-t="response">
                  Response
                </button>
                <button class="tab-btn rtab" data-t="headers">
                  Headers
                </button>
              </div>
              <span class="meta"></span>
              <button class="copy-btn cpresp" title="Copy">
                <i class="fa-regular fa-copy"></i>
              </button>
            </div>

            <pre class="code-output out"><code></code></pre>
          </div>
        </div>
      </div>
    </article>
  `;

  function renderDocs() {
    views.docs.innerHTML = `
      ${window.APIHUB_BANNER
        ? `<div class="banner"><img src="${esc(window.APIHUB_BANNER)}" alt=""></div>`
        : ''}

      <section class="gs" id="gs">
        <div class="gs-t">
          <i class="dot"></i> GLOBAL STATS
        </div>

        <div class="gs-g">
          <div>
            <small>REQ/SESSION</small>
            <b id="gsReq">0</b>
          </div>

          <div>
            <small>ENDPOINTS</small>
            <b>${EPS.length}</b>
          </div>

          <div>
            <small>SUCCESS RATE</small>
            <b id="gsRate">100%</b>
          </div>

          <div>
            <small>AVG LATENCY</small>
            <b id="gsLat">-</b>
          </div>
        </div>
      </section>

      <div id="docList"></div>
    `;

    renderDocList();
    updG();
  }

  function renderDocList() {
    const box = $('#docList');
    if (!box) return;

    const list = EPS.filter(match);

    const groups = new Map();
    list.forEach(e => {
      const k = e.category || 'Lainnya';
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push(e);
    });

    box.innerHTML = list.length
      ? [...groups.entries()].map(([k, items]) => `
        <div class="doc-cat">
          <span class="pink-square"></span>
          <h3>${esc(k)}</h3>
          <b>${items.length}</b>
        </div>
        ${items.map(card).join('')}
      `).join('')
      : `<div class="empty-note">${EPS.length
          ? 'Tidak ada endpoint yang cocok.'
          : 'Endpoint gagal dimuat. Pastikan server PHP berjalan, lalu buka <a href="' + BASE + '/api/endpoints" target="_blank" rel="noopener">/api/endpoints</a> untuk memeriksa.'}</div>`;

    $$('.ep-card', box).forEach(updSnip);

    if (pending) {
      const c = $(`.ep-card[data-id="${pending}"]`, box);
      pending = null;

      if (c) {
        c.classList.add('open');

        setTimeout(() => {
          c.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }, 60);
      }
    } else if (list.length === 1) {
      $('.ep-card', box).classList.add('open');
    }
  }

  function setQuery(v) {
    query = String(v || '').trim().toLowerCase();

    const s = $('#docsSearch');

    if (s && s.value !== v) {
      s.value = v;
    }

    renderPopular();

    if (!views.docs.hidden) {
      renderDocList();
    }
  }

  async function run(c) {
    const ep = getEp(c);
    const s = S(ep.id);
    const p = vals(c);
    const btn = $('.run', c);

    $$('.prm', c).forEach(i => {
      i.classList.remove('err');
    });

    const miss = ep.params.find(x =>
      x.required && !p[x.name]
    );

    if (miss) {
      const i = $(`.prm[name="${miss.name}"]`, c);
      i.classList.add('err');
      i.focus();
      return;
    }

    if (!KEY) {
      const j0 = {
        status: false,
        message: 'API key belum tersedia, buka menu API Key lalu coba lagi'
      };

      s.last = { raw: JSON.stringify(j0, null, 2), body: hl(j0), headers: '' };
      $('.resp', c).hidden = false;
      $('.meta', c).innerHTML = '<b class="bad">401</b>';
      showResp(c);
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Loading…';

    $('.resp', c).hidden = false;

    const t0 = performance.now();

    let ok = false;
    let status = 'ERR';
    let j;

    try {
      const r = await fetch(
        urlOf(ep, p, false),
        { method: ep.method }
      );

      const text = await r.text();

      try {
        j = JSON.parse(text);
      } catch {
        j = {
          status: false,
          message: text.slice(0, 300)
        };
      }

      ok = r.ok;
      status = r.status;

      s.last = {
        raw: JSON.stringify(j, null, 2),
        body: hl(j),
        headers: [...r.headers]
          .map(([k, v]) => `${k}: ${v}`)
          .join('\n')
      };

    } catch (err) {
      j = {
        status: false,
        message: 'Gagal terhubung ke server: ' + err.message
      };

      s.last = {
        raw: JSON.stringify(j, null, 2),
        body: hl(j),
        headers: ''
      };
    }

    const ms = Math.round(performance.now() - t0);

    SESS.n++;

    if (ok) SESS.ok++;

    SESS.ms += ms;

    updG();

    $('.meta', c).innerHTML = `
      <b class="${ok ? 'ok' : 'bad'}">${status}</b> · ${ms}ms
    `;

    showResp(c);

    btn.disabled = false;
    btn.textContent = 'Execute';

    refreshStats();
  }

  views.docs.addEventListener('click', e => {
    const t = e.target;
    const c = t.closest('.ep-card');

    if (!c) return;

    const ep = getEp(c);
    const s = S(ep.id);
    const el = sel => t.closest(sel);

    if (el('.ep-head')) {
      return void c.classList.toggle('open');
    }

    if (el('.stab')) {
      s.snip = el('.stab').dataset.s;
      return updSnip(c);
    }

    if (el('.copysnip')) {
      return copy(
        snips(ep, urlOf(ep, vals(c), true))[s.snip],
        el('.copysnip')
      );
    }

    if (el('.eye')) {
      SHOW = !SHOW;
      return applyShow();
    }

    if (el('.copyurl')) {
      return copy(
        urlOf(ep, vals(c), false),
        el('.copyurl')
      );
    }

    if (el('.cpresp')) {
      return copy(s.last.raw, el('.cpresp'));
    }

    if (el('.rtab')) {
      s.rtab = el('.rtab').dataset.t;

      $$('.rtab', c).forEach(b => {
        b.classList.toggle(
          'active',
          b.dataset.t === s.rtab
        );
      });

      return showResp(c);
    }

    if (el('.clear')) {
      $$('.prm', c).forEach(i => {
        i.value = '';
        i.classList.remove('err');
      });

      $('.resp', c).hidden = true;

      return updSnip(c);
    }

    if (el('.run')) {
      run(c);
    }
  });

  views.docs.addEventListener('input', e => {
    const c = e.target.closest('.ep-card');

    if (c && e.target.classList.contains('prm')) {
      updSnip(c);
    }
  });

  views.docs.addEventListener('keydown', e => {
    if (
      e.key === 'Enter' &&
      e.target.classList.contains('prm')
    ) {
      run(e.target.closest('.ep-card'));
    }
  });

  // ===== HEALTH =====

  const CHECKS = {
    php_8_4: 'PHP 8.4',
    curl: 'cURL',
    json: 'JSON',
    openssl: 'OpenSSL',
    mongodb: 'MongoDB Atlas'
  };

  async function loadHealth() {
    try {
      HEALTH = await fetch(BASE + '/api/health', {
        cache: 'no-store'
      }).then(r => r.json());
    } catch {
      HEALTH = null;
    }

    if (!views.health.hidden) renderHealth();
  }

  function renderHealth() {
    const ok = !!(HEALTH && HEALTH.status);
    const up = LIVE && LIVE.uptime;

    views.health.innerHTML = panel('Health', `
      <div class="chips">
        <span class="chip ${ok ? 'ok' : 'bad'}">
          <i class="dot"></i>
          ${HEALTH ? (ok ? 'Healthy' : 'Degraded') : 'Offline'}
        </span>
        <span class="chip"><code>GET /api/health</code></span>
      </div>

      <div class="kv">
        <div>
          <small>Uptime 30 hari</small>
          <b>${up ? LIVE.uptime.percent + '%' : '-'}</b>
        </div>
        <div>
          <small>Avg response</small>
          <b>${LIVE && LIVE.avgMs != null ? LIVE.avgMs + 'ms' : '-'}</b>
        </div>
        <div>
          <small>Berjalan</small>
          <b>${up ? dur(LIVE.uptime.runningSec + (performance.now() - liveAt) / 1000) : '-'}</b>
        </div>
        <div>
          <small>Active users</small>
          <b>${LIVE ? fmt(LIVE.activeUsers) : '-'}</b>
        </div>
      </div>

      ${up ? `
        <div class="uptime-bars big">
          ${LIVE.uptime.days.map((v, i) => `
            <div
              class="uptime-bar${v == null ? ' na' : ''}"
              title="${v == null ? 'Belum ada data' : v + '% uptime'} · ${30 - i - 1} hari lalu"
              style="height:${v == null ? 6 : Math.max(6, v)}%"
            ></div>
          `).join('')}
        </div>
      ` : ''}

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Pemeriksaan</th><th>Status</th></tr>
          </thead>
          <tbody>
            ${HEALTH
              ? Object.entries(HEALTH.checks || {}).map(([k, v]) => `
                <tr>
                  <td>${esc(CHECKS[k] || k)}</td>
                  <td><span class="chip ${v ? 'ok' : 'bad'}"><i class="dot"></i> ${v ? 'OK' : 'Gagal'}</span></td>
                </tr>
              `).join('')
              : '<tr><td colspan="2">Tidak dapat terhubung ke server.</td></tr>'}
          </tbody>
        </table>
      </div>
    `);
  }

  setInterval(() => {
    if (!document.hidden && !views.health.hidden) loadHealth();
  }, 15000);

  // ===== API KEY =====

  function renderApiKey() {
    views.apikey.innerHTML = panel('API Key', `
      <p class="muted">
        Gunakan API key ini pada parameter <code>apikey</code>
        atau header <code>X-API-Key</code> saat memanggil endpoint downloader.
      </p>

      <div class="key-hero">
        <div class="key-lbl">API KEY</div>

        <div class="key-box">
          <i class="fa-solid fa-key"></i>
          <code>${KEY ? esc(SHOW ? KEY : mask()) : 'API key belum tersedia'}</code>
        </div>

        <div class="key-actions">
          <button class="btn-brutal eye" ${KEY ? '' : 'disabled'}>
            <i class="fa-regular ${SHOW ? 'fa-eye-slash' : 'fa-eye'}"></i>
            ${SHOW ? 'Sembunyikan' : 'Tampilkan'}
          </button>

          <button class="btn-brutal alt" id="cpKey" ${KEY ? '' : 'disabled'}>
            <i class="fa-regular fa-copy"></i>
            Salin Key
          </button>

          ${KEY ? '' : `
          <button class="btn-brutal alt" id="reloadKey">
            <i class="fa-solid fa-rotate"></i>
            Muat Ulang
          </button>`}
        </div>
      </div>
    `);

    const cp = $('#cpKey', views.apikey);
    if (cp && KEY) cp.onclick = e => copy(KEY, e.currentTarget);

    const eye = $('.eye', views.apikey);
    if (eye && KEY) {
      eye.onclick = () => {
        SHOW = !SHOW;
        applyShow();
      };
    }

    const rl = $('#reloadKey', views.apikey);
    if (rl) {
      rl.onclick = async () => {
        await loadKey();
        renderApiKey();
      };
    }
  }

  // ===== API KEY LOAD =====

  async function loadKey() {
    try {
      KEY = (
        await fetch(BASE + '/api/key', {
          cache: 'no-store'
        }).then(r => r.json())
      ).apiKey || '';
    } catch {}
  }

  // ===== LIVE STATS =====

  const set = (id, v) => {
    const el = $('#' + id);
    if (el) el.textContent = v;
  };

  const dur = t => {
    t = Math.max(0, Math.floor(t));

    const d = Math.floor(t / 86400);
    const h = Math.floor(t % 86400 / 3600);
    const m = Math.floor(t % 3600 / 60);
    const s = t % 60;

    return (
      (d ? d + 'h ' : '') +
      (h || d ? h + 'j ' : '') +
      (m || h || d ? m + 'm ' : '') +
      s + 'd'
    );
  };

  const bars = $('#uptimeBars');

  function renderLive() {
    const on = !!LIVE;

    $('#statusInd').classList.toggle('off', !on);

    set('statusTxt', on ? 'Operational' : 'Offline');

    if (!on) {
      set('statusMsg', 'Tidak dapat terhubung ke server.');
      return;
    }

    set('stTotal', fmt(LIVE.total));

    set(
      'stAvg',
      LIVE.avgMs == null ? '-' : LIVE.avgMs + 'ms'
    );

    set('stUptime', LIVE.uptime.percent + '%');
    set('stActive', fmt(LIVE.activeUsers));

    set('kTotal', fmt(LIVE.total));
    set('kToday', fmt(LIVE.today));

    bars.innerHTML = LIVE.uptime.days.map((v, i) => `
      <div
        class="uptime-bar${v == null ? ' na' : ''}"
        title="${v == null ? 'Belum ada data' : v + '% uptime'} · ${30 - i - 1} hari lalu"
        style="height:${v == null ? 6 : Math.max(6, v)}%"
      ></div>
    `).join('');

    tick();
  }

  function tick() {
    if (!LIVE) return;

    const run =
      LIVE.uptime.runningSec +
      (performance.now() - liveAt) / 1000;

    set(
      'statusMsg',
      `Server berjalan ${dur(run)} · uptime 30 hari ${LIVE.uptime.percent}%`
    );
  }

  async function pollLive() {
    try {
      LIVE = await fetch(BASE + '/api/live', {
        cache: 'no-store'
      }).then(r => r.json());

      liveAt = performance.now();

    } catch {
      LIVE = null;
    }

    renderLive();

    if (!views.health.hidden) renderHealth();
  }

  setInterval(() => {
    if (!document.hidden) pollLive();
  }, 5000);

  setInterval(tick, 1000);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) pollLive();
  });

  function renderStats() {
    renderLive();
  }

  async function refreshStats() {
    await Promise.all([
      load(),
      pollLive()
    ]);

    renderPopular();
  }

  // ===== DRAWER =====

  const mb = $('#menuBtn');

  function setDrawer(o) {
    document.body.classList.toggle('drawer-open', o);
    mb.setAttribute('aria-expanded', String(o));
  }

  mb.onclick = () => {
    setDrawer(!document.body.classList.contains('drawer-open'));
  };

  $('#overlay').onclick = () => setDrawer(false);

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') setDrawer(false);
  });

  // ===== INITIALIZE =====

  (async () => {
    await Promise.all([
      load(),
      loadKey(),
      pollLive()
    ]);

    renderPopular();

    // Kompatibilitas link lama (/#docs, /#keys, dst.) -> URL bersih.
    const legacy = {
      docs: 'docs', explorer: 'docs', keys: 'apikey',
      apikey: 'apikey', settings: 'apikey', health: 'health'
    }[location.hash.replace('#', '')];

    if (legacy && location.pathname === '/') {
      return go(legacy, true);
    }

    const name = pathName(location.pathname);

    if (name) {
      show(name);
    } else {
      go('dashboard', true);
    }
  })();

})();
