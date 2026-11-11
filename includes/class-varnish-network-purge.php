<?php
/**
 * Main class for the Varnish Network Purge plugin.
 *
 * @package VarnishNetworkPurge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Varnish_Network_Purge {

	/** Network option holding the secret token. */
	const OPTION_TOKEN = 'varnish_purge_token';

	/** URL parameter that triggers the purge. */
	const QUERY_VAR = 'varnish_purge';

	/** Prefix of the throttle network transient (suffixed with the target). */
	const THROTTLE_KEY = 'vnp_last_run_';

	/** Prefix of the admin-notice network transient (suffixed with the user ID). */
	const NOTICE_KEY = 'vnp_notice_';

	/** Text domain used for translations. */
	const TEXTDOMAIN = 'varnish-network-purge';

	/** Minimum delay (seconds) between two purges of the same target via URL. */
	const THROTTLE_SEC = 10;

	/** Number of parallel PURGE requests per curl_multi batch. */
	const BATCH_SIZE = 50;

	/** WP-Cron hook of the cache warm-up event. */
	const WARMUP_HOOK = 'vnp_warmup_event';

	/** Prefix of the pending warm-up work transient (suffixed with the host hash). */
	const WARMUP_STATE_KEY = 'vnp_warmup_';

	/** Delay (seconds) between a purge and its warm-up event. */
	const WARMUP_DELAY_SEC = 5;

	/** Default maximum number of URLs warmed per host. */
	const WARMUP_DEFAULT_LIMIT = 600;

	/** Default number of parallel warm-up requests. */
	const WARMUP_CONCURRENCY = 4;

	/** Default pause (milliseconds) between two warm-up batches. */
	const WARMUP_PAUSE_MS = 250;

	/** @var Varnish_Network_Purge|null */
	private static $instance = null;

	/** @var string[] Exact URLs queued for purge at shutdown (deduplicated). */
	private $queue = array();

	/** @var string[] Hosts whose whole cache is queued for purge at shutdown. */
	private $queue_hosts = array();

	/** @var bool True while we are clearing local caches ourselves (loop guard). */
	private $syncing_local = false;

	/**
	 * Single entry point.
	 *
	 * @return Varnish_Network_Purge
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Load translations (French .mo shipped in /languages).
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		// Token-protected URL trigger (front-end, any network domain).
		add_action( 'init', array( $this, 'maybe_handle_url_purge' ) );

		// Automatic targeted purge when content changes.
		add_action( 'wp_after_insert_post', array( $this, 'queue_post_purge' ), 10, 4 );
		add_action( 'before_delete_post', array( $this, 'queue_deleted_post_purge' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'queue_term_purge' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'queue_term_purge' ), 10, 2 );

		// Site-wide changes (affect every page): purge the whole site.
		add_action( 'wp_update_nav_menu', array( $this, 'queue_full_site_purge' ) );
		add_action( 'customize_save_after', array( $this, 'queue_full_site_purge' ) );
		add_action( 'switch_theme', array( $this, 'queue_full_site_purge' ) );

		// Keep WP Fastest Cache and Varnish coherent: when WPFC is cleared
		// (admin bar button, its own hooks…), purge Varnish too. Otherwise
		// Varnish keeps serving HTML that references deleted minified
		// CSS/JS bundles, and pages break once those expire.
		add_action( 'wpfc_clear_all_cache', array( $this, 'on_wpfc_clear_all' ) );
		add_action( 'wpfc_delete_cache', array( $this, 'on_wpfc_clear_all' ) );
		add_action( 'wpfc_clear_post_cache_by_id', array( $this, 'on_wpfc_clear_post' ), 10, 2 );

		// Cache warm-up (WP-Cron event + settings-page button).
		add_action( self::WARMUP_HOOK, array( $this, 'run_warmup_event' ) );
		add_action( 'admin_post_vnp_warmup', array( $this, 'handle_warmup' ) );

		// Queued purges are flushed once, after everything has been saved.
		add_action( 'shutdown', array( $this, 'flush_queue' ) );

		// Admin screens.
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
		add_action( 'admin_menu', array( $this, 'add_site_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar' ), 100 );

		// Action handlers (forms + admin-bar links).
		add_action( 'admin_post_vnp_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'admin_post_vnp_purge_site', array( $this, 'handle_purge_site' ) );
		add_action( 'admin_post_vnp_purge_current', array( $this, 'handle_purge_current' ) );
		add_action( 'admin_post_vnp_purge_page', array( $this, 'handle_purge_page' ) );
		add_action( 'admin_post_vnp_regen_token', array( $this, 'handle_regen_token' ) );

		// Notices shown after a purge triggered from the admin.
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'maybe_show_notice' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			self::TEXTDOMAIN,
			false,
			dirname( plugin_basename( VNP_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/* --------------------------------------------------------------------- */
	/* Core: sending PURGE requests                                          */
	/*                                                                       */
	/* The VCL in front implements the Proxy-Cache-Purge convention:         */
	/*   - PURGE without header        -> exact-URL purge (query stripped)   */
	/*   - PURGE + X-Purge-Method:regex-> ban obj.x-url ~ url && x-host ~ host */
	/* So one "PURGE / + regex" request wipes a whole domain (pages,         */
	/* pagination, feeds, static assets), while content saves use precise    */
	/* exact-URL purges to keep the rest of the cache warm.                  */
	/* NB: the VCL answers "200 Purged" whether an object matched or not.    */
	/* --------------------------------------------------------------------- */

	/**
	 * Get (or generate) the secret token shared across the network.
	 *
	 * @return string
	 */
	private function get_token() {
		$token = get_site_option( self::OPTION_TOKEN );
		if ( ! $token ) {
			$token = wp_generate_password( 40, false, false );
			update_site_option( self::OPTION_TOKEN, $token );
		}
		return $token;
	}

	/**
	 * List of unique network domains (subdirectory sub-sites share the
	 * domain of their host site and are covered by its host-wide purge).
	 *
	 * @return string[]
	 */
	private function get_network_hosts() {
		$hosts = array();

		if ( ! function_exists( 'get_sites' ) || ! is_multisite() ) {
			$host = $this->current_host();
			return $host ? array( $host ) : array();
		}

		$sites = get_sites( array(
			'number'   => 0, // 0 = no limit (all sites).
			'deleted'  => 0,
			'archived' => 0,
			'spam'     => 0,
		) );

		foreach ( $sites as $site ) {
			$host = trim( $site->domain );
			if ( '' !== $host ) {
				$hosts[ $host ] = true;
			}
		}

		return array_keys( $hosts );
	}

	/**
	 * Current site's host (for display / labels / purges).
	 *
	 * @return string
	 */
	private function current_host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host ? $host : '';
	}

	/**
	 * Capability required for global actions (full purge, token management):
	 * super admin on multisite, administrator on a single site (where
	 * "manage_network" is granted to nobody).
	 *
	 * @return string
	 */
	private function manage_cap() {
		return is_multisite() ? 'manage_network' : 'manage_options';
	}

	/**
	 * Purge every domain of the network (one wildcard request per domain),
	 * clearing local page caches first so Varnish cannot re-cache stale HTML.
	 *
	 * @return array[]
	 */
	private function purge_all() {
		if ( function_exists( 'get_sites' ) && is_multisite() ) {
			$sites = get_sites( array(
				'number'   => 0,
				'deleted'  => 0,
				'archived' => 0,
				'spam'     => 0,
			) );
			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site->blog_id );
				$this->clear_local_site_cache();
				restore_current_blog();
			}
		} else {
			$this->clear_local_site_cache();
		}

		return $this->purge_hosts( $this->get_network_hosts() );
	}

	/* --------------------------------------------------------------------- */
	/* Local page caches (WP Fastest Cache): keep them in sync with Varnish  */
	/* --------------------------------------------------------------------- */

	/**
	 * Clear the WP Fastest Cache of the CURRENT site (pages + minified
	 * CSS/JS bundles). Called before purging Varnish: local caches first,
	 * then Varnish, so the next request rebuilds coherent HTML + assets.
	 */
	private function clear_local_site_cache() {
		if ( ! function_exists( 'wpfc_clear_all_cache' ) ) {
			return;
		}
		$this->syncing_local = true;
		wpfc_clear_all_cache( true );
		$this->syncing_local = false;
	}

	/**
	 * Clear the WP Fastest Cache of every site whose mapped domain is $host.
	 *
	 * @param string $host Domain, e.g. "example.com".
	 */
	private function clear_local_host_cache( $host ) {
		if ( ! function_exists( 'get_sites' ) || ! is_multisite() ) {
			$this->clear_local_site_cache();
			return;
		}

		$sites = get_sites( array(
			'domain'   => $host,
			'number'   => 0,
			'deleted'  => 0,
			'archived' => 0,
			'spam'     => 0,
		) );
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			$this->clear_local_site_cache();
			restore_current_blog();
		}
	}

	/**
	 * WPFC was cleared by someone else (admin bar, cron, another plugin):
	 * purge Varnish for the current site so both layers stay coherent.
	 */
	public function on_wpfc_clear_all() {
		if ( $this->syncing_local ) {
			return;
		}
		$this->queue_host_wipe();
	}

	/**
	 * WPFC cleared the cache of one post: purge its URLs in Varnish too.
	 * WPFC fires this action as do_action(hook, false, $post_id).
	 *
	 * @param mixed $unused  First argument passed by WPFC (always false).
	 * @param int   $post_id Post ID.
	 */
	public function on_wpfc_clear_post( $unused = false, $post_id = 0 ) {
		if ( $this->syncing_local ) {
			return;
		}
		$post = $post_id ? get_post( $post_id ) : null;
		if ( $post instanceof WP_Post ) {
			$this->queue_urls( $this->urls_for_post( $post ) );
		}
	}

	/**
	 * Wipe the whole cache of each given domain: one PURGE request on "/"
	 * with "X-Purge-Method: regex", which the VCL turns into a ban matching
	 * every object of the host (pages, pagination, feeds, static assets).
	 *
	 * @param string[] $hosts Domains, e.g. [ "example.com" ].
	 * @return array[] List of results { url, code, ok, err }.
	 */
	private function purge_hosts( array $hosts ) {
		$requests = array();
		foreach ( $hosts as $host ) {
			$host = trim( $host );
			if ( '' !== $host ) {
				$requests[] = array(
					'url'   => 'https://' . $host . '/',
					'regex' => true,
				);
			}
		}
		$results = $this->send_purge_requests( $requests );
		$this->schedule_warmup_hosts( $hosts );
		return $results;
	}

	/**
	 * Purge a list of exact URLs (one PURGE request per URL).
	 *
	 * @param string[] $urls Exact absolute URLs.
	 * @return array[] List of results { url, code, ok, err }.
	 */
	private function purge_urls( array $urls ) {
		$requests = array();
		foreach ( $urls as $url ) {
			$requests[] = array(
				'url'   => $url,
				'regex' => false,
			);
		}
		$results = $this->send_purge_requests( $requests );
		$this->schedule_warmup_urls( $urls );
		return $results;
	}

	/**
	 * Build CURLOPT_RESOLVE entries pinning the request host to the origin
	 * address configured through VNP_ORIGIN_IP and/or the vnp_origin_ip filter.
	 * The URL (and therefore TLS verification and the Host header) is unchanged.
	 *
	 * @param string $url Purge URL.
	 * @return string[] CURLOPT_RESOLVE entries, empty when no valid address is set.
	 */
	private function get_resolve_entries( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return array();
		}

		$ip = defined( 'VNP_ORIGIN_IP' ) ? (string) VNP_ORIGIN_IP : '';
		$ip = trim( (string) apply_filters( 'vnp_origin_ip', $ip, $host ) );
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array();
		}

		if ( false !== strpos( $ip, ':' ) ) {
			$ip = '[' . $ip . ']';
		}

		return array(
			$host . ':443:' . $ip,
			$host . ':80:' . $ip,
		);
	}

	/**
	 * Send PURGE requests in parallel batches via curl_multi.
	 *
	 * @param array[] $requests List of { url: string, regex: bool }.
	 * @return array[] List of results { url, code, ok, err }.
	 */
	private function send_purge_requests( array $requests ) {
		$results = array();

		if ( empty( $requests ) || ! function_exists( 'curl_multi_init' ) ) {
			return $results;
		}

		foreach ( array_chunk( $requests, self::BATCH_SIZE ) as $batch ) {
			$mh      = curl_multi_init();
			$handles = array();

			foreach ( $batch as $request ) {
				$ch      = curl_init( $request['url'] );
				$headers = array();
				if ( ! empty( $request['regex'] ) ) {
					$headers[] = 'X-Purge-Method: regex';
				}
				$resolve = $this->get_resolve_entries( $request['url'] );
				if ( ! empty( $resolve ) ) {
					curl_setopt( $ch, CURLOPT_RESOLVE, $resolve );
				}
				curl_setopt_array( $ch, array(
					CURLOPT_CUSTOMREQUEST  => 'PURGE',
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 10,
					CURLOPT_CONNECTTIMEOUT => 5,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_USERAGENT      => 'Varnish-Network-Purge/' . VNP_VERSION,
				) );
				curl_multi_add_handle( $mh, $ch );
				$handles[] = array( 'ch' => $ch, 'url' => $request['url'] );
			}

			$running = null;
			do {
				curl_multi_exec( $mh, $running );
				if ( $running > 0 ) {
					curl_multi_select( $mh, 1.0 );
				}
			} while ( $running > 0 );

			foreach ( $handles as $h ) {
				$code      = (int) curl_getinfo( $h['ch'], CURLINFO_HTTP_CODE );
				$err       = curl_error( $h['ch'] );
				$results[] = array(
					'url'  => $h['url'],
					'code' => $code,
					'ok'   => ( '' === $err && $code >= 200 && $code < 400 ),
					'err'  => $err,
				);
				curl_multi_remove_handle( $mh, $h['ch'] );
				curl_close( $h['ch'] );
			}

			curl_multi_close( $mh );
		}

		return $results;
	}

	/* --------------------------------------------------------------------- */
	/* Automatic purge on content change                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Queue the URLs affected by a post save (create, update, trash,
	 * untrash, status change). Fired on wp_after_insert_post, i.e. once
	 * terms and meta have been saved too.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post after the save.
	 * @param bool         $update      Whether this is an update.
	 * @param WP_Post|null $post_before Post before the save (null on creation).
	 */
	public function queue_post_purge( $post_id, $post, $update, $post_before ) {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$was_public = ( $post_before instanceof WP_Post ) && 'publish' === $post_before->post_status;
		$is_public  = 'publish' === $post->post_status;
		if ( ! $was_public && ! $is_public ) {
			return;
		}

		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}

		$this->queue_urls( $this->urls_for_post( $post ) );

		// Old permalink too, in case the slug (or parent) changed.
		if ( $was_public ) {
			$old_link = get_permalink( $post_before );
			if ( $old_link ) {
				$this->queue_urls( array( $old_link ) );
			}
		}

		// Drop the WPFC copy of this post as well: with some settings WPFC
		// only watches the "post" type, leaving stale page HTML behind.
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			$this->syncing_local = true;
			wpfc_clear_post_cache_by_id( $post->ID );
			$this->syncing_local = false;
		}
	}

	/**
	 * Queue the URLs of a post about to be permanently deleted, while its
	 * permalink can still be computed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function queue_deleted_post_purge( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || wp_is_post_revision( $post ) ) {
			return;
		}

		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}

		$this->queue_urls( $this->urls_for_post( $post ) );
	}

	/**
	 * Queue a term archive when the term is edited or about to be deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy (unused; pre_delete_term passes it as 2nd arg).
	 */
	public function queue_term_purge( $term_id, $tt_id = 0, $taxonomy = '' ) {
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$urls = array( home_url( '/' ) );
		$link = get_term_link( $term );
		if ( ! is_wp_error( $link ) ) {
			$urls[] = $link;
		}
		$this->queue_urls( $urls );
	}

	/**
	 * Site-wide change (menus, customizer, theme switch — affects every
	 * page): clear the local page cache and queue a Varnish wipe of the
	 * current site.
	 */
	public function queue_full_site_purge() {
		$this->clear_local_site_cache();
		$this->queue_host_wipe();
	}

	/**
	 * Queue a Varnish wipe of the current site's host (no local clearing).
	 */
	private function queue_host_wipe() {
		$host = $this->current_host();
		if ( $host ) {
			$this->queue_hosts[ $host ] = true;
		}
	}

	/**
	 * URLs affected by a change to $post: its permalink, the home page,
	 * its post type archive and its term archives.
	 *
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private function urls_for_post( $post ) {
		$urls = array( home_url( '/' ) );

		$link = get_permalink( $post );
		if ( $link ) {
			$urls[] = $link;
		}

		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}

		foreach ( get_object_taxonomies( $post ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) ) {
					$urls[] = $term_link;
				}
			}
		}

		/**
		 * Filter the URLs purged when a post changes.
		 *
		 * @param string[] $urls Absolute URLs.
		 * @param WP_Post  $post The post being saved.
		 */
		return apply_filters( 'vnp_post_urls', $urls, $post );
	}

	/**
	 * Add exact URLs to the shutdown queue (deduplicated).
	 *
	 * @param string[] $urls Absolute URLs.
	 */
	private function queue_urls( array $urls ) {
		foreach ( $urls as $url ) {
			if ( is_string( $url ) && '' !== $url ) {
				$this->queue[ $url ] = true;
			}
		}
	}

	/**
	 * Send the queued purges once, at the end of the request. Exact URLs
	 * already covered by a queued host-wide purge are skipped.
	 */
	public function flush_queue() {
		if ( empty( $this->queue ) && empty( $this->queue_hosts ) ) {
			return;
		}

		$requests = array();
		foreach ( array_keys( $this->queue_hosts ) as $host ) {
			$requests[] = array(
				'url'   => 'https://' . $host . '/',
				'regex' => true,
			);
		}
		foreach ( array_keys( $this->queue ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host && isset( $this->queue_hosts[ $host ] ) ) {
				continue;
			}
			$requests[] = array(
				'url'   => $url,
				'regex' => false,
			);
		}

		$purged_urls  = array();
		foreach ( $requests as $request ) {
			if ( empty( $request['regex'] ) ) {
				$purged_urls[] = $request['url'];
			}
		}
		$wiped_hosts = array_keys( $this->queue_hosts );

		$this->queue       = array();
		$this->queue_hosts = array();
		$this->send_purge_requests( $requests );
		$this->schedule_warmup_hosts( $wiped_hosts );
		$this->schedule_warmup_urls( $purged_urls );
	}

	/* --------------------------------------------------------------------- */
	/* Cache warm-up                                                         */
	/*                                                                       */
	/* A purge empties Varnish, so the next visitor pays the full WordPress  */
	/* render. When enabled (VNP_WARMUP / vnp_warmup_enabled) the plugin     */
	/* schedules a one-off WP-Cron event that requests the purged URLs (or,  */
	/* after a whole-site purge, the URLs of the XML sitemap) to refill the  */
	/* cache, outside the administrator's request. A failure here never      */
	/* breaks anything and never triggers another purge.                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Whether the automatic warm-up after purges is enabled.
	 *
	 * @return bool
	 */
	private function warmup_enabled() {
		$enabled = defined( 'VNP_WARMUP' ) ? (bool) VNP_WARMUP : false;
		return (bool) apply_filters( 'vnp_warmup_enabled', $enabled );
	}

	/**
	 * Transient key holding the pending warm-up work of a host.
	 *
	 * @param string $host Domain.
	 * @return string
	 */
	private function warmup_state_key( $host ) {
		return self::WARMUP_STATE_KEY . md5( strtolower( $host ) );
	}

	/**
	 * Schedule a warm-up of $host. Pending work of the same host is merged
	 * into one single event (no second event while one is already planned).
	 *
	 * @param string        $host Domain of the site.
	 * @param string[]|null $urls Exact URLs to warm, or null to warm the sitemap URLs.
	 * @return bool True when work is pending (new or already planned).
	 */
	private function schedule_warmup( $host, $urls = null ) {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return false;
		}

		// WP-Cron is per site: schedule in the blog that owns the host.
		$switched = false;
		if ( is_multisite() && $host !== strtolower( $this->current_host() ) ) {
			$sites = get_sites( array( 'domain' => $host, 'number' => 1, 'deleted' => 0, 'archived' => 0, 'spam' => 0 ) );
			if ( empty( $sites ) ) {
				return false;
			}
			switch_to_blog( (int) $sites[0]->blog_id );
			$switched = true;
		}

		$key   = $this->warmup_state_key( $host );
		$state = get_site_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array( 'full' => false, 'urls' => array() );
		}
		if ( null === $urls ) {
			$state['full'] = true;
		} else {
			foreach ( $urls as $url ) {
				if ( is_string( $url ) && '' !== $url ) {
					$state['urls'][ $url ] = true;
				}
			}
			if ( count( $state['urls'] ) > 1000 ) {
				$state['urls'] = array_slice( $state['urls'], 0, 1000, true );
			}
		}
		set_site_transient( $key, $state, HOUR_IN_SECONDS );

		$args   = array( $host );
		$result = true;
		if ( ! wp_next_scheduled( self::WARMUP_HOOK, $args ) ) {
			$result = false !== wp_schedule_single_event( time() + self::WARMUP_DELAY_SEC, self::WARMUP_HOOK, $args );
		}

		if ( $switched ) {
			restore_current_blog();
		}
		return $result;
	}

	/**
	 * Schedule warm-ups for exact URLs that were just purged (grouped by host).
	 *
	 * @param string[] $urls Absolute URLs.
	 */
	private function schedule_warmup_urls( array $urls ) {
		if ( ! $this->warmup_enabled() ) {
			return;
		}
		$by_host = array();
		foreach ( $urls as $url ) {
			$host = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
			if ( is_string( $host ) && '' !== $host ) {
				$by_host[ strtolower( $host ) ][] = $url;
			}
		}
		foreach ( $by_host as $host => $host_urls ) {
			$this->schedule_warmup( $host, $host_urls );
		}
	}

	/**
	 * Schedule sitemap warm-ups for hosts that were just wiped.
	 *
	 * @param string[] $hosts Domains.
	 */
	private function schedule_warmup_hosts( array $hosts ) {
		if ( ! $this->warmup_enabled() ) {
			return;
		}
		foreach ( $hosts as $host ) {
			$this->schedule_warmup( $host, null );
		}
	}

	/**
	 * WP-Cron callback: warm the pending URLs of a host. Never throws.
	 *
	 * @param string $host Domain.
	 */
	public function run_warmup_event( $host ) {
		try {
			$host  = strtolower( trim( (string) $host ) );
			$key   = $this->warmup_state_key( $host );
			$state = get_site_transient( $key );
			delete_site_transient( $key );
			if ( '' === $host || ! is_array( $state ) ) {
				return;
			}

			$urls = array_keys( isset( $state['urls'] ) && is_array( $state['urls'] ) ? $state['urls'] : array() );
			if ( ! empty( $state['full'] ) ) {
				$urls = array_merge( $this->collect_sitemap_urls( $host ), $urls );
			}
			$summary = $this->warm_urls( $host, $urls );

			error_log( sprintf(
				'[varnish-network-purge] warmup host=%s urls=%d ok=%d failed=%d duration_ms=%d',
				$host,
				$summary['total'],
				$summary['ok'],
				$summary['failed'],
				$summary['duration_ms']
			) );
		} catch ( \Throwable $e ) {
			error_log( '[varnish-network-purge] warmup failed host=' . ( is_string( $host ) ? $host : '' ) . ' error=' . $e->getMessage() );
		}
	}

	/**
	 * Per-host URL limit.
	 *
	 * @param string   $host     Domain.
	 * @param int|null $override Explicit limit (WP-CLI), or null.
	 * @return int
	 */
	private function warmup_limit( $host, $override = null ) {
		$limit = null !== $override ? (int) $override : self::WARMUP_DEFAULT_LIMIT;
		$limit = (int) apply_filters( 'vnp_warmup_limit', $limit, $host );
		return max( 0, $limit );
	}

	/**
	 * Keep only valid, same-host, deduplicated http(s) URLs (fragments dropped).
	 *
	 * @param string[] $urls Candidate URLs.
	 * @param string   $host Domain.
	 * @return string[]
	 */
	private function filter_same_host_urls( array $urls, $host ) {
		$out = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$url = trim( $url );
			$pos = strpos( $url, '#' );
			if ( false !== $pos ) {
				$url = substr( $url, 0, $pos );
			}
			$parts = wp_parse_url( $url );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] )
				|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
				|| strtolower( $parts['host'] ) !== $host ) {
				continue;
			}
			$out[ $url ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Read the sitemap of $host and return the page URLs it lists: tries
	 * /sitemap_index.xml (Rank Math, Yoast) then /wp-sitemap.xml (WordPress),
	 * follows one level of sub-sitemaps, stops at the per-host limit. Returns
	 * an array without the home page when no sitemap is usable.
	 *
	 * @param string   $host  Domain.
	 * @param int|null $limit Explicit limit, or null for the filtered default.
	 * @return string[]
	 */
	private function collect_sitemap_urls( $host, $limit = null ) {
		$limit = $this->warmup_limit( $host, $limit );
		$base  = 'https://' . $host;
		$urls  = array( $base . '/' );

		foreach ( array( '/sitemap_index.xml', '/wp-sitemap.xml' ) as $path ) {
			$root = $this->fetch_sitemap( $base . $path );
			if ( null === $root ) {
				continue;
			}

			$locs = $this->sitemap_locs( $root );
			if ( 'sitemapindex' === $root->getName() ) {
				$children = $this->filter_same_host_urls( $locs, $host );
				$children = array_slice( $children, 0, 50 );
				$locs     = array();
				foreach ( $children as $child ) {
					if ( count( $locs ) >= $limit ) {
						break;
					}
					$sub = $this->fetch_sitemap( $child );
					if ( null !== $sub && 'urlset' === $sub->getName() ) {
						$locs = array_merge( $locs, $this->sitemap_locs( $sub ) );
					}
					$this->warmup_pause();
				}
			}

			$locs = $this->filter_same_host_urls( $locs, $host );
			if ( ! empty( $locs ) ) {
				$urls = array_merge( $urls, $locs );
				break;
			}
		}

		return $urls;
	}

	/**
	 * Fetch and parse one XML sitemap.
	 *
	 * @param string $url Sitemap URL.
	 * @return SimpleXMLElement|null Null on non-200, network error or invalid XML.
	 */
	private function fetch_sitemap( $url ) {
		$res = $this->http_get_batch( array( $url ), true, 15 );
		if ( empty( $res ) || 200 !== $res[0]['code'] || '' !== $res[0]['err'] || '' === $res[0]['body'] ) {
			return null;
		}

		$body = $res[0]['body'];
		if ( false !== stripos( $body, '<!DOCTYPE' ) || false !== stripos( $body, '<!ENTITY' ) ) {
			return null; // Never parse DTDs or entities from a remote document.
		}
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $xml instanceof SimpleXMLElement || ! in_array( $xml->getName(), array( 'sitemapindex', 'urlset' ), true ) ) {
			return null;
		}
		return $xml;
	}

	/**
	 * Every page / sub-sitemap <loc> of a sitemap document (namespace-agnostic).
	 * Only <loc> that are direct children of <url> or <sitemap> are kept, so
	 * <image:loc>, <video:*> and <xhtml:link> entries are ignored.
	 *
	 * @param SimpleXMLElement $xml Parsed sitemap.
	 * @return string[]
	 */
	private function sitemap_locs( SimpleXMLElement $xml ) {
		$nodes = $xml->xpath( '//*[local-name()="url" or local-name()="sitemap"]/*[local-name()="loc"]' );
		$locs  = array();
		if ( is_array( $nodes ) ) {
			foreach ( $nodes as $node ) {
				$locs[] = trim( (string) $node );
			}
		}
		return $locs;
	}

	/**
	 * Short pause between batches (anti-burst protections).
	 */
	private function warmup_pause() {
		$ms = (int) apply_filters( 'vnp_warmup_pause_ms', self::WARMUP_PAUSE_MS );
		if ( $ms > 0 ) {
			usleep( min( $ms, 5000 ) * 1000 );
		}
	}

	/**
	 * Request URLs to fill the cache: GET, no cookie, no redirect followed,
	 * small curl_multi batches with a pause in between.
	 *
	 * @param string   $host  Domain; only URLs of this host are requested.
	 * @param string[] $urls  Candidate URLs.
	 * @param int|null $limit Explicit limit, or null for the filtered default.
	 * @return array { total, ok, failed, codes: array<string,int>, duration_ms }
	 */
	private function warm_urls( $host, array $urls, $limit = null ) {
		$started = microtime( true );
		$summary = array( 'total' => 0, 'ok' => 0, 'failed' => 0, 'codes' => array(), 'duration_ms' => 0 );

		$urls = apply_filters( 'vnp_warmup_urls', array_values( array_unique( $urls ) ), $host );
		$urls = is_array( $urls ) ? $this->filter_same_host_urls( $urls, $host ) : array();
		$urls = array_slice( $urls, 0, $this->warmup_limit( $host, $limit ) );

		if ( ! empty( $urls ) && function_exists( 'curl_multi_init' ) ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$size = (int) apply_filters( 'vnp_warmup_concurrency', self::WARMUP_CONCURRENCY );
			$size = max( 1, min( 20, $size ) );

			foreach ( array_chunk( $urls, $size ) as $i => $batch ) {
				if ( $i > 0 ) {
					$this->warmup_pause();
				}
				foreach ( $this->http_get_batch( $batch, false, 20 ) as $r ) {
					$summary['total']++;
					$label = '' !== $r['err'] ? 'error' : (string) $r['code'];
					$summary['codes'][ $label ] = ( isset( $summary['codes'][ $label ] ) ? $summary['codes'][ $label ] : 0 ) + 1;
					if ( '' === $r['err'] && $r['code'] >= 200 && $r['code'] < 400 ) {
						$summary['ok']++;
					} else {
						$summary['failed']++;
					}
				}
			}
		}

		$summary['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		return $summary;
	}

	/**
	 * One curl_multi batch of GET requests (no cookie, redirects not
	 * followed), resolved through the origin address when VNP_ORIGIN_IP is set.
	 *
	 * @param string[] $urls      URLs.
	 * @param bool     $keep_body Keep the body (sitemaps, capped at 10 MB); otherwise discard it.
	 * @param int      $timeout   Timeout per request, seconds.
	 * @return array[] List of { url, code, err, body } in input order.
	 */
	private function http_get_batch( array $urls, $keep_body, $timeout ) {
		$out = array();
		if ( empty( $urls ) || ! function_exists( 'curl_multi_init' ) ) {
			return $out;
		}

		$mh      = curl_multi_init();
		$handles = array();
		$bodies  = array();

		foreach ( array_values( $urls ) as $i => $url ) {
			$ch = curl_init( $url );
			$bodies[ $i ] = '';
			$opts = array(
				CURLOPT_HTTPGET        => true,
				CURLOPT_TIMEOUT        => (int) $timeout,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_COOKIE         => '',
				CURLOPT_ENCODING       => '',
				CURLOPT_HTTPHEADER     => array( 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ),
				CURLOPT_USERAGENT      => 'Varnish-Network-Purge-Warmup/' . VNP_VERSION,
				CURLOPT_WRITEFUNCTION  => function ( $handle, $chunk ) use ( $keep_body, $i, &$bodies ) {
					if ( $keep_body ) {
						if ( strlen( $bodies[ $i ] ) + strlen( $chunk ) > 10 * 1024 * 1024 ) {
							return 0; // Abort: oversized document.
						}
						$bodies[ $i ] .= $chunk;
					}
					return strlen( $chunk );
				},
			);
			curl_setopt_array( $ch, $opts );
			$resolve = $this->get_resolve_entries( $url );
			if ( ! empty( $resolve ) ) {
				curl_setopt( $ch, CURLOPT_RESOLVE, $resolve );
			}
			curl_multi_add_handle( $mh, $ch );
			$handles[ $i ] = array( 'ch' => $ch, 'url' => $url );
		}

		$running = null;
		do {
			curl_multi_exec( $mh, $running );
			if ( $running > 0 ) {
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $running > 0 );

		foreach ( $handles as $i => $h ) {
			$out[] = array(
				'url'  => $h['url'],
				'code' => (int) curl_getinfo( $h['ch'], CURLINFO_HTTP_CODE ),
				'err'  => (string) curl_error( $h['ch'] ),
				'body' => $bodies[ $i ],
			);
			curl_multi_remove_handle( $mh, $h['ch'] );
			curl_close( $h['ch'] );
		}
		curl_multi_close( $mh );

		return $out;
	}

	/**
	 * Synchronous warm-up of a whole site from its sitemap (WP-CLI).
	 *
	 * @param string   $host  Domain.
	 * @param int|null $limit Explicit limit, or null for the filtered default.
	 * @return array Summary, see warm_urls().
	 */
	public function warmup_now( $host, $limit = null ) {
		return $this->warm_urls( $host, $this->collect_sitemap_urls( $host, $limit ), $limit );
	}

	/**
	 * Wipe the cache of a host synchronously (WP-Cron warm-up scheduling
	 * follows the usual rules). Used by WP-CLI.
	 *
	 * @param string $host Domain.
	 * @return array[] Purge results.
	 */
	public function purge_now( $host ) {
		$this->clear_local_host_cache( $host );
		return $this->purge_hosts( array( $host ) );
	}

	/**
	 * Hosts the current context may target (WP-CLI validation).
	 *
	 * @return string[]
	 */
	public function known_hosts() {
		return $this->get_network_hosts();
	}

	/**
	 * Settings-page button: schedule a warm-up of the current site.
	 */
	public function handle_warmup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'varnish-network-purge' ) );
		}
		check_admin_referer( 'vnp_warmup' );

		$scheduled = $this->schedule_warmup( $this->current_host(), null );
		$this->stash_message(
			$scheduled
				? __( 'Cache warm-up scheduled: the pages of the sitemap will be requested in the background within a few seconds.', 'varnish-network-purge' )
				: __( 'The cache warm-up could not be scheduled.', 'varnish-network-purge' ),
			$scheduled ? 'notice-success' : 'notice-error'
		);
		$this->redirect_back();
	}

	/* --------------------------------------------------------------------- */
	/* Trigger: token-protected URL                                          */
	/*                                                                       */
	/* Machine-facing endpoint (curl / cron): responses are kept in English  */
	/* on purpose so scripts get deterministic, locale-independent output.   */
	/* --------------------------------------------------------------------- */

	/**
	 * Network     : https://example.com/?varnish_purge=TOKEN
	 * Single site : https://example.com/?varnish_purge=TOKEN&host=sub.example.com
	 */
	public function maybe_handle_url_purge() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		$provided = (string) wp_unslash( $_GET[ self::QUERY_VAR ] );
		$token    = $this->get_token();

		if ( ! hash_equals( $token, $provided ) ) {
			status_header( 403 );
			echo "403 - Invalid token.";
			exit;
		}

		// Target: a specific site via &host=... , otherwise the whole network.
		$target = isset( $_GET['host'] ) ? trim( wp_unslash( $_GET['host'] ) ) : '';
		if ( '' !== $target && ! in_array( $target, $this->get_network_hosts(), true ) ) {
			status_header( 404 );
			echo "404 - Unknown domain in the network.";
			exit;
		}

		// Per-target throttle: prevents burst purges (cache stampede).
		$throttle_key = self::THROTTLE_KEY . md5( '' !== $target ? $target : 'all' );
		$last         = (int) get_site_transient( $throttle_key );
		if ( $last && ( time() - $last ) < self::THROTTLE_SEC ) {
			$wait = self::THROTTLE_SEC - ( time() - $last );
			status_header( 429 );
			echo "429 - A purge was just performed. Retry in {$wait}s.";
			exit;
		}
		set_site_transient( $throttle_key, time(), self::THROTTLE_SEC );

		if ( '' !== $target ) {
			$this->clear_local_host_cache( $target );
			$results = $this->purge_hosts( array( $target ) );
		} else {
			$results = $this->purge_all();
		}

		status_header( 200 );
		echo $this->format_results_text( $results );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Network admin: global purge + per-site purge + token                  */
	/* --------------------------------------------------------------------- */

	public function add_network_menu() {
		add_submenu_page(
			'settings.php',
			esc_html__( 'Varnish Cache', 'varnish-network-purge' ),
			esc_html__( 'Varnish Cache', 'varnish-network-purge' ),
			'manage_network',
			'varnish-purge',
			array( $this, 'render_network_page' )
		);
	}

	public function render_network_page() {
		$hosts = $this->get_network_hosts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Varnish Cache — Network', 'varnish-network-purge' ); ?></h1>

			<h2><?php esc_html_e( 'Global purge', 'varnish-network-purge' ); ?></h2>
			<p>
			<?php
			printf(
				wp_kses(
					/* translators: %d: number of network domains */
					__( 'Purge the Varnish cache of all <strong>%d network domains</strong> at once.', 'varnish-network-purge' ),
					array( 'strong' => array() )
				),
				count( $hosts )
			);
			?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vnp_purge_all" />
				<?php wp_nonce_field( 'vnp_purge_all' ); ?>
				<?php submit_button( __( 'Purge all Varnish cache', 'varnish-network-purge' ), 'primary large' ); ?>
			</form>

			<hr />

			<h2><?php printf( esc_html__( 'Per-site purge (%d)', 'varnish-network-purge' ), count( $hosts ) ); ?></h2>
			<table class="widefat striped" style="max-width:820px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Domain', 'varnish-network-purge' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Action', 'varnish-network-purge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $hosts as $host ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( 'https://' . $host . '/' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $host ); ?></a>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
									<input type="hidden" name="action" value="vnp_purge_site" />
									<input type="hidden" name="host" value="<?php echo esc_attr( $host ); ?>" />
									<?php wp_nonce_field( 'vnp_purge_site' ); ?>
									<?php submit_button( __( 'Purge', 'varnish-network-purge' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<hr />

			<?php $this->render_token_section( true ); ?>
		</div>
		<?php
	}

	/**
	 * URL trigger + token management section, shared by the network admin
	 * page (multisite) and the site settings page (single site).
	 *
	 * @param bool $with_host_example Show the "&host=DOMAIN" example
	 *                                (only meaningful on multisite).
	 */
	private function render_token_section( $with_host_example ) {
		$token     = $this->get_token();
		$purge_url = add_query_arg( self::QUERY_VAR, $token, network_home_url( '/' ) );
		?>
		<h2><?php esc_html_e( 'URL trigger', 'varnish-network-purge' ); ?></h2>
		<p>
			<?php esc_html_e( 'URL to call (curl, bookmark, scheduled task).', 'varnish-network-purge' ); ?>
			<strong><?php esc_html_e( 'Keep it secret.', 'varnish-network-purge' ); ?></strong>
		</p>
		<p>
			<input type="text" readonly onclick="this.select();" style="width:100%;max-width:820px;"
				value="<?php echo esc_attr( $purge_url ); ?>" />
		</p>
		<p><code>curl -s "<?php echo esc_html( $purge_url ); ?>"</code></p>

		<?php if ( $with_host_example ) : ?>
			<?php
			$hosts        = $this->get_network_hosts();
			$example_host = ! empty( $hosts ) ? reset( $hosts ) : 'example.com';
			?>
			<p>
			<?php
			echo wp_kses(
				__( 'To purge <strong>a single site</strong>, append <code>&amp;host=DOMAIN</code>:', 'varnish-network-purge' ),
				array(
					'strong' => array(),
					'code'   => array(),
				)
			);
			?>
			</p>
			<p><code>curl -s "<?php echo esc_html( add_query_arg( 'host', $example_host, $purge_url ) ); ?>"</code></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( 'Regenerate the token? Existing URLs will stop working.', 'varnish-network-purge' ) ); ?>');">
			<input type="hidden" name="action" value="vnp_regen_token" />
			<?php wp_nonce_field( 'vnp_regen_token' ); ?>
			<?php submit_button( __( 'Regenerate token', 'varnish-network-purge' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public function handle_purge_all() {
		if ( ! current_user_can( $this->manage_cap() ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'varnish-network-purge' ) );
		}
		check_admin_referer( 'vnp_purge_all' );

		$results = $this->purge_all();
		$this->stash_notice( $results, __( 'the whole network', 'varnish-network-purge' ) );
		$this->redirect_back();
	}

	public function handle_purge_site() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'varnish-network-purge' ) );
		}
		check_admin_referer( 'vnp_purge_site' );

		$host = isset( $_POST['host'] ) ? trim( wp_unslash( $_POST['host'] ) ) : '';
		if ( ! in_array( $host, $this->get_network_hosts(), true ) ) {
			wp_die( esc_html__( 'Unknown domain.', 'varnish-network-purge' ) );
		}

		$this->clear_local_host_cache( $host );
		$results = $this->purge_hosts( array( $host ) );
		$this->stash_notice( $results, $host );
		$this->redirect_back();
	}

	public function handle_regen_token() {
		if ( ! current_user_can( $this->manage_cap() ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'varnish-network-purge' ) );
		}
		check_admin_referer( 'vnp_regen_token' );

		update_site_option( self::OPTION_TOKEN, wp_generate_password( 40, false, false ) );
		$this->redirect_back();
	}

	/* --------------------------------------------------------------------- */
	/* Site admin: purge the current site from its settings                  */
	/* --------------------------------------------------------------------- */

	public function add_site_menu() {
		add_options_page(
			esc_html__( 'Varnish Cache', 'varnish-network-purge' ),
			esc_html__( 'Varnish Cache', 'varnish-network-purge' ),
			'manage_options',
			'varnish-purge',
			array( $this, 'render_site_page' )
		);
	}

	public function render_site_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Varnish Cache', 'varnish-network-purge' ); ?></h1>
			<p>
			<?php
			printf(
				/* translators: %s: current site host */
				esc_html__( "Purge this site's Varnish cache: %s", 'varnish-network-purge' ),
				'<strong>' . esc_html( $this->current_host() ) . '</strong>'
			);
			?>
			</p>
			<p><?php esc_html_e( 'Note: the cache of a page is purged automatically when its content is saved.', 'varnish-network-purge' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vnp_purge_current" />
				<?php wp_nonce_field( 'vnp_purge_current' ); ?>
				<?php submit_button( __( "Purge this site's cache", 'varnish-network-purge' ), 'primary large' ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Cache warm-up', 'varnish-network-purge' ); ?></h2>
			<p><?php esc_html_e( 'A purge empties the cache: the next visit to each page is slow. Warm-up requests the pages listed in the XML sitemap in the background to refill it.', 'varnish-network-purge' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vnp_warmup" />
				<?php wp_nonce_field( 'vnp_warmup' ); ?>
				<?php submit_button( __( 'Warm up the cache', 'varnish-network-purge' ), 'secondary' ); ?>
			</form>

			<?php if ( ! is_multisite() ) : ?>
				<hr />
				<?php $this->render_token_section( false ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_purge_current() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'varnish-network-purge' ) );
		}
		check_admin_referer( 'vnp_purge_current' );

		$this->clear_local_site_cache();
		$results = $this->purge_hosts( array( $this->current_host() ) );
		$this->stash_notice( $results, $this->current_host() );
		$this->redirect_back();
	}

	/**
	 * Purge a single page from the admin-bar link shown on the front end.
	 * The URL is rebuilt from its validated parts (network host + path)
	 * so only pages of the network can be purged.
	 */
	public function handle_purge_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'varnish-network-purge' ) );
		}
		check_admin_referer( 'vnp_purge_page' );

		$raw  = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
		$host = $raw ? wp_parse_url( $raw, PHP_URL_HOST ) : '';
		if ( '' === $raw || ! in_array( $host, $this->get_network_hosts(), true ) ) {
			wp_die( esc_html__( 'Invalid URL.', 'varnish-network-purge' ) );
		}

		$path = wp_parse_url( $raw, PHP_URL_PATH );
		$url  = 'https://' . $host . ( $path ? $path : '/' );

		// Drop the WPFC copy of the page too, when it maps to a post.
		$post_id = url_to_postid( $url );
		if ( $post_id && function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			$this->syncing_local = true;
			wpfc_clear_post_cache_by_id( $post_id );
			$this->syncing_local = false;
		}

		$results = $this->purge_urls( array( $url ) );
		$this->stash_notice( $results, $url );
		$this->redirect_back();
	}

	/* --------------------------------------------------------------------- */
	/* Admin bar                                                             */
	/* --------------------------------------------------------------------- */

	public function add_admin_bar( $wp_admin_bar ) {
		$can_site = current_user_can( 'manage_options' );
		$can_net  = current_user_can( 'manage_network' );

		if ( ! $can_site && ! $can_net ) {
			return;
		}

		$root_href = $can_net
			? network_admin_url( 'settings.php?page=varnish-purge' )
			: admin_url( 'options-general.php?page=varnish-purge' );

		$wp_admin_bar->add_node( array(
			'id'    => 'vnp',
			'title' => '<span class="ab-icon dashicons dashicons-update" style="font-family:dashicons;top:2px;"></span>' . esc_html__( 'Varnish Cache', 'varnish-network-purge' ),
			'href'  => $root_href,
			'meta'  => array( 'title' => esc_attr__( 'Varnish cache purge', 'varnish-network-purge' ) ),
		) );

		// Current front-end page (meaningless in wp-admin, which is not cached).
		if ( $can_site && ! is_admin() ) {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
			$page_url = 'https://' . $this->current_host() . ( $path ? $path : '/' );
			$wp_admin_bar->add_node( array(
				'parent' => 'vnp',
				'id'     => 'vnp-page',
				'title'  => esc_html__( 'Purge this page', 'varnish-network-purge' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=vnp_purge_page&url=' . rawurlencode( $page_url ) ), 'vnp_purge_page' ),
			) );
		}

		if ( $can_site ) {
			$wp_admin_bar->add_node( array(
				'parent' => 'vnp',
				'id'     => 'vnp-site',
				'title'  => esc_html__( 'Purge this site', 'varnish-network-purge' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=vnp_purge_current' ), 'vnp_purge_current' ),
			) );
		}

		if ( $can_net ) {
			$wp_admin_bar->add_node( array(
				'parent' => 'vnp',
				'id'     => 'vnp-all',
				'title'  => esc_html__( 'Purge the whole network', 'varnish-network-purge' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=vnp_purge_all' ), 'vnp_purge_all' ),
			) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Admin notices (after redirect)                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Store a plain message for the current user (shown as an admin notice).
	 *
	 * @param string $message Already translated message.
	 * @param string $class   Notice CSS class.
	 */
	private function stash_message( $message, $class = 'notice-success' ) {
		set_site_transient( self::NOTICE_KEY . get_current_user_id(), array(
			'message' => $message,
			'class'   => $class,
		), 60 );
	}

	/**
	 * Store a purge result for the current user.
	 *
	 * @param array[] $results     Purge results.
	 * @param string  $scope_label Target label (already translated when relevant).
	 */
	private function stash_notice( array $results, $scope_label ) {
		$ok = count( array_filter( $results, function ( $r ) { return $r['ok']; } ) );
		set_site_transient( self::NOTICE_KEY . get_current_user_id(), array(
			'ok'    => $ok,
			'total' => count( $results ),
			'scope' => $scope_label,
		), 60 );
	}

	public function maybe_show_notice() {
		$key    = self::NOTICE_KEY . get_current_user_id();
		$notice = get_site_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_site_transient( $key );

		if ( isset( $notice['message'] ) ) {
			printf(
				'<div class="notice %s is-dismissible"><p><strong>%s</strong></p></div>',
				esc_attr( $notice['class'] ),
				esc_html( $notice['message'] )
			);
			return;
		}

		$class   = ( $notice['total'] > 0 && $notice['ok'] === $notice['total'] ) ? 'notice-success' : 'notice-warning';
		$message = sprintf(
			/* translators: 1: target label, 2: successful requests, 3: total requests */
			__( 'Varnish cache purged — %1$s. %2$d / %3$d PURGE requests succeeded.', 'varnish-network-purge' ),
			$notice['scope'],
			(int) $notice['ok'],
			(int) $notice['total']
		);
		printf(
			'<div class="notice %s is-dismissible"><p><strong>%s</strong></p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Redirect back to the previous page (admin form or admin-bar link), with
	 * a safe fallback.
	 */
	private function redirect_back() {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = is_network_admin() ? network_admin_url( 'settings.php?page=varnish-purge' ) : admin_url();
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* --------------------------------------------------------------------- */

	private function format_results_text( array $results ) {
		$ok    = count( array_filter( $results, function ( $r ) { return $r['ok']; } ) );
		$total = count( $results );

		$lines   = array();
		$lines[] = "Varnish purge: {$ok}/{$total} requests OK";
		$lines[] = str_repeat( '-', 40 );
		foreach ( $results as $r ) {
			$status  = $r['ok'] ? 'OK ' : 'ERR';
			$detail  = $r['err'] ? ' (' . $r['err'] . ')' : '';
			$lines[] = sprintf( '[%s] %d  %s%s', $status, $r['code'], $r['url'], $detail );
		}
		return implode( "\n", $lines ) . "\n";
	}
}

/**
 * WP-CLI commands: `wp varnish-purge warmup|purge`.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class Varnish_Network_Purge_CLI {

		/**
		 * Warm the cache of a site from its XML sitemap (synchronous). Works
		 * even when VNP_WARMUP is false.
		 *
		 * ## OPTIONS
		 *
		 * [--host=<host>]
		 * : Domain to warm. Defaults to the current site.
		 *
		 * [--limit=<n>]
		 * : Maximum number of URLs (default: 600, filter vnp_warmup_limit).
		 *
		 * ## EXAMPLES
		 *
		 *     wp varnish-purge warmup --host=example.com --limit=100
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments.
		 */
		public function warmup( $args, $assoc_args ) {
			$plugin = Varnish_Network_Purge::instance();
			$host   = $this->resolve_host( $assoc_args );
			$limit  = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : null;

			$summary = $plugin->warmup_now( $host, $limit );
			if ( 0 === $summary['total'] ) {
				WP_CLI::warning( "No URL warmed for {$host} (no sitemap found, empty, or limit 0)." );
				return;
			}

			ksort( $summary['codes'] );
			$codes = array();
			foreach ( $summary['codes'] as $code => $count ) {
				$codes[] = "{$code}x{$count}";
			}
			WP_CLI::success( sprintf(
				'%s: %d URLs, %d ok, %d failed, codes: %s, %.1f s',
				$host,
				$summary['total'],
				$summary['ok'],
				$summary['failed'],
				implode( ' ', $codes ),
				$summary['duration_ms'] / 1000
			) );
		}

		/**
		 * Wipe the Varnish cache of a site.
		 *
		 * ## OPTIONS
		 *
		 * [--host=<host>]
		 * : Domain to purge. Defaults to the current site.
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments.
		 */
		public function purge( $args, $assoc_args ) {
			$host    = $this->resolve_host( $assoc_args );
			$results = Varnish_Network_Purge::instance()->purge_now( $host );
			$ok      = count( array_filter( $results, function ( $r ) { return $r['ok']; } ) );
			if ( $ok !== count( $results ) || 0 === $ok ) {
				WP_CLI::error( "Purge of {$host}: {$ok}/" . count( $results ) . ' requests OK.' );
			}
			WP_CLI::success( "Purge of {$host}: {$ok}/" . count( $results ) . ' requests OK.' );
		}

		/**
		 * @param array $assoc_args Associative arguments.
		 * @return string
		 */
		private function resolve_host( array $assoc_args ) {
			$plugin = Varnish_Network_Purge::instance();
			$host   = isset( $assoc_args['host'] ) ? strtolower( trim( (string) $assoc_args['host'] ) ) : '';
			if ( '' === $host ) {
				$hosts = $plugin->known_hosts();
				$host  = isset( $hosts[0] ) ? strtolower( $hosts[0] ) : '';
			}
			if ( '' === $host || ! in_array( $host, array_map( 'strtolower', $plugin->known_hosts() ), true ) ) {
				WP_CLI::error( 'Unknown or missing host.' );
			}
			return $host;
		}
	}

	WP_CLI::add_command( 'varnish-purge', 'Varnish_Network_Purge_CLI' );
}
