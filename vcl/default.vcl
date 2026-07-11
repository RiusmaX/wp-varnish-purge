# ---------------------------------------------------------------------------
# Varnish 6.0 configuration for WordPress sites (multisite / WooCommerce)
# and PrestaShop sites sharing the same instance.
#
# Purge conventions implemented (compatible with the "Varnish Network Purge"
# and "Proxy Cache Purge" WordPress plugins):
#
#   PURGE <url>                           -> exact-URL purge (query stripped)
#   PURGE <url> + "X-Purge-Method: regex" -> ban obj.http.x-url ~ <url>,
#                                            scoped to the request host
#   PURGE <url> + "X-Purge-Method: exact" -> ban on the exact URL
#
#   e.g. wipe a whole domain:
#     curl -X PURGE -H "X-Purge-Method: regex" https://example.com/
#
# Requirements:
#   - The TLS terminator / load balancer in front must set X-Forwarded-Proto.
#   - Fill the "purgers" ACL below with the IPs allowed to purge.
#
# Note: the VCL answers "200 Purged" whether an object matched or not.
# To check a purge really worked, verify the page goes back to
# "x-cache: MISS" / "age: 0" with: curl -sI <url>
# ---------------------------------------------------------------------------

vcl 4.0;

import std;

# Backend: the web server (Apache/Nginx + PHP) serving the sites.
backend default {
	.host = "127.0.0.80";
	.port = "80";
	.connect_timeout = 5s;
	.first_byte_timeout = 60s;
	.between_bytes_timeout = 60s;
}

# IPs allowed to send PURGE/BAN requests. Keep localhost and add the
# OUTBOUND public IPs of the web servers (purge plugins call the public
# URL, so Varnish sees the server's outbound address), plus admin IPs.
acl purgers {
	"localhost";
	"127.0.0.1";
	"127.0.0.80";
	"::1";
	# "203.0.113.10";   # example: web server outbound IPv4
	# "2001:db8::10";   # example: admin workstation IPv6
}

sub vcl_synth {

	# Custom status 750 = HTTP -> HTTPS redirect built in vcl_recv.
	if (resp.status == 750) {
		set resp.status = 301;
		set resp.http.Location = req.http.x-Redir-Url;
		return (deliver);
	}

}

