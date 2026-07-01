<?php
/**
 * Plugin Name:       Varnish Network Purge
 * Plugin URI:        https://github.com/RiusmaX/wp-varnish-purge
 * Description:       Purge the Varnish cache of a WordPress multisite network: purge the whole network or a single site from the Network admin, purge the current site from its own settings and from the admin bar, and trigger purges through a token-protected URL (curl / cron).
 * Version:           1.0.0
 * Requires at least: 5.2
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

define( 'VNP_VERSION', '1.0.0' );
define( 'VNP_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-varnish-network-purge.php';

// Bootstrap.
add_action( 'plugins_loaded', array( 'Varnish_Network_Purge', 'instance' ) );
