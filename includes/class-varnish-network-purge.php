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

	/**
	 * Number of parallel PURGE requests per curl_multi batch.
	 *
	 * The Varnish in front (Infomaniak) only purges EXACT URLs — "/*" or
	 * regex bans answer "200 Purged" but evict nothing — so a full purge
	 * means one request per URL. Batching keeps the socket count sane.
	 */
	const BATCH_SIZE = 50;

	/** Hard cap of enumerated URLs per site (safety net for huge sites). */
	const MAX_URLS_PER_SITE = 5000;

	/** @var Varnish_Network_Purge|null */
	private static $instance = null;

	/** @var string[] URLs queued for purge at shutdown (deduplicated). */
	private $queue = array();

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

		// Queued URLs are flushed once, after everything has been saved.
		add_action( 'shutdown', array( $this, 'flush_queue' ) );

		// Admin screens.
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
		add_action( 'admin_menu', array( $this, 'add_site_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar' ), 100 );

		// Action handlers (forms + admin-bar links).
		add_action( 'admin_post_vnp_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'admin_post_vnp_purge_site', array( $this, 'handle_purge_site' ) );
		add_action( 'admin_post_vnp_purge_current', array( $this, 'handle_purge_current' ) );
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
	/* Core: URL enumeration + sending PURGE requests                        */
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
	 * List of unique network domains (for the admin table and host targeting).
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
	 * Current site's host (for display / labels).
	 *
	 * @return string
	 */
	private function current_host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host ? $host : '';
	}

	/**
	 * Every purgeable URL of the CURRENT site: home page, all published
	 * content of public post types, term archives, post type archives.
	 *
	 * The Varnish in front only supports exact-URL purge (no wildcard, no
	 * regex ban), so a site-wide purge has to enumerate real URLs.
	 *
	 * @return string[]
	 */
	private function collect_site_urls() {
		$urls   = array();
		$urls[] = home_url( '/' );

		// Published content of every public post type.
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_ids   = get_posts( array(
			'post_type'              => array_values( $post_types ),
			'post_status'            => 'publish',
			'numberposts'            => self::MAX_URLS_PER_SITE,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		foreach ( $post_ids as $post_id ) {
			$link = get_permalink( $post_id );
			if ( $link ) {
				$urls[] = $link;
			}
		}

		// Post type archives (e.g. /blog/ for a CPT with has_archive).
		foreach ( $post_types as $post_type ) {
			$archive = get_post_type_archive_link( $post_type );
			if ( $archive ) {
				$urls[] = $archive;
			}
		}

		// Term archives of every public taxonomy.
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		if ( ! empty( $taxonomies ) ) {
			$terms = get_terms( array(
				'taxonomy'   => array_values( $taxonomies ),
				'hide_empty' => true,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		/**
		 * Filter the list of URLs purged for the current site.
		 *
		 * @param string[] $urls Absolute URLs.
		 */
		$urls = apply_filters( 'vnp_site_urls', $urls );

		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_URLS_PER_SITE );
	}

	/**
	 * Every purgeable URL of the sites whose mapped domain is $host
	 * (subdirectory sub-sites share the domain of their host site).
	 *
	 * @param string $host Domain, e.g. "port-hoedic.com".
	 * @return string[]
	 */
	private function collect_host_urls( $host ) {
		if ( ! function_exists( 'get_sites' ) || ! is_multisite() ) {
			return $this->collect_site_urls();
		}

		$urls  = array();
		$sites = get_sites( array(
			'domain'   => $host,
			'number'   => 0,
			'deleted'  => 0,
			'archived' => 0,
			'spam'     => 0,
		) );

		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			$urls = array_merge( $urls, $this->collect_site_urls() );
			restore_current_blog();
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Purge every site of the network, URL by URL.
	 *
	 * @return array[]
	 */
	private function purge_all() {
		if ( ! function_exists( 'get_sites' ) || ! is_multisite() ) {
			return $this->purge_urls( $this->collect_site_urls() );
		}

		$urls  = array();
		$sites = get_sites( array(
			'number'   => 0,
			'deleted'  => 0,
			'archived' => 0,
			'spam'     => 0,
		) );

		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			$urls = array_merge( $urls, $this->collect_site_urls() );
			restore_current_blog();
		}

		return $this->purge_urls( array_values( array_unique( $urls ) ) );
	}

	/**
	 * Send one PURGE request per URL, in parallel batches via curl_multi.
	 *
	 * @param string[] $urls Exact absolute URLs to purge.
	 * @return array[] List of results { url, code, ok, err }.
	 */
	private function purge_urls( array $urls ) {
		$results = array();

		if ( empty( $urls ) || ! function_exists( 'curl_multi_init' ) ) {
			return $results;
		}

		foreach ( array_chunk( $urls, self::BATCH_SIZE ) as $batch ) {
			$mh      = curl_multi_init();
			$handles = array();

			foreach ( $batch as $url ) {
				$ch = curl_init( $url );
				curl_setopt_array( $ch, array(
					CURLOPT_CUSTOMREQUEST  => 'PURGE',
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 10,
					CURLOPT_CONNECTTIMEOUT => 5,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_USERAGENT      => 'Varnish-Network-Purge/' . VNP_VERSION,
				) );
				curl_multi_add_handle( $mh, $ch );
				$handles[] = array( 'ch' => $ch, 'url' => $url );
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
	 * @param string $taxonomy Taxonomy (absent on pre_delete_term's signature order).
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
	 * Queue a purge of the whole current site (menus, customizer, theme
	 * switch — changes that affect every page).
	 */
	public function queue_full_site_purge() {
		$this->queue_urls( $this->collect_site_urls() );
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
	 * Add URLs to the shutdown queue (deduplicated).
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
	 * Send the queued PURGE requests once, at the end of the request.
	 */
	public function flush_queue() {
		if ( empty( $this->queue ) ) {
			return;
		}
		$urls        = array_keys( $this->queue );
		$this->queue = array();
		$this->purge_urls( $urls );
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

		$results = ( '' !== $target )
			? $this->purge_urls( $this->collect_host_urls( $target ) )
			: $this->purge_all();

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
		$token        = $this->get_token();
		$purge_url    = add_query_arg( self::QUERY_VAR, $token, network_home_url( '/' ) );
		$hosts        = $this->get_network_hosts();
		$example_host = ! empty( $hosts ) ? reset( $hosts ) : 'example.com';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Varnish Cache — Network', 'varnish-network-purge' ); ?></h1>

			<h2><?php esc_html_e( 'Global purge', 'varnish-network-purge' ); ?></h2>
			<p>
			<?php
			printf(
				wp_kses(
					/* translators: %d: number of network domains */
					__( 'Purge the Varnish cache of all <strong>%d network domains</strong> at once (every known URL of every site, one PURGE request per URL).', 'varnish-network-purge' ),
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php echo esc_js( __( 'Regenerate the token? Existing URLs will stop working.', 'varnish-network-purge' ) ); ?>');">
				<input type="hidden" name="action" value="vnp_regen_token" />
				<?php wp_nonce_field( 'vnp_regen_token' ); ?>
				<?php submit_button( __( 'Regenerate token', 'varnish-network-purge' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_purge_all() {
		if ( ! current_user_can( 'manage_network' ) ) {
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

		$results = $this->purge_urls( $this->collect_host_urls( $host ) );
		$this->stash_notice( $results, $host );
		$this->redirect_back();
	}

	public function handle_regen_token() {
		if ( ! current_user_can( 'manage_network' ) ) {
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
		</div>
		<?php
	}

	public function handle_purge_current() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'varnish-network-purge' ) );
		}
		check_admin_referer( 'vnp_purge_current' );

		$results = $this->purge_urls( $this->collect_site_urls() );
		$this->stash_notice( $results, $this->current_host() );
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

		$class   = ( $notice['total'] > 0 && $notice['ok'] === $notice['total'] ) ? 'notice-success' : 'notice-warning';
		$message = sprintf(
			/* translators: 1: target label, 2: purged URLs, 3: total URLs */
			__( 'Varnish cache purged — %1$s. %2$d / %3$d URLs purged.', 'varnish-network-purge' ),
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
		$lines[] = "Varnish purge: {$ok}/{$total} URLs OK";
		$lines[] = str_repeat( '-', 40 );
		foreach ( $results as $r ) {
			$status  = $r['ok'] ? 'OK ' : 'ERR';
			$detail  = $r['err'] ? ' (' . $r['err'] . ')' : '';
			$lines[] = sprintf( '[%s] %d  %s%s', $status, $r['code'], $r['url'], $detail );
		}
		return implode( "\n", $lines ) . "\n";
	}
}