sub vcl_recv {

	# Strip tracking query parameters added by Facebook, Google, Mailchimp,
	# TikTok, Bing…: the page content is identical with or without them,
	# so removing them avoids caching many copies of the same page.
	if (req.url ~ "(\?|&)(fbclid|gclid|msclkid|ttclid|mc_cid|mc_eid|utm_[a-z]+)=") {
		set req.url = regsuball(req.url, "(fbclid|gclid|msclkid|ttclid|mc_cid|mc_eid|utm_[a-z]+)=[-_A-Za-z0-9+()%.]+&?", "");
		set req.url = regsub(req.url, "[?&]+$", "");
	}

	# Remove a trailing empty query string ("/page/?" -> "/page/").
	if (req.url ~ "\?$") {
		set req.url = regsub(req.url, "\?$", "");
	}

	# Remove the port from the Host header ("example.com:443" -> "example.com")
	# so the cache key does not depend on it.
	set req.http.Host = regsub(req.http.Host, ":[0-9]+", "");

	# Sort query string parameters alphabetically: "?b=2&a=1" and "?a=1&b=2"
	# then share the same cache entry.
	set req.url = std.querysort(req.url);

	# Remove the "Proxy" header to mitigate the httpoxy vulnerability.
	# See https://httpoxy.org/
	unset req.http.proxy;

	# ------------------------------------------------------------------
	# Cache invalidation (PURGE / BAN), restricted to the "purgers" ACL.
	# ------------------------------------------------------------------
	if (req.method == "PURGE" || req.method == "BAN") {

		if (!client.ip ~ purgers) {
			return (synth(405, "This IP is not allowed to send PURGE requests."));
		}

		# Regex invalidation: bans every cached object of this host whose
		# URL matches req.url. Bans only reference obj.* attributes
		# (x-url / x-host are stamped in vcl_backend_response), so they
		# stay "lurker-friendly" and are cleaned up in the background.
		if (req.http.X-Purge-Method == "regex") {
			ban("obj.http.x-url ~ " + req.url + " && obj.http.x-host ~ " + req.http.host);
			return (synth(200, "Purged"));
		}

		# Exact-URL invalidation through a ban (keeps the query string,
		# unlike the plain purge below).
		if (req.http.X-Purge-Method == "exact") {
			ban("obj.http.x-url == " + req.url + " && obj.http.x-host == " + req.http.host);
			return (synth(200, "Purged"));
		}

		# Default: native exact purge of the URL without its query string.
		set req.url = regsub(req.url, "\?.*$", "");
		return (purge);

	} else {

		# Redirect HTTP -> HTTPS, but only when the front proxy actually
		# tells us the scheme: without this guard, a missing header would
		# cause an infinite redirect loop.
		if (req.http.X-Forwarded-Proto && req.http.X-Forwarded-Proto !~ "(?i)https") {
			set req.http.x-Redir-Url = "https://" + req.http.host + req.url;
			return (synth(750));
		}
	}

	# Websocket upgrades cannot be cached: hand the connection over as-is.
	if (req.http.Upgrade ~ "(?i)websocket") {
		return (pipe);
	}

	# Only handle standard HTTP request methods; pipe anything exotic.
	if (
		req.method != "GET" &&
		req.method != "HEAD" &&
		req.method != "PUT" &&
		req.method != "POST" &&
		req.method != "PATCH" &&
		req.method != "TRACE" &&
		req.method != "OPTIONS" &&
		req.method != "DELETE"
	) {
		return (pipe);
	}

	# Only GET and HEAD are cacheable.
	if (req.method != "GET" && req.method != "HEAD") {
		set req.http.X-Cacheable = "NO:REQUEST-METHOD";
		return (pass);
	}

	# Static files: mark them (X-Static-File is reused in
	# vcl_backend_response) and drop cookies so they always hit the cache.
	if (req.url ~ "^[^?]*\.(7z|avi|avif|bmp|bz2|css|csv|doc|docx|eot|flac|flv|gif|gz|ico|jpeg|jpg|js|json|less|mka|mkv|mov|mp3|mp4|mpeg|mpg|odt|ogg|ogm|opus|otf|pdf|png|ppt|pptx|rar|rtf|svg|svgz|swf|tar|tbz|tgz|ttf|txt|txz|wav|webm|webp|woff|woff2|xls|xlsx|xml|xz|zip)(\?.*)?$") {
		set req.http.X-Static-File = "true";
		unset req.http.Cookie;
		return (hash);
	}

	# Never cache: logged-in users (session cookies) and URLs that are
	# user-specific or must stay dynamic.
	if (
		# --- Session / auth cookies (WordPress, WooCommerce, PHP) ---
		req.http.Cookie ~ "wordpress_(?!test_)[a-zA-Z0-9_]+|wp-postpass|comment_author_[a-zA-Z0-9_]+|woocommerce_cart_hash|woocommerce_items_in_cart|wp_woocommerce_session_[a-zA-Z0-9]+|wordpress_logged_in_|comment_author|PHPSESSID|wp-resetpass-[a-zA-Z0-9]" ||
		req.http.Authorization ||

		# --- Generic no-cache switches ---
		req.url ~ "nocache" ||
		req.url ~ "no_cache" ||

		# --- WordPress core ---
		req.url ~ "^/wp-admin" ||
		req.url ~ "^/wp-login.php" ||
		req.url ~ "^/wp-activate.php" ||
		req.url ~ "^/wp-comments-post.php" ||
		req.url ~ "^/wp-cron.php" ||
		req.url ~ "^/wp-mail.php" ||
		req.url ~ "^/xmlrpc.php" ||
		req.url ~ "^/wp-json" ||
		req.url ~ "preview=" ||
		req.url ~ "^/\.well-known/acme-challenge/" ||

		# --- WooCommerce / Easy Digital Downloads ---
		req.url ~ "^/cart" ||
		req.url ~ "^/checkout" ||
		req.url ~ "^/my-account" ||
		req.url ~ "^/product" ||
		req.url ~ "add_to_cart" ||
		req.url ~ "add-to-cart=" ||
		req.url ~ "wc-api=" ||
		req.url ~ "wc-ajax=" ||
		req.url ~ "^/wc-api" ||
		req.url ~ "edd_action" ||

		# --- PrestaShop (English and French routes) ---
		req.url ~ "^/admin" ||        # also covers renamed back-office dirs like /adminXYZ
		req.url ~ "^/api" ||          # webservice
		req.url ~ "^/module/" ||      # module controllers (payments, ajax…)
		req.url ~ "^/order" ||
		req.url ~ "^/order-confirmation" ||
		req.url ~ "^/identity" ||
		req.url ~ "^/my-account.php" ||
		req.url ~ "^/authentification" ||
		req.url ~ "^/commande" ||
		req.url ~ "^/confirmation-commande" ||
		req.url ~ "^/panier" ||
		req.url ~ "^/connexion" ||
		req.url ~ "^/deconnexion" ||
		req.url ~ "^/mon-compte" ||

		# --- Generic auth / account routes ---
		req.url ~ "^/login" ||
		req.url ~ "^/logout" ||
		req.url ~ "^/signin" ||
		req.url ~ "^/signup" ||
		req.url ~ "^/register" ||
		req.url ~ "^/register.php" ||
		req.url ~ "^/lost-password" ||

		# --- bbPress (legacy) ---
		req.url ~ "^/bb-admin" ||
		req.url ~ "^/bb-login.php" ||
		req.url ~ "^/bb-reset-password.php" ||

		# --- Misc / monitoring ---
		req.url ~ "^/addons" ||
		req.url ~ "^/control.php" ||
		req.url ~ "^/server-status" ||
		req.url ~ "^/stats"
	) {
		set req.http.X-Cacheable = "NO:Logged in/Got Sessions";
		if (req.http.X-Requested-With == "XMLHttpRequest") {
			set req.http.X-Cacheable = "NO:Ajax";
		}
		return (pass);
	}

	# Anonymous page view: drop any remaining cookie so the page is shared
	# in the cache. (Responses that set cookies or send private/no-cache
	# headers are still excluded by vcl_backend_response + builtin VCL.)
	unset req.http.Cookie;
	return (hash);
}

