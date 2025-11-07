<?php

/**
 * This class handles all database operations for email logging - creating the
 * table, storing logs, retrieving logs, and clearing logs.
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

class Wpfa_Mailconnect_Logger {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wpfa_mail_logs';
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            to_email varchar(100) NOT NULL,
            subject varchar(255) NOT NULL, 
            message text NOT NULL,
            status varchar(20) NOT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function log_email($to, $subject, $message, $status, $error = '') {
        global $wpdb;
        
        return $wpdb->insert(
            $this->table_name,
            array(
                'to_email' => $to,
                'subject' => $subject,
                'message' => wp_strip_all_tags($message),
                'status' => $status,
                'error_message' => $error
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get paginated email logs
     *
     * @param int $limit Number of logs per page
     * @param int $offset Offset for pagination
     * @return array Array of log objects
     */
    public function get_logs($limit = 20, $offset = 0) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                 ORDER BY created_at DESC 
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
    }

    /**
     * Get total number of log entries
     *
     * @return int Total number of logs
     */
    public function get_total_logs() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }

    public function clear_logs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
}
