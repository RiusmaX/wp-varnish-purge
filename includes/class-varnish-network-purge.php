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

	/** Minimum delay (seconds) between two purges of the same target via URL. */
	const THROTTLE_SEC = 10;

	/** @var Varnish_Network_Purge|null */
	private static $instance = null;

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
		// Token-protected URL trigger (front-end, any network domain).
		add_action( 'init', array( $this, 'maybe_handle_url_purge' ) );

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

	/* --------------------------------------------------------------------- */
	/* Core: targets + sending PURGE requests                                */
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
	 * List of unique network domains (deduplicated by host: subdirectory sites
	 * share the same domain and are covered by the "/*" purge of their host).
	 *
	 * @return string[]
	 */
	private function get_network_hosts() {
		$hosts = array();

		if ( ! function_exists( 'get_sites' ) ) {
			return $hosts;
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
	 * Current site's base URL (with trailing slash).
	 *
	 * @return string
	 */
	private function current_base() {
		return trailingslashit( home_url( '/' ) );
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
	 * Purge every network domain.
	 *
	 * @return array[]
	 */
	private function purge_all() {
		$bases = array();
		foreach ( $this->get_network_hosts() as $host ) {
			$bases[] = 'https://' . $host . '/';
		}
		return $this->purge_urls( $bases );
	}

	/**
	 * Send a PURGE request to each provided base as well as to its wildcard
	 * "<base>*", in parallel via curl_multi.
	 *
	 * @param string[] $bases Base URLs (e.g. https://example.com/ or https://example.com/subsite/).
	 * @return array[] List of results { url, code, ok, err }.
	 */
	private function purge_urls( array $bases ) {
		$results = array();

		if ( empty( $bases ) || ! function_exists( 'curl_multi_init' ) ) {
			return $results;
		}

		$mh      = curl_multi_init();
		$handles = array();

		foreach ( $bases as $base ) {
			$base = trailingslashit( $base );
			foreach ( array( $base, $base . '*' ) as $url ) {
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

		return $results;
	}

	/* --------------------------------------------------------------------- */
	/* Trigger: token-protected URL                                          */
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
			? $this->purge_urls( array( 'https://' . $target . '/' ) )
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
			'Varnish Cache',
			'Varnish Cache',
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
			<h1>Varnish Cache — Network</h1>

			<h2>Global purge</h2>
			<p>Purge the Varnish cache of all <strong><?php echo count( $hosts ); ?> network domains</strong> at once.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vnp_purge_all" />
				<?php wp_nonce_field( 'vnp_purge_all' ); ?>
				<?php submit_button( 'Purge all Varnish cache', 'primary large' ); ?>
			</form>

			<hr />

			<h2>Per-site purge (<?php echo count( $hosts ); ?>)</h2>
			<table class="widefat striped" style="max-width:820px;">
				<thead>
					<tr>
						<th>Domain</th>
						<th style="width:140px;">Action</th>
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
									<?php submit_button( 'Purge', 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<hr />

			<h2>URL trigger</h2>
			<p>URL to call (curl, bookmark, scheduled task). <strong>Keep it secret.</strong></p>
			<p>
				<input type="text" readonly onclick="this.select();" style="width:100%;max-width:820px;"
					value="<?php echo esc_attr( $purge_url ); ?>" />
			</p>
			<p><code>curl -s "<?php echo esc_html( $purge_url ); ?>"</code></p>

			<p>To purge <strong>a single site</strong>, append <code>&amp;host=DOMAIN</code>:</p>
			<p><code>curl -s "<?php echo esc_html( add_query_arg( 'host', $example_host, $purge_url ) ); ?>"</code></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('Regenerate the token? Existing URLs will stop working.');">
				<input type="hidden" name="action" value="vnp_regen_token" />
				<?php wp_nonce_field( 'vnp_regen_token' ); ?>
				<?php submit_button( 'Regenerate token', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_purge_all() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( 'Action not allowed.' );
		}
		check_admin_referer( 'vnp_purge_all' );

		$results = $this->purge_all();
		$this->stash_notice( $results, 'the whole network' );
		$this->redirect_back();
	}

	public function handle_purge_site() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( 'Action not allowed.' );
		}
		check_admin_referer( 'vnp_purge_site' );

		$host = isset( $_POST['host'] ) ? trim( wp_unslash( $_POST['host'] ) ) : '';
		if ( ! in_array( $host, $this->get_network_hosts(), true ) ) {
			wp_die( 'Unknown domain.' );
		}

		$results = $this->purge_urls( array( 'https://' . $host . '/' ) );
		$this->stash_notice( $results, $host );
		$this->redirect_back();
	}

	public function handle_regen_token() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( 'Action not allowed.' );
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
			'Varnish Cache',
			'Varnish Cache',
			'manage_options',
			'varnish-purge',
			array( $this, 'render_site_page' )
		);
	}

	public function render_site_page() {
		?>
		<div class="wrap">
			<h1>Varnish Cache</h1>
			<p>Purge this site's Varnish cache: <strong><?php echo esc_html( $this->current_host() ); ?></strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vnp_purge_current" />
				<?php wp_nonce_field( 'vnp_purge_current' ); ?>
				<?php submit_button( "Purge this site's cache", 'primary large' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_purge_current() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Action not allowed.' );
		}
		check_admin_referer( 'vnp_purge_current' );

		$results = $this->purge_urls( array( $this->current_base() ) );
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
			'title' => '<span class="ab-icon dashicons dashicons-update" style="font-family:dashicons;top:2px;"></span>' . esc_html( 'Varnish Cache' ),
			'href'  => $root_href,
			'meta'  => array( 'title' => 'Varnish cache purge' ),
		) );

		if ( $can_site ) {
			$wp_admin_bar->add_node( array(
				'parent' => 'vnp',
				'id'     => 'vnp-site',
				'title'  => 'Purge this site',
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=vnp_purge_current' ), 'vnp_purge_current' ),
			) );
		}

		if ( $can_net ) {
			$wp_admin_bar->add_node( array(
				'parent' => 'vnp',
				'id'     => 'vnp-all',
				'title'  => 'Purge the whole network',
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
	 * @param string  $scope_label Target label.
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

		$class = ( $notice['total'] > 0 && $notice['ok'] === $notice['total'] ) ? 'notice-success' : 'notice-warning';
		printf(
			'<div class="notice %s is-dismissible"><p><strong>Varnish cache purged — %s.</strong> %d / %d PURGE requests succeeded.</p></div>',
			esc_attr( $class ),
			esc_html( $notice['scope'] ),
			(int) $notice['ok'],
			(int) $notice['total']
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
