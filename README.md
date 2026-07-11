# Varnish Network Purge

WordPress **multisite** plugin to purge the **Varnish** cache of every site in the network — or just one — without touching the command line.

Built for a multisite network with **domain mapping** sitting behind Varnish (e.g. Infomaniak), where each site has its own domain. The list of domains is fetched **dynamically** via `get_sites()`, so any new network site is handled automatically.

## Features

- **Automatic targeted purge** — when a post, page or term is saved (created, updated, trashed, deleted), the plugin automatically purges its URL, the old URL if the slug changed, the home page, and the relevant archives. Menu changes, Customizer saves and theme switches trigger a purge of the whole site.
- **WP Fastest Cache synchronization** — stacked page caches drift apart: WPFC can keep serving HTML that references deleted minified CSS/JS bundles, which Varnish then re-caches (broken styling for visitors while everything looks fine in the editor). When WPFC is active, every purge of this plugin clears the matching WPFC cache first (pages + minified assets, per site), and conversely clearing WPFC (admin bar "Delete Cache", its own hooks) automatically triggers the matching Varnish purge. No configuration needed; inactive if WPFC is absent.
- **Network admin** (`Network → Settings → Varnish Cache`)
  - Global purge of every network domain in one pass.
  - Per-site purge (a table listing each domain).
  - Secret URL (token), regenerable.
- **Site settings** (`Settings → Varnish Cache`)
  - Button to purge the **current site** only (available to site administrators, `manage_options` capability).
- **Admin bar** (top bar)
  - "Varnish Cache" shortcut with *Purge this page* (the front-end page being viewed), *Purge this site* and, for super admins, *Purge the whole network*.
- **URL trigger** (curl / cron / bookmark)
  - `https://example.com/?varnish_purge=TOKEN` → purge the whole network.
  - `…&host=DOMAIN` → purge a single site.

## How it works

The plugin targets a VCL implementing the [Proxy Cache Purge](https://wordpress.org/plugins/varnish-http-purge/) convention:

- `PURGE <url>` without header → **exact-URL** purge (Varnish's native `purge`, query string stripped).
- `PURGE <url>` with `X-Purge-Method: regex` → a **ban** on `obj.http.x-url ~ <url> && obj.http.x-host ~ <host>` (requires the VCL to stamp `x-url` / `x-host` on objects in `vcl_backend_response`).

Beware: a bare `PURGE https://example.com/*` does **not** work on such a VCL — without the header it purges the literal object `/*` and still answers `200 Purged` (the response is the same whether an object matched or not; verified empirically). That trap is what this plugin's v1.1 rework fixed, and v1.2 now uses the proper header.

Purges are sent in parallel batches (`curl_multi`, 50 per batch):

- **On content change** (save/trash/delete of a post, page or term): precise exact-URL purges — permalink, old permalink if the slug changed, home page, post type archive, term archives — so the rest of the cache stays warm. Queued and flushed once at `shutdown`. The `vnp_post_urls( $urls, $post )` filter adjusts the list.
- **On a manual site purge** (admin button, admin bar, `&host=` URL trigger) and on site-wide changes (menu, Customizer, theme switch): a single `PURGE https://domain/` with `X-Purge-Method: regex`, which wipes everything for the domain — pages, pagination, feeds, static assets, and subdirectory sub-sites sharing it.
- **On a network purge**: one such wildcard request per network domain.

Note: `X-Purge-Method: exact` exists in the reference VCL but is buggy there (it matches `obj.http.X-Req-Host`, a header the VCL never sets), so the plugin uses the header-less exact purge instead.

## Reference VCL

[`vcl/default.vcl`](vcl/default.vcl) is a complete, generic Varnish 6.0 configuration implementing everything this plugin expects — exact and regex purges, lurker-friendly bans via `x-url`/`x-host` object headers — plus sane WordPress / WooCommerce / PrestaShop caching rules: session and auth cookies bypass, `wp-admin` / `wp-json` / cart / checkout / back-office exclusions, tracking-parameter stripping (`utm_*`, `fbclid`, `gclid`…), static-asset caching with cookie neutralization, 4h grace, and an HTTPS redirect guarded against missing `X-Forwarded-Proto`. The `X-Purge-Method: exact` bug mentioned above is fixed in this version.

Before deploying: fill the `purgers` ACL with the outbound IPs of your web servers, and adjust the backend address. The file is validated with `varnishd -C` and a `varnishtest` functional suite covering caching, both purge methods, session bypass and static handling.

## Security

- Back-office purges: WordPress capabilities + nonce.
  - Global / per-site purge and token regeneration: `manage_network` (super admin).
  - Current-site purge: `manage_options` (site administrator).
- URL endpoint: secret token compared with `hash_equals` (timing-safe), `nocache` headers, and a **per-target throttle** (10 s between two purges of the same target) to avoid a *cache stampede*.

## Installation

1. Copy the `varnish-network-purge/` folder into `wp-content/plugins/`.
2. In **Network Admin → Plugins**, click **Network Activate**.
3. Open **Network → Settings → Varnish Cache** to grab the URL/token.

## Requirements

- WordPress multisite (5.6+), PHP 7.2+ with the cURL extension.
- A Varnish front end that accepts the `PURGE` method from the origin server and implements the `X-Purge-Method: regex` ban convention (see *How it works*).

## Translations

The admin interface is fully translatable (text domain `varnish-network-purge`). A **French** translation is bundled (`languages/varnish-network-purge-fr_FR.po` / `.mo`): a WordPress site running in French shows French, any other locale falls back to the English source strings. A `.pot` template is included to add more languages.

The token URL endpoint (curl / cron output) intentionally stays in English so scripts get deterministic, locale-independent responses.

## License

GPL-2.0-or-later.
