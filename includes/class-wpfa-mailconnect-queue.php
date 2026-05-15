<?php
/**
 * Email queue management class.
 *
 * @package Wpfa_Mailconnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores wp_mail requests and processes them asynchronously via WP Cron.
 */
class Wpfa_Mailconnect_Queue {

	const TABLE_SUFFIX = 'wpfa_mail_queue';
	const PROCESS_CRON_HOOK = 'wpfa_mailconnect_process_queue';
	const CRON_INTERVAL = 'wpfa_mailconnect_every_five_minutes';
	const BATCH_SIZE = 10;
	const MAX_RETRIES = 3;

	/**
	 * Whether the current request is processing queued mail.
	 *
	 * @var bool
	 */
	private static $processing = false;

	/**
	 * Queue table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Logger instance.
	 *
	 * @var Wpfa_Mailconnect_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Wpfa_Mailconnect_Logger|null $logger Logger instance.
	 */
	public function __construct( $logger = null ) {
		global $wpdb;

		$this->table_name = $wpdb->prefix . self::TABLE_SUFFIX;
		$this->logger     = $logger ? $logger : new Wpfa_Mailconnect_Logger();
	}

	/**
	 * Creates the queue database table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'queued',
			hash varchar(64) NOT NULL,
			to_email longtext NOT NULL,
			subject text NOT NULL,
			message longtext,
			headers longtext,
			attachments longtext,
			retries int(11) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			next_attempt_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY status_next_attempt (status, next_attempt_at),
			KEY hash (hash),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Registers custom cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes', 'wpfa-mailconnect' ),
			);
		}

		return $schedules;
	}

	/**
	 * Indicates if email queueing is enabled in settings.
	 *
	 * @return bool
	 */
	public static function is_queue_enabled() {
		$options = get_option( 'smtp_options', array() );

		return isset( $options['enable_email_queue'] ) && '1' === (string) $options['enable_email_queue'];
	}

	/**
	 * Schedules queue processing.
	 *
	 * @return void
	 */
	public static function schedule_processing() {
		if ( self::is_queue_enabled() && ! wp_next_scheduled( self::PROCESS_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::PROCESS_CRON_HOOK );
		}
	}

	/**
	 * Unschedules queue processing.
	 *
	 * @return void
	 */
	public static function unschedule_processing() {
		wp_clear_scheduled_hook( self::PROCESS_CRON_HOOK );
	}

	/**
	 * Indicates if queue processing is currently sending a message.
	 *
	 * @return bool
	 */
	public static function is_processing() {
		return self::$processing;
	}

