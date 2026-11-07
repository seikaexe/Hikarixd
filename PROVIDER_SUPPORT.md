# Provider implementation matrix

The API routes and original provider names are retained. This matrix distinguishes an actual download adapter from a metadata-only fallback; it does not label every route as a working downloader.

| Platform/provider group | PHP implementation |
|---|---|
| TikTok (all five provider aliases) | TikWM JSON API adapter; video/image URLs |
| Instagram (Indown/SnapInsta/SnapSave aliases) | Indown AJAX adapter; public posts/reels only |
| Facebook (SnapSave/FDown aliases) | SnapSave AJAX adapter; public videos only |
| YouTube (ytmp3/ytmp3gg) | Convert1s conversion API; MP4/MP3 polling |
| Reddit (RapidSave) | RapidSave HTML adapter |
| X/Twitter (three aliases) | FxTwitter API adapter |
| MediaFire | HTML download-link extraction |
| Unshorten | cURL redirect resolution |
| Apple Music, Bandcamp, Pinterest, Pixiv, Threads, SoundCloud, Spotify, TeraBox, other resolver providers, YouTube playlist | Metadata/basic fallback only unless an external provider is configured |

Some source services may block datacenter/shared-hosting IPs, change HTML or API formats, rate-limit, or require browser challenges. That can make a provider unavailable even when its PHP adapter is syntactically valid. Do not treat metadata-only results as a downloadable file.

To supply a real downloader for a remaining provider, add its API URL and key under `external_providers` in `config/config.php`. The endpoint contract is documented in README.md.
