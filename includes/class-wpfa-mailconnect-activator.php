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
	 * Creates the email logs table upon plugin activation, sets the initial DB version,
	 * and schedules the daily log cleanup event.
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

		// Set the initial DB version for the migration system.
		// add_option only succeeds if the option does not already exist, which is perfect for activation.
		add_option( 'wpfa_mailconnect_db_version', WPFA_MAILCONNECT_DB_VERSION );
		
		// Schedule the daily log cleanup cron job for retention policy
		$cleanup_hook = 'wpfa_mailconnect_cleanup_logs';
		if ( ! wp_next_scheduled( $cleanup_hook ) ) {
			// Schedule a daily event starting right now.
			// This event will be hooked into by the main plugin class (class-wpfa-mailconnect.php)
			wp_schedule_event( time(), 'daily', $cleanup_hook );
		}
	}

}