	/**
	 * Adds a wp_mail payload to the queue.
	 *
	 * @param array $args wp_mail arguments.
	 * @return int|false Insert id on success, false on failure.
	 */
	public function add_to_queue( $args ) {
		global $wpdb;

		$args = $this->normalize_mail_args( $args );
		$hash = Wpfa_Mailconnect_Logger::generate_mail_hash( $args );

		$attachment_details = $this->get_attachment_details( $args['attachments'] );
		$headers_for_log    = is_array( $args['headers'] ) ? wp_json_encode( $args['headers'] ) : (string) $args['headers'];
		$body_html          = $this->is_html_content( $args ) ? $args['message'] : '';
		$status_details     = wp_json_encode(
			array_merge(
				array(
					'message' => 'Email queued at ' . current_time( 'mysql' ),
				),
				$attachment_details
			)
		);

		$this->logger->insert_pending(
			$hash,
			$this->format_recipients( $args['to'] ),
			$args['subject'],
			$args['message'],
			$body_html,
			$headers_for_log,
			$status_details
		);
		$this->logger->update_status( $hash, 'queued', '', $status_details );

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'status'          => 'queued',
				'hash'            => $hash,
				'to_email'        => maybe_serialize( $args['to'] ),
				'subject'         => $args['subject'],
				'message'         => $args['message'],
				'headers'         => maybe_serialize( $args['headers'] ),
				'attachments'     => maybe_serialize( $args['attachments'] ),
				'retries'         => 0,
				'created_at'      => current_time( 'mysql' ),
				'next_attempt_at' => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			$this->logger->update_status(
				$hash,
				'failed',
				__( 'Failed to add email to queue.', 'wpfa-mailconnect' ),
				wp_json_encode( array_merge( array( 'message' => 'Queue insert failed.' ), $attachment_details ) )
			);
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Processes a small batch of queued messages.
	 *
	 * @return int Number of queue items attempted.
	 */
	public function process_queue_batch() {
		global $wpdb;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name}
				WHERE status = %s
					AND next_attempt_at <= %s
				ORDER BY created_at ASC
				LIMIT %d",
				'queued',
				current_time( 'mysql' ),
				self::BATCH_SIZE
			)
		);

		if ( empty( $items ) ) {
			return 0;
		}

		$attempted = 0;

		foreach ( $items as $item ) {
			$attempted++;
			$this->mark_processing( $item->id );

			$to          = maybe_unserialize( $item->to_email );
			$headers     = maybe_unserialize( $item->headers );
			$attachments = maybe_unserialize( $item->attachments );
			$error       = '';

			$error_capture = function( $wp_error ) use ( &$error ) {
				if ( is_wp_error( $wp_error ) ) {
					$error = $wp_error->get_error_message();
				}
			};

			add_action( 'wp_mail_failed', $error_capture );
			self::$processing = true;
			$sent = wp_mail( $to, $item->subject, $item->message, $headers, $attachments );
			self::$processing = false;
			remove_action( 'wp_mail_failed', $error_capture );

			if ( $sent ) {
				$this->mark_sent( $item );
			} else {
				$this->mark_failed_or_retry( $item, $error );
			}
		}

		return $attempted;
	}

	/**
	 * Returns counts by queue status.
	 *
	 * @return array Queue counts.
	 */
	public function get_status_counts() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table_name} GROUP BY status" );
		$counts = array(
			'queued'     => 0,
			'processing' => 0,
			'sent'       => 0,
			'failed'     => 0,
		);

		foreach ( $rows as $row ) {
			$counts[ $row->status ] = absint( $row->total );
		}

		return $counts;
	}

	/**
	 * Marks a queue item as processing.
	 *
	 * @param int $id Queue id.
	 * @return void
	 */
	private function mark_processing( $id ) {
		global $wpdb;

		$wpdb->update(
			$this->table_name,
			array(
				'status'     => 'processing',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a queue item and linked log as sent.
	 *
	 * @param object $item Queue item.
	 * @return void
	 */
	private function mark_sent( $item ) {
		global $wpdb;

		$details = wp_json_encode(
			array_merge(
				array(
					'message' => 'Queued email sent at ' . current_time( 'mysql' ),
				),
				$this->get_attachment_details( maybe_unserialize( $item->attachments ) )
			)
		);

		$wpdb->update(
			$this->table_name,
			array(
				'status'     => 'sent',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $item->id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->logger->update_status( $item->hash, 'success', '', $details );
	}

	/**
	 * Retries or permanently fails a queue item.
	 *
	 * @param object $item Queue item.
	 * @param string $error Error message.
	 * @return void
	 */
	private function mark_failed_or_retry( $item, $error ) {
		global $wpdb;

		$retries = absint( $item->retries ) + 1;
		$failed  = $retries >= self::MAX_RETRIES;
		$status  = $failed ? 'failed' : 'queued';
		$delay   = min( 60, 5 * $retries );
		$next_attempt = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $delay * MINUTE_IN_SECONDS ) );

		$wpdb->update(
			$this->table_name,
			array(
				'status'          => $status,
				'retries'         => $retries,
				'next_attempt_at' => $next_attempt,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => absint( $item->id ) ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$details = wp_json_encode(
			array_merge(
				array(
					'message' => $failed ? 'Queued email failed permanently.' : 'Queued email will be retried.',
					'retries' => $retries,
				),
				$this->get_attachment_details( maybe_unserialize( $item->attachments ) )
			)
		);

		$this->logger->update_status(
			$item->hash,
			$failed ? 'failed' : 'queued',
			$error ? $error : __( 'Queued email delivery failed.', 'wpfa-mailconnect' ),
			$details
		);
	}

	/**
	 * Normalizes wp_mail args.
	 *
	 * @param array $args Raw args.
	 * @return array Normalized args.
	 */
	private function normalize_mail_args( $args ) {
		return wp_parse_args(
			$args,
			array(
				'to'          => '',
				'subject'     => '',
				'message'     => '',
				'headers'     => array(),
				'attachments' => array(),
			)
		);
	}

	/**
	 * Formats recipient email addresses.
	 *
	 * @param mixed $recipients Recipients.
	 * @return string
	 */
	private function format_recipients( $recipients ) {
		if ( is_array( $recipients ) ) {
			return implode( ', ', $recipients );
		}
		return (string) $recipients;
	}

	/**
	 * Checks if mail args are HTML.
	 *
	 * @param array $args Mail args.
	 * @return bool
	 */
	private function is_html_content( $args ) {
		$headers = isset( $args['headers'] ) ? $args['headers'] : array();
		if ( is_array( $headers ) ) {
			$headers = implode( "\n", $headers );
		}

		return false !== stripos( (string) $headers, 'Content-Type: text/html' );
	}

	/**
	 * Builds safe attachment metadata for logs.
	 *
	 * @param mixed $attachments wp_mail attachments.
	 * @return array
	 */
	private function get_attachment_details( $attachments ) {
		if ( empty( $attachments ) ) {
			return array(
				'attachments_included' => false,
				'attachment_count'     => 0,
			);
		}

		if ( is_string( $attachments ) ) {
			$attachments = preg_split( '/\r\n|\r|\n/', $attachments );
		}

		if ( ! is_array( $attachments ) ) {
			$attachments = array( $attachments );
		}

		$attachments = array_filter(
			$attachments,
			function( $attachment ) {
				return is_scalar( $attachment ) && '' !== trim( (string) $attachment );
			}
		);

		$count = count( $attachments );

		return array(
			'attachments_included' => $count > 0,
			'attachment_count'     => $count,
		);
	}
}
