<?php

/**
 * Fired during plugin activation
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect_Activator {

	/**
	 * Creates the email logs table upon plugin activation.
	 *
	 * Requires the logger class and calls its static table creation method.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		// Create the email logs table
		require_once plugin_dir_path( __FILE__ ) . 'class-wpfa-mailconnect-logger.php';
		
		// Call the static method to create the table
		Wpfa_Mailconnect_Logger::create_log_table();
	}

}