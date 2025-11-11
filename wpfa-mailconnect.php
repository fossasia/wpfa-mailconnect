<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://fossasia.org
 * @since             1.0.0
 * @package           Wpfa_Mailconnect
 *
 * @wordpress-plugin
 * Plugin Name:       FOSSASIA Mail Connect
 * Plugin URI:        https://github.com/fossasia/wpfa-mailconnect
 * Description:       A helper plugin to assist Wordpress to connect to your mail and send and receive emails.
 * Version:           1.0.0
 * Author:            FOSSASIA
 * Author URI:        https://fossasia.org/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wpfa-mailconnect
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.1.0 for log retention features.
 */
define( 'WPFA_MAILCONNECT_VERSION', '1.1.0' );

/**
 * Defines the required database schema version.
 * Updated to 1.1.0 to trigger dbDelta for log table indexes.
 */
define( 'WPFA_MAILCONNECT_DB_VERSION', '1.1.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-wpfa-mailconnect-activator.php
 */
function activate_wpfa_mailconnect() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpfa-mailconnect-activator.php';
	Wpfa_Mailconnect_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-wpfa-mailconnect-deactivator.php
 */
function deactivate_wpfa_mailconnect() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpfa-mailconnect-deactivator.php';
	Wpfa_Mailconnect_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_wpfa_mailconnect' );
register_deactivation_hook( __FILE__, 'deactivate_wpfa_mailconnect' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-wpfa-mailconnect.php';

/**
 * Require the logger class
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpfa-mailconnect-logger.php';

/**
 * Require the updater class for database migrations.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpfa-mailconnect-updater.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_wpfa_mailconnect() {

	$plugin = new Wpfa_Mailconnect();
	$plugin->run();
}
run_wpfa_mailconnect();