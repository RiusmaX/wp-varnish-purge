# Varnish Network Purge

WordPress **multisite** plugin to purge the **Varnish** cache of every site in the network — or just one — without touching the command line.

Built for a multisite network with **domain mapping** sitting behind Varnish (e.g. Infomaniak), where each site has its own domain. The list of domains is fetched **dynamically** via `get_sites()`, so any new network site is handled automatically.

## Features

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

For each target, the plugin sends two `PURGE` HTTP requests in parallel (`curl_multi`):

```
PURGE https://example.com/
PURGE https://example.com/*
```

`/*` covers the whole site (including subdirectory sub-sites that share the domain).

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

- WordPress multisite (5.2+), PHP 7.2+ with the cURL extension.
- A Varnish front end that accepts the `PURGE` method from the origin server.

## License

GPL-2.0-or-later.
