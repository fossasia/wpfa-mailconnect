<?php

/**
 * Email Logger Service - Consolidated email logging flow.
 *
 * This service consolidates the email logging lifecycle that was previously
 * spread across multiple hooks (log_email_on_send, track_email_result, log_email_success).
 * It ensures atomic operations and prevents race conditions.
 *
 * @link       https://fossasia.org
 * @since      1.2.1
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

/**
 * Email Logger Service class definition.
 *
 * This service hooks into WordPress's wp_mail filter and wp_mail_failed action
 * to capture all email sends in a consolidated, atomic way.
 *
 * @since      1.2.1
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect_Email_Logger_Service {

	/**
	 * The logger instance for database operations.
	 *
	 * @since    1.2.1
	 * @access   private
	 * @var      Wpfa_Mailconnect_Logger    $logger    The logger instance.
	 */
	private $logger;

	/**
	 * Stores the hash of the currently processing email.
	 *
	 * @since    1.2.1
	 * @access   private
	 * @var      string    $current_mail_hash    Current email hash for linking status updates.
	 */
	private $current_mail_hash = '';

	/**
	 * Stores the current email data for status updates.
	 *
	 * @since    1.2.1
	 * @access   private
	 * @var      array    $current_mail_data    Current email data.
	 */
	private $current_mail_data = array();

	/**
	 * Constructor. Initializes the logger.
	 *
	 * @since    1.2.1
	 * @param    Wpfa_Mailconnect_Logger    $logger    Optional. Logger instance.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger ? $logger : new Wpfa_Mailconnect_Logger();
	}

	/**
	 * Registers hooks with WordPress for email logging.
	 *
	 * This method should be called during plugin initialization to register
	 * the appropriate hooks for email logging.
	 *
	 * @since    1.2.1
	 */
	public function register_hooks() {
		// Hook into wp_mail filter to log pending emails (priority 10, before send)
		add_filter( 'wp_mail', array( $this, 'log_pending_email' ), 10, 1 );
		
		// Hook into wp_mail filter again to track success (priority 999, after send)
		add_filter( 'wp_mail', array( $this, 'log_email_success' ), 999, 1 );
		
		// Hook into wp_mail_failed action to log failures
		add_action( 'wp_mail_failed', array( $this, 'log_email_failure' ), 10, 1 );
	}

	/**
	 * Logs the email with 'pending' status before it's sent.
	 *
	 * This runs early in the wp_mail filter chain (priority 10).
	 *
	 * @since    1.2.1
	 * @param    array    $args    The wp_mail arguments array.
	 * @return   array             The unmodified arguments (must return for wp_mail to work).
	 */
	public function log_pending_email( $args ) {
		// Check if logging is enabled
		if ( ! $this->is_logging_enabled() ) {
			return $args;
		}

		// Generate deterministic hash
		$hash = $this->generate_hash( $args );
		
		// Store hash and data for later use
		$this->current_mail_hash = $hash;
		$this->current_mail_data = $args;

		// Extract email components
		$to        = $this->format_recipients( $args['to'] );
		$subject   = isset( $args['subject'] ) ? $args['subject'] : 'No Subject';
		$message   = isset( $args['message'] ) ? $args['message'] : '';
		$headers   = $this->format_headers( isset( $args['headers'] ) ? $args['headers'] : '' );
		
		// Extract HTML body if present (WordPress may add this)
		$body_html = '';
		if ( isset( $args['message'] ) && $this->is_html_content( $args ) ) {
			$body_html = $args['message'];
		}

		// Insert pending log entry
		$this->logger->insert_pending(
			$hash,
			$to,
			$subject,
			$message,
			$body_html,
			$headers
		);

		// MUST return args unchanged for wp_mail to work
		return $args;
	}

	/**
	 * Updates the log to 'success' status after email is sent.
	 *
	 * This runs late in the wp_mail filter chain (priority 999).
	 * If we reach this point, wp_mail succeeded (didn't trigger wp_mail_failed).
	 *
	 * @since    1.2.1
	 * @param    array    $args    The wp_mail arguments array.
	 * @return   array             The unmodified arguments.
	 */
	public function log_email_success( $args ) {
		// Check if logging is enabled
		if ( ! $this->is_logging_enabled() ) {
			return $args;
		}

		// Only update if we have a hash from the pending log
		if ( ! empty( $this->current_mail_hash ) ) {
			$this->logger->update_status(
				$this->current_mail_hash,
				'success',
				'',
				'Email sent successfully at ' . current_time( 'mysql' )
			);

			// Clear the hash to prevent duplicate updates
			$this->current_mail_hash = '';
			$this->current_mail_data = array();
		}

		return $args;
	}

	/**
	 * Logs email failure when wp_mail fails.
	 *
	 * This is triggered by WordPress's wp_mail_failed action.
	 *
	 * @since    1.2.1
	 * @param    WP_Error    $error    The error object from wp_mail.
	 */
	public function log_email_failure( $error ) {
		// Check if logging is enabled
		if ( ! $this->is_logging_enabled() ) {
			return;
		}

		// Try to get hash from stored data first
		$hash = $this->current_mail_hash;

		// If no stored hash, try to extract from error data
		if ( empty( $hash ) ) {
			$error_data = $error->get_error_data();
			if ( is_array( $error_data ) ) {
				$hash = $this->generate_hash( $error_data );
			}
		}

		// Only proceed if we have a hash
		if ( ! empty( $hash ) ) {
			$this->logger->update_status(
				$hash,
				'failed',
				$error->get_error_message(),
				$this->get_error_details( $error )
			);

			// Clear stored data
			$this->current_mail_hash = '';
			$this->current_mail_data = array();
		}
	}

	/**
	 * Checks if email logging is enabled in settings.
	 *
	 * @since    1.2.1
	 * @return   bool    True if logging is enabled, false otherwise.
	 */
	private function is_logging_enabled() {
		$options = get_option( 'smtp_options', array() );
		return isset( $options['enable_log'] ) ? (bool) $options['enable_log'] : false;
	}

	/**
	 * Generates a deterministic hash for the email based on its content.
	 *
	 * This hash is used to prevent duplicate logging of the same email.
	 *
	 * @since    1.2.1
	 * @param    array    $args    The wp_mail arguments array.
	 * @return   string            The MD5 hash of the email content.
	 */
	private function generate_hash( $args ) {
		$to_raw      = isset( $args['to'] ) ? $args['to'] : '';
		$subject_raw = isset( $args['subject'] ) ? $args['subject'] : '';
		$message_raw = isset( $args['message'] ) ? $args['message'] : '';
		$headers_raw = isset( $args['headers'] ) ? $args['headers'] : '';
		
		// Normalize 'to' field: ensure array, convert to lowercase, remove duplicates, sort.
		$to_array = is_array( $to_raw ) ? $to_raw : array( (string) $to_raw );
		$to_array = array_values( array_unique( array_map( 'strtolower', $to_array ) ) );
		sort( $to_array );

		$normalized_data = array(
			'to'      => $to_array,
			'subject' => trim( strtolower( (string) $subject_raw ) ),
			'message' => trim( (string) $message_raw ),
			'headers' => $headers_raw,
		);

		return md5( wp_json_encode( $normalized_data ) );
	}

	/**
	 * Formats recipient email addresses into a comma-separated string.
	 *
	 * @since    1.2.1
	 * @param    mixed    $recipients    String or array of recipient emails.
	 * @return   string                  Comma-separated email addresses.
	 */
	private function format_recipients( $recipients ) {
		if ( is_array( $recipients ) ) {
			return implode( ', ', $recipients );
		}
		return (string) $recipients;
	}

	/**
	 * Formats email headers for storage.
	 *
	 * Converts headers array to JSON or keeps as string.
	 *
	 * @since    1.2.1
	 * @param    mixed    $headers    Headers as string or array.
	 * @return   string               Formatted headers string.
	 */
	private function format_headers( $headers ) {
		if ( is_array( $headers ) ) {
			return wp_json_encode( $headers );
		}
		return (string) $headers;
	}

	/**
	 * Checks if the email content is HTML based on headers or content type.
	 *
	 * @since    1.2.1
	 * @param    array    $args    The wp_mail arguments.
	 * @return   bool              True if HTML content, false otherwise.
	 */
	private function is_html_content( $args ) {
		if ( ! isset( $args['headers'] ) ) {
			return false;
		}

		$headers = $args['headers'];
		if ( is_array( $headers ) ) {
			$headers = implode( "\n", $headers );
		}

		return ( stripos( $headers, 'Content-Type: text/html' ) !== false );
	}

	/**
	 * Extracts detailed error information from WP_Error object.
	 *
	 * @since    1.2.1
	 * @param    WP_Error    $error    The WP_Error object.
	 * @return   string                Detailed error information as JSON string.
	 */
	private function get_error_details( $error ) {
		$details = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $error->get_error_data(),
			'time'    => current_time( 'mysql' ),
		);

		return wp_json_encode( $details );
	}
}