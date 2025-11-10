<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect_Deactivator {

	/**
	 * Clears the scheduled cron job for log cleanup.
	 *
	 * @since    1.1.0
	 */
	public static function deactivate() {
		// Clear the scheduled daily log cleanup cron job
		wp_clear_scheduled_hook( 'wpfa_mailconnect_cleanup_logs' );
	}

}