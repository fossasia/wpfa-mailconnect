<?php

/**
 * Log management class for database interaction.
 *
 * Handles creating the log table, inserting new email log entries,
 * retrieving logs with filtering/pagination, and clearing logs.
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

/**
 * Logger class definition.
 *
 * @since      1.0.0
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect_Logger {

	/**
	 * The name of the database table for email logs.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $log_table_name    The database table name.
	 */
	private $log_table_name;

	/**
	 * Constructor. Sets up the table name.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->log_table_name = $wpdb->prefix . 'wpfa_mail_logs';
	}

	/**
	 * Creates the custom database table during plugin activation or migration.
	 *
	 * This method is generally called from Wpfa_Mailconnect_Activator::activate()
	 * and Wpfa_Mailconnect_Updater::run_migrations().
	 *
	 * @since 1.0.0
	 */
	public static function create_log_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'wpfa_mail_logs';
		$charset_collate = $wpdb->get_charset_collate();

		// Use dbDelta to safely create or update the table schema.
		// NOTE: The DB version is included as a comment for dbDelta to track schema changes.
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			to_email varchar(255) NOT NULL,
			subject varchar(255) NOT NULL,
			message longtext,
			status varchar(20) NOT NULL,
			error_message text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY status (status),
            KEY created_at (created_at),
            -- The prefix length of 191 is used for utf8mb4 compatibility with older MySQL versions (767 bytes index limit)
            KEY to_email (to_email(191))
		) $charset_collate;";
		
		// Concatenate the DB version comment, ensuring the constant is evaluated by PHP
		$sql .= " /* db_version " . WPFA_MAILCONNECT_DB_VERSION . " */";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Logs an email attempt to the database.
	 *
	 * @since 1.0.0
	 * @param string $to_email The recipient email address(es).
	 * @param string $subject The email subject.
	 * @param string $message The email body.
	 * @param string $status The result status ('success' or 'failed').
	 * @param string $error_message Any associated error message, if failed.
	 * @return bool True on success, false on failure.
	 */
	public function log_email( $to_email, $subject, $message, $status, $error_message = '' ) {
		global $wpdb;

		// Clean up message before logging to prevent excessive size, keeping first 10KB.
		$message = substr( $message, 0, 10240 );

		return $wpdb->insert(
			$this->log_table_name,
			array(
				'to_email'      => $to_email,
				'subject'       => $subject,
				'message'       => $message,
				'status'        => $status,
				'error_message' => $error_message,
			),
			array(
				'%s', // to_email
				'%s', // subject
				'%s', // message
				'%s', // status
				'%s', // error_message
			)
		);
	}

	/**
	 * Retrieves email logs with pagination, filtering by status, and searching by recipient.
	 *
	 * The SQL query is built dynamically based on the provided filtering parameters.
	 *
	 * @since 1.0.0
	 * @param int    $limit The number of logs to retrieve per page.
	 * @param int    $offset The starting offset for pagination.
	 * @param string $status Optional. Filter by status ('success' or 'failed').
	 * @param string $search Optional. Search term for the 'to_email' column.
	 * @return array Array of log objects.
	 */
	public function get_logs( $limit = 20, $offset = 0, $status = '', $search = '' ) {
		global $wpdb;
		
		// SECURITY FIX: Ensure pagination variables are absolute positive integers.
		$limit  = absint( $limit );
		$offset = absint( $offset );
        
		$table_name = $this->log_table_name;
		$where      = 'WHERE 1=1';
		$params     = array();

		// Filter by status
		if ( ! empty( $status ) && in_array( $status, array( 'success', 'failed' ), true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		// Search by recipient email (to_email)
		if ( ! empty( $search ) ) {
			$where   .= ' AND to_email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

        // Build the WHERE clause portion
        $sql = "SELECT * FROM $table_name $where ORDER BY created_at DESC";

        // Prepare WHERE clause if there are filter parameters
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        // Add pagination (always present, so always safe to prepare)
        $sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $limit, $offset );

        return $wpdb->get_results( $sql );
    }

	/**
	 * Gets the total count of email logs, respecting filters.
	 *
	 * @since 1.0.0
	 * @param string $status Optional. Filter count by status ('success' or 'failed').
	 * @param string $search Optional. Filter count by search term for 'to_email'.
	 * @return int Total count of logs matching the criteria.
	 */
	public function get_total_logs( $status = '', $search = '' ) {
		global $wpdb;
		$table_name = $this->log_table_name;
		$where      = 'WHERE 1=1';
		$params     = array();

		// Filter by status
		if ( ! empty( $status ) && in_array( $status, array( 'success', 'failed' ), true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		// Search by recipient email (to_email)
		if ( ! empty( $search ) ) {
			$where   .= ' AND to_email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql = "SELECT COUNT(*) FROM $table_name $where";

        // Prepare and execute the query
		if ( empty( $params ) ) {
            $count = $wpdb->get_var( $sql );
        } else {
            $prepared_sql = $wpdb->prepare( $sql, $params );
            $count        = $wpdb->get_var( $prepared_sql );
        }

		return (int) $count;
	}

	/**
	 * Clears all entries from the email log table.
	 *
	 * @since 1.0.0
	 * @return bool True if successful, false otherwise.
	 */
	public function clear_logs() {
		global $wpdb;
		return $wpdb->query( "TRUNCATE TABLE {$this->log_table_name}" );
	}

	/**
	 * Deletes log entries older than a specified number of days.
	 *
	 * This method is called by the daily WP Cron job established in Wpfa_Mailconnect_SMTP.
	 *
	 * @since 1.1.0
	 * @param int $days The number of days to keep logs for. Logs older than this will be deleted.
	 * @return int|false The number of deleted rows on success, or false on failure.
	 */
	public function clear_old_logs( $days ) {
		global $wpdb;

		$days = absint( $days );

		if ( $days <= 0 ) {
			return 0; // Don't delete anything if retention is 0 or invalid
		}

		// Calculate the cutoff date (current time minus $days days)
		// Use DATETIME field created_at and DATE_SUB for efficiency.
		$sql = $wpdb->prepare(
			"DELETE FROM {$this->log_table_name}
			WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		);

		// $wpdb->query returns the number of rows affected (deleted) or false on error.
		return $wpdb->query( $sql );
	}
}