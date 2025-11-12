<?php

/**
 * Log management class for database interaction.
 *
 * Handles creating the log table, inserting new email log entries,
 * retrieving logs with filtering/pagination, and clearing logs.
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 * @version    1.2.0 (Updated for full HTML content and detailed status logging)
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

		// Check if table already exists
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;

		// If table exists, only add missing columns instead of running full dbDelta
		if ( $table_exists ) {
			// Check and add body_html column if it doesn't exist
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'body_html'" );
			if ( empty( $column_exists ) ) {
				$wpdb->query( "ALTER TABLE $table_name ADD COLUMN body_html longtext AFTER message" );
			}

			// Check and add status_details column if it doesn't exist
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'status_details'" );
			if ( empty( $column_exists ) ) {
				$wpdb->query( "ALTER TABLE $table_name ADD COLUMN status_details text AFTER error_message" );
			}

			// Check and add headers column if it doesn't exist (NEW CODE)
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'headers'" );
			if ( empty( $column_exists ) ) {
				// Using text (up to 65KB) for headers; change to longtext if headers can exceed 65KB.
				$wpdb->query( "ALTER TABLE $table_name ADD COLUMN headers text AFTER status_details" ); 
			}

			return; // Exit early to avoid dbDelta issues
		}

		// NOTE: The DB version is included as a comment for dbDelta to track schema changes.
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hash varchar(64) NOT NULL,
			to_email varchar(255) NOT NULL,
			subject varchar(255) NOT NULL,
			message longtext,
			body_html longtext,
			status varchar(20) NOT NULL,
			error_message text,
			status_details text,
			headers text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_hash (hash),
			KEY status (status),
			KEY created_at (created_at),
			KEY to_email (to_email(191))
		) $charset_collate;";

        /* The prefix length of 191 is used for utf8mb4 compatibility with older MySQL versions (767 bytes index limit) */
		// We must define the DB version constant in the plugin's main file (wpfa-mailconnect.php)
		// For now, we assume it's set to 1.2.0 in the main plugin file.
		$db_version = defined( 'WPFA_MAILCONNECT_DB_VERSION' ) ? WPFA_MAILCONNECT_DB_VERSION : '1.2.0';
		$sql .= " /* db_version " . $db_version . " */";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// --- New Logger API Methods for Status Lifecycle ---

	/**
     * Inserts a new log entry with status 'pending' if the hash does not already exist.
     * This method prevents deterministic duplicates at the database level.
     *
     * @since 1.2.0
     * @param string $hash The deterministic hash of the email content.
     * @param string $to The recipient email address(es) (CSV format).
	 * @param string $subject The email subject.
     * @param string $message The email body (plain text).
     * @param string $body_html The email body (HTML).
     * @param string $headers The email headers (JSON or string).
     * @return bool True on successful insertion, False if the query fails or if hash already exists.
     */
	/**
	 * Inserts a new log entry with status 'pending' if the hash does not already exist.
	 * This method prevents deterministic duplicates at the database level.
	 *
	 * @since 1.2.0
	 * @param string $hash The deterministic hash of the email content.
	 * @param string $to The recipient email address(es) (CSV format).
	 * @param string $subject The email subject.
	 * @param string $message The email body (plain text).
	 * @param string $body_html The email body (HTML).
	 * @param string $headers The email headers (JSON or string).
	 * @return bool True on successful insertion, False if the query fails or if hash already exists.
	 */
	public function insert_pending( $hash, $to, $subject, $message, $body_html, $headers = '' ) {
		global $wpdb;
		$table = $this->log_table_name;

		// Clean up message and body_html before logging to prevent excessive size.
		// Truncating to 1MB (1048576 bytes) as longtext handles up to 4GB, but we aim for safety.
		$message   = substr( $message, 0, 1048576 );
		$body_html = substr( $body_html, 0, 1048576 );
		$headers   = substr( $headers, 0, 65535 ); // Truncate headers for text column

		// The SQL query is corrected here to include 'headers' in the column list
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (hash, to_email, subject, message, body_html, status, error_message, headers, created_at)
			  SELECT %s, %s, %s, %s, %s, 'pending', %s, %s, %s
			WHERE NOT EXISTS (SELECT 1 FROM $table WHERE hash = %s)",
			$hash,            // %s: hash
			$to,              // %s: to_email
			$subject,         // %s: subject
			$message,         // %s: message
			$body_html,       // %s: body_html
			'',               // %s: error_message (empty for pending)
			$headers,         // %s: headers
			current_time('mysql'), // %s: created_at
			$hash             // %s: for WHERE NOT EXISTS clause
		 ));

		// $wpdb->query returns 1 for success, 0 for duplicate, false for error
		return $result !== false;
	}

	/**
     * Updates the status, error message, and detailed status/ID for a log entry identified by its hash.
     *
     * @since 1.2.0
     * @param string $hash The deterministic hash of the email content.
	 * @param string $status The result status ('success' or 'failed').
     * @param string $error The high-level error message (used for display).
     * @param string $status_details Detailed, technical status or tracking ID (used for debugging).
     * @return int|false The number of rows updated (0 or 1), or false on error.
     */
	public function update_status( $hash, $status, $error = '', $status_details = '' ) {
		global $wpdb;
		$table = $this->log_table_name;

		// Ensure messages don't exceed column size limits.
		$error 			= substr( $error, 0, 65535 ); 
		$status_details = substr( $status_details, 0, 65535 );

		// Update the existing row based on the unique hash.
		return $wpdb->update(
			$table,
			array( 
				'status' 		=> $status, 
				'error_message' => $error,
				'status_details' => $status_details 
			),
			array( 'hash' => $hash ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
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
		if ( ! empty( $status ) && in_array( $status, array( 'success', 'failed', 'pending' ), true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		// Search by recipient email (to_email)
		if ( ! empty( $search ) ) {
			$where   .= ' AND to_email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

        // Build the WHERE clause portion
		// NOTE: We include all fields here, including the new ones (body_html, status_details)
		$sql = "SELECT id, hash, to_email, subject, message, body_html, status, error_message, status_details, created_at FROM $table_name $where ORDER BY created_at DESC";

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
		if ( ! empty( $status ) && in_array( $status, array( 'success', 'failed', 'pending' ), true ) ) {
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
		return $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->log_table_name ) );
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