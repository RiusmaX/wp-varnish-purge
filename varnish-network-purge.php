<?php
/**
 * Plugin Name:       Varnish Network Purge
 * Plugin URI:        https://github.com/RiusmaX/wp-varnish-purge
 * Description:       Purge the Varnish cache of a WordPress multisite network: automatic targeted purge when content changes, full purge of the network or of a single site from the admin (URL-by-URL, for Varnish setups that only support exact-URL purge), and a token-protected trigger URL (curl / cron).
 * Version:           1.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Marius Sergent
 * Author URI:        https://sergent.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Network:           true
 * Text Domain:       varnish-network-purge
 *
 * @package VarnishNetworkPurge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VNP_VERSION', '1.1.0' );
define( 'VNP_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-varnish-network-purge.php';

// Bootstrap.
add_action( 'plugins_loaded', array( 'Varnish_Network_Purge', 'instance' ) );
