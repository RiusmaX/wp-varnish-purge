# Varnish Network Purge

WordPress **multisite** plugin to purge the **Varnish** cache of every site in the network — or just one — without touching the command line.

Built for a multisite network with **domain mapping** sitting behind Varnish (e.g. Infomaniak), where each site has its own domain. The list of domains is fetched **dynamically** via `get_sites()`, so any new network site is handled automatically.

## Features

- **Automatic targeted purge** — when a post, page or term is saved (created, updated, trashed, deleted), the plugin automatically purges its URL, the old URL if the slug changed, the home page, and the relevant archives. Menu changes, Customizer saves and theme switches trigger a purge of the whole site.
- **Network admin** (`Network → Settings → Varnish Cache`)
  - Global purge of every network domain in one pass.
  - Per-site purge (a table listing each domain).
  - Secret URL (token), regenerable.
- **Site settings** (`Settings → Varnish Cache`)
  - Button to purge the **current site** only (available to site administrators, `manage_options` capability).
- **Admin bar** (top bar)
  - "Varnish Cache" shortcut with *Purge this site* and, for super admins, *Purge the whole network*.
- **URL trigger** (curl / cron / bookmark)
  - `https://example.com/?varnish_purge=TOKEN` → purge the whole network.
  - `…&host=DOMAIN` → purge a single site.

## How it works

Many managed Varnish setups (Infomaniak among them) only honour **exact-URL** purges: `PURGE /*` or regex `BAN`s answer `200 Purged` but evict **nothing** (verified empirically — the response is the same whether an object matched or not). So the plugin never relies on wildcards; it enumerates real URLs and sends **one `PURGE` request per URL**, in parallel batches (`curl_multi`, 50 per batch):

- **On content change** (save/trash/delete of a post, page or term): the affected URLs only — permalink, old permalink if the slug changed, home page, post type archive, term archives.
- **On a manual site purge**: every known URL of the site — home page, all published content of public post types, post type archives, term archives (capped at 5000 URLs per site).
- **On a network purge**: the same, for every site of the network.

Two filters allow adjusting the URL lists: `vnp_post_urls( $urls, $post )` and `vnp_site_urls( $urls )`.

Known limit: paginated archive pages (`/page/2/`…) and date archives are not enumerated; they expire with their natural TTL.

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
- A Varnish front end that accepts the `PURGE` method from the origin server (exact-URL purge is enough; no wildcard/ban support required).

## Translations

The admin interface is fully translatable (text domain `varnish-network-purge`). A **French** translation is bundled (`languages/varnish-network-purge-fr_FR.po` / `.mo`): a WordPress site running in French shows French, any other locale falls back to the English source strings. A `.pot` template is included to add more languages.

The token URL endpoint (curl / cron output) intentionally stays in English so scripts get deterministic, locale-independent responses.

## License

GPL-2.0-or-later.