sub vcl_backend_response {

	# Stamp URL & Host on the stored object: bans reference these headers
	# (lurker-friendly invalidation); they are removed from responses in
	# vcl_deliver.
	set beresp.http.x-url = bereq.url;
	set beresp.http.x-host = bereq.http.host;

	# Grace: serve a stale copy for up to 4h while a fresh one is fetched
	# in the background (users never wait for the backend).
	# https://varnish-cache.org/docs/6.0/users-guide/vcl-grace.html
	set beresp.grace = 4h;

	# Cache only sane statuses. 404 is deliberately NOT cached: some
	# plugins (WP Rocket async CSS, Elementor…) return temporary 404s
	# while generating assets, and caching those would break pages until
	# the next purge.
	if (beresp.status != 200 && beresp.status != 410 && beresp.status != 301 && beresp.status != 302 && beresp.status != 304 && beresp.status != 307) {
		set beresp.http.X-Cacheable = "NO:UNCACHEABLE";
		set beresp.ttl = 10s;
		set beresp.uncacheable = true;
	} else {

		# No Cache-Control from the backend: default every page to 1h.
		if (!beresp.http.Cache-Control) {
			set beresp.ttl = 1h;
			set beresp.http.X-Cacheable = "YES:Forced";
		}

		# Static files: cache 1 day and drop any Set-Cookie.
		if (bereq.http.X-Static-File == "true") {
			unset beresp.http.Set-Cookie;
			set beresp.http.X-Cacheable = "YES:Forced";
			set beresp.ttl = 1d;
		}

		# Some plugins set harmless cookies that would otherwise block
		# caching: Wordfence bot verification, and Polylang's language
		# memory (pll_language) — each language has its own URLs, so that
		# cookie is redundant for cache purposes. Note: this drops ALL
		# Set-Cookie headers of the response when one of these matches;
		# session-critical URLs are already excluded in vcl_recv.
		if (beresp.http.Set-Cookie ~ "wfvt_|wordfence_verifiedHuman|pll_language") {
			unset beresp.http.Set-Cookie;
		}

		if (beresp.http.Set-Cookie) {
			set beresp.http.X-Cacheable = "NO:Got Cookies";
		} elseif (beresp.http.Cache-Control ~ "private") {

			if (beresp.http.Cache-Control ~ "public" && bereq.http.X-Static-File == "true") {
				set beresp.http.Cache-Control = regsub(beresp.http.Cache-Control, "private,", "");
				set beresp.http.Cache-Control = regsub(beresp.http.Cache-Control, "private", "");
				set beresp.http.X-Cacheable = "YES";
			} elseif (bereq.http.X-Static-File == "true" && (beresp.http.Content-type ~ "image\/webp" || beresp.http.Content-type ~ "image\/avif")) {
				set beresp.http.Cache-Control = regsub(beresp.http.Cache-Control, "private,", "");
				set beresp.http.Cache-Control = regsub(beresp.http.Cache-Control, "private", "");
				set beresp.http.X-Cacheable = "YES";
			} else {
				set beresp.http.X-Cacheable = "NO:Cache-Control=private";
			}
		}

		# No explicit return: the builtin vcl_backend_response still runs
		# and correctly refuses to cache Set-Cookie / no-store / no-cache /
		# private responses (that is what keeps logged-in and PrestaShop
		# session pages out of the cache).
	}

}

sub vcl_deliver {

	# --- Debug headers -------------------------------------------------
	if (req.http.X-Cacheable) {
		set resp.http.X-Cacheable = req.http.X-Cacheable;
	} elseif (obj.uncacheable) {
		if (!resp.http.X-Cacheable) {
			set resp.http.X-Cacheable = "NO:UNCACHEABLE";
		}
	} elseif (!resp.http.X-Cacheable) {
		set resp.http.X-Cacheable = "YES";
	}

	if (obj.hits > 0) {
		set resp.http.X-Cache = "HIT";
	} else {
		set resp.http.X-Cache = "MISS";
	}
	set resp.http.X-Cache-Hits = obj.hits;
	# --- End debug headers ---------------------------------------------

	# Internal headers only used for bans: never expose them to clients.
	unset resp.http.x-url;
	unset resp.http.x-host;

	# Trim response fingerprinting.
	unset resp.http.X-Powered-By;
}
