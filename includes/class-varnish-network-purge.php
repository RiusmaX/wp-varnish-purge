<?php
/**
 * Classe principale du plugin Varnish Network Purge.
 *
 * @package VarnishNetworkPurge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Varnish_Network_Purge {

	/** Option réseau contenant le jeton secret. */
	const OPTION_TOKEN = 'varnish_purge_token';

	/** Paramètre d'URL déclenchant la purge. */
	const QUERY_VAR = 'varnish_purge';

	/** Préfixe du transient réseau de limitation (suivi de la cible). */
	const THROTTLE_KEY = 'vnp_last_run_';

	/** Préfixe du transient réseau d'avis d'admin (suivi de l'ID utilisateur). */
	const NOTICE_KEY = 'vnp_notice_';

	/** Délai minimum (secondes) entre deux purges d'une même cible via URL. */
	const THROTTLE_SEC = 10;

	/** @var Varnish_Network_Purge|null */
	private static $instance = null;

	/**
	 * Point d'entrée unique.
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
		// Déclencheur URL protégé par jeton (front, tout domaine du réseau).
		add_action( 'init', array( $this, 'maybe_handle_url_purge' ) );

		// Interfaces d'administration.
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
		add_action( 'admin_menu', array( $this, 'add_site_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar' ), 100 );

		// Handlers d'action (formulaires + liens de la barre d'admin).
		add_action( 'admin_post_vnp_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'admin_post_vnp_purge_site', array( $this, 'handle_purge_site' ) );
		add_action( 'admin_post_vnp_purge_current', array( $this, 'handle_purge_current' ) );
		add_action( 'admin_post_vnp_regen_token', array( $this, 'handle_regen_token' ) );

		// Avis affichés après une purge déclenchée depuis l'admin.
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'maybe_show_notice' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Cœur : cibles + envoi des PURGE                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Récupère (ou génère) le jeton secret partagé par le réseau.
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
	 * Liste des domaines uniques du réseau (déduplication sur l'hôte : les
	 * sites en sous-dossier partagent le même domaine et sont couverts par la
	 * purge « /* » de leur hôte).
	 *
	 * @return string[]
	 */
	private function get_network_hosts() {
		$hosts = array();

		if ( ! function_exists( 'get_sites' ) ) {
			return $hosts;
		}

		$sites = get_sites( array(
			'number'   => 0, // 0 = pas de limite (tous les sites).
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
	 * Base d'URL (avec slash final) du site courant.
	 *
	 * @return string
	 */
	private function current_base() {
		return trailingslashit( home_url( '/' ) );
	}

	/**
	 * Hôte du site courant (pour affichage / libellés).
	 *
	 * @return string
	 */
	private function current_host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host ? $host : '';
	}

	/**
	 * Purge l'ensemble des domaines du réseau.
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
	 * Envoie une requête PURGE sur chaque base fournie ainsi que sur son
	 * joker « <base>* », en parallèle via curl_multi.
	 *
	 * @param string[] $bases Bases d'URL (ex. https://exemple.com/ ou https://exemple.com/sous-site/).
	 * @return array[] Liste de résultats { url, code, ok, err }.
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
	/* Déclencheur : URL protégée par jeton                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Réseau  : https://exemple.com/?varnish_purge=JETON
	 * Un site : https://exemple.com/?varnish_purge=JETON&host=sous-site.exemple.com
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
			echo "403 - Jeton invalide.";
			exit;
		}

		// Cible : un site précis via &host=... , sinon tout le réseau.
		$target = isset( $_GET['host'] ) ? trim( wp_unslash( $_GET['host'] ) ) : '';
		if ( '' !== $target && ! in_array( $target, $this->get_network_hosts(), true ) ) {
			status_header( 404 );
			echo "404 - Domaine inconnu dans le reseau.";
			exit;
		}

		// Limitation par cible : évite les purges en rafale (cache stampede).
		$throttle_key = self::THROTTLE_KEY . md5( '' !== $target ? $target : 'all' );
		$last         = (int) get_site_transient( $throttle_key );
		if ( $last && ( time() - $last ) < self::THROTTLE_SEC ) {
			$wait = self::THROTTLE_SEC - ( time() - $last );
			status_header( 429 );
			echo "429 - Une purge vient d'etre effectuee. Reessayez dans {$wait}s.";
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
	/* Admin Réseau : purge globale + purge par site + jeton                 */
	/* --------------------------------------------------------------------- */

	public function add_network_menu() {
		add_submenu_page(
			'settings.php',
			'Cache Varnish',
			'Cache Varnish',
			'manage_network',
			'varnish-purge',
			array( $this, 'render_network_page' )
		);
	}

	public function render_network_page() {
		$token        = $this->get_token();
		$purge_url    = add_query_arg( self::QUERY_VAR, $token, network_home_url( '/' ) );
		$hosts        = $this->get_network_hosts();
		$example_host = ! empty( $hosts ) ? reset( $hosts ) : 'exemple.com';
		?>
		<div class="wrap">
			<h1>Cache Varnish — Réseau</h1>

			<h2>Purge globale</h2>
			<p>Purge le cache Varnish des <strong><?php echo count( $hosts ); ?> domaines</strong> du réseau en une fois.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vnp_purge_all" />
				<?php wp_nonce_field( 'vnp_purge_all' ); ?>
				<?php submit_button( 'Vider tout le cache Varnish', 'primary large' ); ?>
			</form>

			<hr />

			<h2>Purge par site (<?php echo count( $hosts ); ?>)</h2>
			<table class="widefat striped" style="max-width:820px;">
				<thead>
					<tr>
						<th>Domaine</th>
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
									<?php submit_button( 'Purger', 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<hr />

			<h2>Déclenchement par URL</h2>
			<p>URL à appeler (curl, marque-page, tâche planifiée). <strong>À garder secrète.</strong></p>
			<p>
				<input type="text" readonly onclick="this.select();" style="width:100%;max-width:820px;"
					value="<?php echo esc_attr( $purge_url ); ?>" />
			</p>
			<p><code>curl -s "<?php echo esc_html( $purge_url ); ?>"</code></p>

			<p>Pour purger <strong>un seul site</strong>, ajoutez <code>&amp;host=DOMAINE</code> :</p>
			<p><code>curl -s "<?php echo esc_html( add_query_arg( 'host', $example_host, $purge_url ) ); ?>"</code></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('Régénérer le jeton ? Les anciennes URL cesseront de fonctionner.');">
				<input type="hidden" name="action" value="vnp_regen_token" />
				<?php wp_nonce_field( 'vnp_regen_token' ); ?>
				<?php submit_button( 'Régénérer le jeton', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_purge_all() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		check_admin_referer( 'vnp_purge_all' );

		$results = $this->purge_all();
		$this->stash_notice( $results, 'tout le réseau' );
		$this->redirect_back();
	}

	public function handle_purge_site() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		check_admin_referer( 'vnp_purge_site' );

		$host = isset( $_POST['host'] ) ? trim( wp_unslash( $_POST['host'] ) ) : '';
		if ( ! in_array( $host, $this->get_network_hosts(), true ) ) {
			wp_die( 'Domaine inconnu.' );
		}

		$results = $this->purge_urls( array( 'https://' . $host . '/' ) );
		$this->stash_notice( $results, $host );
		$this->redirect_back();
	}

	public function handle_regen_token() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		check_admin_referer( 'vnp_regen_token' );

		update_site_option( self::OPTION_TOKEN, wp_generate_password( 40, false, false ) );
		$this->redirect_back();
	}

	/* --------------------------------------------------------------------- */
	/* Admin du site : purge du site courant via ses réglages                */
	/* --------------------------------------------------------------------- */

	public function add_site_menu() {
		add_options_page(
			'Cache Varnish',
			'Cache Varnish',
			'manage_options',
			'varnish-purge',
			array( $this, 'render_site_page' )
		);
	}

	public function render_site_page() {
		?>
		<div class="wrap">
			<h1>Cache Varnish</h1>
			<p>Vider le cache Varnish de ce site : <strong><?php echo esc_html( $this->current_host() ); ?></strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vnp_purge_current" />
				<?php wp_nonce_field( 'vnp_purge_current' ); ?>
				<?php submit_button( 'Vider le cache de ce site', 'primary large' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_purge_current() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		check_admin_referer( 'vnp_purge_current' );

		$results = $this->purge_urls( array( $this->current_base() ) );
		$this->stash_notice( $results, $this->current_host() );
		$this->redirect_back();
	}

	/* --------------------------------------------------------------------- */
	/* Barre d'administration                                                */
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
			'title' => '<span class="ab-icon dashicons dashicons-update" style="font-family:dashicons;top:2px;"></span>' . esc_html( 'Cache Varnish' ),
			'href'  => $root_href,
			'meta'  => array( 'title' => 'Purge du cache Varnish' ),
		) );

		if ( $can_site ) {
			$wp_admin_bar->add_node( array(
				'parent' => 'vnp',
				'id'     => 'vnp-site',
				'title'  => 'Purger ce site',
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=vnp_purge_current' ), 'vnp_purge_current' ),
			) );
		}

		if ( $can_net ) {
			$wp_admin_bar->add_node( array(
				'parent' => 'vnp',
				'id'     => 'vnp-all',
				'title'  => 'Purger tout le réseau',
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=vnp_purge_all' ), 'vnp_purge_all' ),
			) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Avis d'admin (après redirection)                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Mémorise le résultat d'une purge pour l'utilisateur courant.
	 *
	 * @param array[] $results     Résultats de purge.
	 * @param string  $scope_label Libellé de la cible.
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
			'<div class="notice %s is-dismissible"><p><strong>Cache Varnish vidé — %s.</strong> %d / %d requêtes PURGE réussies.</p></div>',
			esc_attr( $class ),
			esc_html( $notice['scope'] ),
			(int) $notice['ok'],
			(int) $notice['total']
		);
	}

	/**
	 * Redirige vers la page précédente (formulaire d'admin ou lien de la
	 * barre d'admin), avec repli sûr.
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
	/* Utilitaires                                                           */
	/* --------------------------------------------------------------------- */

	private function format_results_text( array $results ) {
		$ok    = count( array_filter( $results, function ( $r ) { return $r['ok']; } ) );
		$total = count( $results );

		$lines   = array();
		$lines[] = "Purge Varnish : {$ok}/{$total} requetes OK";
		$lines[] = str_repeat( '-', 40 );
		foreach ( $results as $r ) {
			$status  = $r['ok'] ? 'OK ' : 'ERR';
			$detail  = $r['err'] ? ' (' . $r['err'] . ')' : '';
			$lines[] = sprintf( '[%s] %d  %s%s', $status, $r['code'], $r['url'], $detail );
		}
		return implode( "\n", $lines ) . "\n";
	}
}
