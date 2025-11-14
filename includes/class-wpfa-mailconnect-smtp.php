<?php

/**
 * SMTP configuration and management class.
 *
 * Handles SMTP settings registration, admin UI rendering,
 * test email functionality, log cleanup scheduling, and PHPMailer configuration.
 *
 * @author Ubayed Bin Sufian
 * @since 1.0.0
 * @version 1.2.0 (Updated to use deterministic hash for logging)
 */
class Wpfa_Mailconnect_SMTP {

	/**
	 * WP Cron event hook for log cleanup.
	 *
	 * @since 1.1.0
	 */
	const CLEANUP_CRON_HOOK = 'wpfa_mailconnect_cleanup_logs';

    /**
     * Action hook fired after wp_mail successfully sends.
     *
     * This is used to update the log status from 'pending' to 'success'.
     *
     * @since 1.2.0
     */
    const MAIL_SENT_SUCCESS_HOOK = 'wpfa_mailconnect_mail_sent';

    /**
     * Plugin id and version from main class
     */
    private $plugin_name;
    private $version;

    /**
     * Fields definition
     */
    private $fields = array();

    /**
     * Logger instance
     */
    private $logger;    

    /**
     * Stores the hash of the currently processing email to link success/failure status updates.
     * * @since 1.2.0
     * @access private
     * @var string $current_mail_hash
     */
    private $current_mail_hash = '';

	/**
	 * Stores the current email attributes for success tracking.
	 *
	 * @since 1.2.0
	 * @var array $current_mail_atts
	 */
	private $current_mail_atts = array();
    
    /**
     * Track logged email hashes for successful sends within a single request.
     * Used for the success hook only.
     *
     * @since 1.2.0
     * @var array $logged_hashes
     */
    private static $logged_hashes = array();

    /**
     * Stores the error capture handler closure for later removal.
     *
     * @since 1.0.0
     * @access protected
     * @var callable|null $error_capture_handler_closure
     */
    protected $error_capture_handler_closure = null;

    /**
     * Initialize the SMTP configuration class.
     *
     * @param string $plugin_name The plugin identifier.
     * @param string $version The plugin version.
     */
    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

		// Existing SMTP fields, plus new logging fields for v1.1.0
		$this->fields = array(
			'smtp_user'            => array( 'label' => 'SMTP User Email', 'default' => 'youremail@example.com', 'type' => 'text' ),
			'smtp_pass'            => array( 'label' => 'SMTP Password/App Key', 'default' => 'yourpassword', 'type' => 'password' ),
			'smtp_host'            => array( 'label' => 'SMTP Host', 'default' => 'smtp.gmail.com', 'type' => 'text' ),
			'smtp_from'            => array( 'label' => 'SMTP From Email Address', 'default' => 'youremail@example.com', 'type' => 'text' ),
			'smtp_name'            => array( 'label' => 'SMTP User Name', 'default' => get_bloginfo( 'name' ), 'type' => 'text' ),
			'smtp_port'            => array( 'label' => 'SMTP Port', 'default' => '587', 'type' => 'number' ),
			'smtp_secure'          => array( 'label' => 'Encryption', 'default' => 'tls', 'type' => 'select', 'options' => array( 'tls' => 'TLS (Recommended)', 'ssl' => 'SSL', '' => 'None' ) ),
			'smtp_auth'            => array( 'label' => 'Authentication Required?', 'default' => '1', 'type' => 'select', 'options' => array( '1' => 'Yes', '0' => 'No' ) ),
			'enable_log'           => array( 'label' => 'Enable Email Logging', 'default' => '1', 'type' => 'checkbox', 'description' => 'Uncheck this to stop logging all emails sent through WordPress.' ),
			'log_retention_days'   => array( 'label' => 'Log Retention Days', 'default' => '90', 'type' => 'number', 'description' => 'Automatically delete logs older than this many days (0 for never).' ),
		);

		// Assuming Wpfa_Mailconnect_Logger class is autoloaded or required elsewhere.
        $this->logger = new Wpfa_Mailconnect_Logger();
        
        // Store mail atts for success tracking
		add_filter( 'wp_mail', array( $this, 'track_email_result' ), 999, 1 );
    }

    /* --- Admin menu / settings registration --- */

	/**
	 * Register the plugin options page under Settings.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_options_page(
			__( 'SMTP Email Settings', 'wpfa-mailconnect' ),
			__( 'SMTP Config', 'wpfa-mailconnect' ),
			'manage_options',
			'smtp-config',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Initialize and register plugin settings, sections, and fields.
	 *
	 * Registers the settings group, adds sections for credentials, logging, and testing,
	 * then registers each configured field.
	 *
	 * @return void
	 */
	public function settings_init() {
		register_setting( 'smtp_settings_group', 'smtp_options', array( 'sanitize_callback' => array( $this, 'sanitize_smtp_options' ) ) );

		// Core SMTP Credentials Section
		add_settings_section(
			'smtp_main_section',
			__( 'Core SMTP Credentials', 'wpfa-mailconnect' ),
			array( $this, 'section_callback' ),
			'smtp-config'
		);

		// Register core SMTP fields
        foreach ( $this->fields as $id => $args ) {
            // Skip logging fields in the main section
            if ( 'enable_log' === $id || 'log_retention_days' === $id ) {
                continue;
            }
            add_settings_field(
                $id,
                $args['label'],
                array( $this, 'render_field' ),
                'smtp-config',
                'smtp_main_section',
                array_merge( $args, array( 'id' => $id ) )
            );
        }

		// Email Logging & Retention Section
		add_settings_section(
			'smtp_logging_section',
			__( 'Email Logging & Retention', 'wpfa-mailconnect' ),
			array( $this, 'logging_section_callback' ),
			'smtp-config'
		);
		
		// Register logging fields
		add_settings_field(
			'enable_log',
			$this->fields['enable_log']['label'],
			array( $this, 'render_field' ),
			'smtp-config',
			'smtp_logging_section',
			array_merge( $this->fields['enable_log'], array( 'id' => 'enable_log' ) )
		);
		add_settings_field(
			'log_retention_days',
			$this->fields['log_retention_days']['label'],
			array( $this, 'render_field' ),
			'smtp-config',
			'smtp_logging_section',
			array_merge( $this->fields['log_retention_days'], array( 'id' => 'log_retention_days' ) )
		);

		// Send Test Email Section
		add_settings_section(
			'smtp_test_section',
			__( 'Send a Test Email', 'wpfa-mailconnect' ),
			array( $this, 'test_section_callback' ),
			'smtp-config'
		);
	}

	/**
	 * Callback for the main SMTP settings section description.
	 *
	 * @return void
	 */
	public function section_callback() {
		echo '<p>' . esc_html__( 'Enter your SMTP credentials below. These settings will override the default WordPress email behavior.', 'wpfa-mailconnect' ) . '</p>';
	}
	
	/**
	 * Callback for the new logging settings section description.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function logging_section_callback() {
		echo '<p>' . esc_html__( 'Control how emails are logged and manage data retention.', 'wpfa-mailconnect' ) . '</p>';
	}

	/**
	 * Sanitizes and validates the input for the smtp_options settings group.
	 *
	 * Ensures that unchecked checkbox fields are set to '0' as their value is
	 * omitted from the POST data when not using the hidden '0' field technique.
	 *
	 * @param array $input The submitted array of options.
	 * @return array The sanitized array of options.
	 */
	public function sanitize_smtp_options( $input ) {
		$output = get_option( 'smtp_options', array() );
		
		// Merge submitted data with current data to ensure non-submitted fields are retained
		$output = array_merge( $output, $input );

		// Check all fields defined in the class
		foreach ( $this->fields as $id => $args ) {
			// If the field is a checkbox, we must explicitly set '0' if it's missing from submission
			if ( 'checkbox' === $args['type'] ) {
				if ( ! isset( $input[ $id ] ) ) {
					// Checkbox was unchecked and not submitted, force value to '0'
					$output[ $id ] = '0';
				} else {
					// Checkbox was submitted (must be '1'), sanitize its value
					$output[ $id ] = $input[ $id ] === '1' ? '1' : '0';
				}
			} else {
				// Handle sanitization for other field types (optional but recommended)
				if ( isset( $input[ $id ] ) ) {
					switch ( $args['type'] ) {
						case 'text':
						case 'password':
						case 'select':
							$output[ $id ] = sanitize_text_field( $input[ $id ] );
							break;
						case 'number':
							$output[ $id ] = absint( $input[ $id ] );
							break;
						// Add other types as needed
					}
				}
			}
		}

		return $output;
	}

	/**
	 * Callback for the test section description.
	 *
	 * @return void
	 */
	public function test_section_callback() {
		echo '<p>' . esc_html__( 'Verify your settings by sending a test email. Use the default email or enter a custom address.', 'wpfa-mailconnect' ) . '</p>';
	}

	/**
	 * Renders an individual settings field.
	 *
	 * Supports text, password, number, select, and checkbox field types.
	 *
	 * @param array $args Field definition and metadata (includes 'id', 'type', 'default', etc.).
	 * @return void
	 */
    public function render_field( $args ) {
        $options = get_option( 'smtp_options', array() );
        $id      = sanitize_key( $args['id'] );
		
		// Check type first, then set value once
        if ( isset( $args['type'] ) && 'password' === $args['type'] ) {
			// Password fields: use saved value or empty string (never show default)
            $value = isset( $options[ $id ] ) ? $options[ $id ] : '';
		} else {
			// All other fields: use saved value or default
			$value = isset( $options[ $id ] ) ? $options[ $id ] : $args['default'];
        } 

        if ( isset( $args['type'] ) && 'select' === $args['type'] ) {
            echo '<select id="' . esc_attr( $id ) . '" name="smtp_options[' . esc_attr( $id ) . ']">';
            foreach ( $args['options'] as $val => $label ) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr( $val ),
                    selected( $value, $val, false ),
                    esc_html( $label )
                );
            }
            echo '</select>';
        } elseif ( isset( $args['type'] ) && 'checkbox' === $args['type'] ) {
            // Checkbox handling: value is 1 if checked, 0 if not set/unchecked
            $checked = ( '1' === $value || true === $value );
            printf(
                '<input type="checkbox" id="%s" name="smtp_options[%s]" value="1" %s />',
                esc_attr( $id ),
                esc_attr( $id ),
                checked( $checked, true, false )
            );
        } else {
                // text/password/number
                printf(
                    '<input type="%s" id="%s" name="smtp_options[%s]" value="%s" class="regular-text" />',
                    esc_attr( $args['type'] ),
                    esc_attr( $id ),
                    esc_attr( $id ),
                    esc_attr( $value )
                );
            }

        if ( isset( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

	/**
	 * Renders the plugin settings page.
	 *
	 * Checks user capabilities, outputs the settings form and the test email form.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SMTP Email Configuration', 'wpfa-mailconnect' ); ?></h1>
			<?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'smtp_settings_group' );
                do_settings_sections( 'smtp-config' );
                submit_button( __( 'Save SMTP Settings', 'wpfa-mailconnect' ) );
                ?>
            </form>

			<hr style="margin: 20px 0; border: 0; border-top: 1px solid #ccc;">

            <h2><?php esc_html_e( 'Test Email', 'wpfa-mailconnect' ); ?></h2>
            <?php $this->test_email_form(); ?>

        </div>
        <?php
    }
	
	/* --- Cron scheduling for log retention --- */
	
	/**
	 * Schedules the daily log cleanup event.
	 *
	 * Should be called upon plugin activation or settings update to ensure cron is running.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function schedule_log_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_CRON_HOOK );
		}
	}

	/**
	 * Unschedules the daily log cleanup event.
	 *
	 * Should be called upon plugin deactivation.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function unschedule_log_cleanup() {
		$timestamp = wp_next_scheduled( self::CLEANUP_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_CRON_HOOK );
		}
	}

	/**
	 * Executes the log cleanup based on the retention setting.
	 *
	 * Hooked to the CLEANUP_CRON_HOOK event.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function do_log_cleanup() {
		// Ensure logging is enabled and retention is set
		$options = get_option( 'smtp_options', array() );
        // Default to false if not set, but ensure it's a boolean check on the saved value ('1' or '0').
		$enabled = isset( $options['enable_log'] ) ? (bool) $options['enable_log'] : false;
		$days 	 = isset( $options['log_retention_days'] ) ? absint( $options['log_retention_days'] ) : 90;

		// Only proceed if logging is enabled AND a retention period > 0 is set
		if ( $enabled && $days > 0 ) {
			$this->logger->clear_old_logs( $days );
		}
	}


    /* --- Test email form & handler --- */

    /**
     * Outputs the HTML form for sending a test email using the configured SMTP settings.
     *
     * This form allows the user to specify a recipient email address and submit a test email.
     * The default recipient is the SMTP user email or the WordPress admin email.
     *
     * @return void
     */
    public function test_email_form() {
        $options    = get_option( 'smtp_options', array() );
        $default_to = isset( $options['smtp_user'] ) ? $options['smtp_user'] : get_option( 'admin_email' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="smtp_send_test" />
            <?php wp_nonce_field( 'smtp_test_email_nonce', 'smtp_nonce_field' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="smtp_test_recipient"><?php esc_html_e( 'Recipient Email', 'wpfa-mailconnect' ); ?></label></th>
                    <td>
                        <input type="email" id="smtp_test_recipient" name="smtp_test_recipient" value="<?php echo esc_attr( $default_to ); ?>" class="regular-text" required />
                        <p class="description"><?php esc_html_e( 'The email address the test email will be sent to.', 'wpfa-mailconnect' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Send Test Email', 'wpfa-mailconnect' ), 'secondary', 'smtp_send_test_button' ); ?>
        </form>
        <?php
    }

    /**
     * Handles the test email form submission.
     *
     * Validates user permissions and nonce, sends a test email using the configured SMTP settings,
     * adds a settings error message based on the result, and redirects back to the referring page.
     *
     * @return void
     */
    public function handle_test_email() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access. You do not have permission to perform this action.', 'wpfa-mailconnect' ) );
        }
        if ( ! isset( $_POST['smtp_nonce_field'] ) || ! wp_verify_nonce( $_POST['smtp_nonce_field'], 'smtp_test_email_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wpfa-mailconnect' ) );
        }

        if ( ! isset( $_POST['smtp_test_recipient'] ) ) {
            add_settings_error( 'smtp_messages', 'email_missing', esc_html__( 'Error: Recipient email address is missing.', 'wpfa-mailconnect' ), 'error' );
            wp_safe_redirect( add_query_arg( 'settings-updated', 'false', wp_get_referer() ) );
            exit;
        }
        $recipient = sanitize_email( $_POST['smtp_test_recipient'] );
        if ( ! is_email( $recipient ) ) {
            add_settings_error( 'smtp_messages', 'email_invalid', esc_html__( 'Error: Please enter a valid recipient email address.', 'wpfa-mailconnect' ), 'error' );
            wp_safe_redirect( add_query_arg( 'settings-updated', 'false', wp_get_referer() ) );
            exit;
        }

		$subject = sprintf(
			/* translators: %s: Blog name */
			__( 'SMTP Test Email from %s', 'wpfa-mailconnect' ),
			get_bloginfo( 'name' )
		);
		$body    = sprintf(
			/* translators: %s: Plugin name */
			__( 'Congratulations! If you receive this email, your SMTP settings are configured correctly using the %s plugin.', 'wpfa-mailconnect' ),
			esc_html( $this->plugin_name )
		);
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        // --- NEW LOGGING: INSERT PENDING ---
        $args = array(
            'to'      => $recipient,
            'subject' => $subject,
            'message' => $body,
            'headers' => $headers,
        );
        $hash = $this->get_normalized_hash( $args );
        
        // Log as 'pending' using the new API.
        // The recipient should be logged as a string for simplicity in test context.
        $this->logger->insert_pending(
            $hash,
            $recipient,
            $subject,
            $body,
			'',
            wp_json_encode( $headers )
        );
        
        // --- END NEW LOGGING: INSERT PENDING ---

		// Capture PHPMailer errors by registering a temporary handler.
		$error_message = '';
		
		// Define and store the closure in the class property for reliable removal.
		$this->error_capture_handler_closure = function( $error ) use ( &$error_message ) {
			// Extract PHPMailer error info if available, otherwise use general error message.
			$error_message = $error->get_error_message();

			// Fallback to global PHPMailer object if specific error message is generic
			global $phpmailer;
			if ( empty( $error_message ) || strpos( $error_message, 'could not be sent' ) !== false ) {
				if ( ! empty( $phpmailer->ErrorInfo ) ) {
					$error_message = $phpmailer->ErrorInfo;
				}
			}
		};

        // Add the action using the stored closure property.
        add_action( 'wp_mail_failed', $this->error_capture_handler_closure );

        $success = wp_mail( $recipient, $subject, $body, $headers );
        
        // Reliably remove the action using the stored closure property.
        remove_action( 'wp_mail_failed', $this->error_capture_handler_closure );

		$this->error_capture_handler_closure = null; // Clean up the reference to prevent memory leaks

        // If wp_mail failed and the handler didn't capture the error, check PHPMailer directly as a final fallback.
        if ( ! $success && empty( $error_message ) ) {
            global $phpmailer;
            if ( ! empty( $phpmailer->ErrorInfo ) ) {
                $error_message = $phpmailer->ErrorInfo;
            }
        }

		// --- NEW LOGGING: UPDATE STATUS ---
        if ( $success ) {
            // Update log entry from 'pending' to 'success'.
            $this->logger->update_status( $hash, 'success', '' );
        } else {
            // Update log entry from 'pending' to 'failed'.
            $this->logger->update_status( $hash, 'failed', $error_message );
        }
        // --- END NEW LOGGING: UPDATE STATUS ---
        
		if ( $success ) {
			$message = sprintf(
				/* translators: %s: Recipient email address */
				__( 'Success! Test email sent to %s.', 'wpfa-mailconnect' ),
				esc_html( $recipient )
			);
			add_settings_error( 'smtp_messages', 'email_success', $message, 'updated' );
		} else {
			if ( ! empty( $error_message ) ) {
				$display_error = sprintf(
					/* translators: %s: The detailed error message from PHPMailer */
					__( 'Failed to send test email. Error: %s', 'wpfa-mailconnect' ),
					esc_html( $error_message )
				);
			} else {
				$display_error = esc_html__( 'Failed to send test email. Check your credentials, host, and port settings.', 'wpfa-mailconnect' );
			}
			add_settings_error( 'smtp_messages', 'email_fail', $display_error, 'error' );
		}

        wp_safe_redirect( add_query_arg( 'settings-updated', $success ? 'true' : 'false', wp_get_referer() ) );
        exit;
    }

    /* --- PHPMailer override --- */

    /**
     * Overrides PHPMailer settings with SMTP options from the plugin.
     *
     * @param PHPMailer $phpmailer The PHPMailer instance to configure.
     * @return void
     */
    public function phpmailer_override( $phpmailer ) {
        $options = get_option( 'smtp_options', array() );

		$user   = isset( $options['smtp_user'] ) ? trim( $options['smtp_user'] ) : '';
		$pass   = isset( $options['smtp_pass'] ) ? $options['smtp_pass'] : '';
		$host   = isset( $options['smtp_host'] ) ? trim( $options['smtp_host'] ) : 'localhost';
		$port   = isset( $options['smtp_port'] ) ? (int) $options['smtp_port'] : 25;
		// Validate port range (1-65535)
		if ( $port < 1 || $port > 65535 ) {
			$port = 25; // fallback to default SMTP port
		}
		$secure = isset( $options['smtp_secure'] ) ? $options['smtp_secure'] : '';
		$auth   = isset( $options['smtp_auth'] ) ? (bool) $options['smtp_auth'] : false;
		$from   = isset( $options['smtp_from'] ) ? trim( $options['smtp_from'] ) : get_option( 'admin_email' );
		$name   = isset( $options['smtp_name'] ) ? $options['smtp_name'] : get_bloginfo( 'name' );

		// Only apply SMTP settings if the necessary credentials are provided
		if ( ! empty( $user ) && ! empty( $host ) ) {
			$phpmailer->isSMTP();
			$phpmailer->Host       = $host;
			$phpmailer->SMTPAuth   = $auth;
			$phpmailer->Port       = $port;
			$phpmailer->Username   = $user;
			$phpmailer->Password   = $pass;
			$phpmailer->SMTPSecure = $secure;

			// Validate 'From' email address before assignment
			if ( filter_var( $from, FILTER_VALIDATE_EMAIL ) ) {
				$phpmailer->From = $from;
			} else {
				// Fallback to default WordPress email if configured 'From' is invalid
				$phpmailer->From = get_option( 'admin_email' );
				error_log( 'WPFA MailConnect SMTP: Invalid "From" email address provided in settings: ' . $from );
			}
			$phpmailer->FromName   = $name;
		}

        // Disable debug output
        $phpmailer->SMTPDebug = 0;
    }
    
    /**
     * Calculates a deterministic hash for an email based on its core components.
     *
     * This hash is used for de-duplication and linking the 'pending' log entry 
     * to the 'success'/'failed' update.
     *
     * @since 1.2.0
     * @param array $args Arguments passed to wp_mail (to, subject, message, headers, attachments).
     * @return string The MD5 hash string.
     */
    private function get_normalized_hash( $args ) {
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
            'headers' => $headers_raw, // Keep raw headers for completeness in hash
        );

        // Use wp_json_encode for a consistent string representation of the array
        return md5( wp_json_encode( $normalized_data ) );
    }

	/* --- Email Logging Hooks --- */

	/**
	 * Log email using wp_mail filter before it attempts to send.
	 * Inserts a log entry with status 'pending' and stores the hash for later update.
	 *
	 * @param array $args Arguments passed to wp_mail (to, subject, message, headers, attachments).
	 * @return array The original arguments, unchanged.
	 */
	public function log_email_on_send( $args ) {
		$options = get_option( 'smtp_options', array() );
		$enabled = isset( $options['enable_log'] ) ? (bool) $options['enable_log'] : false; // Default to disabled for safety

		// Early exit if logging is disabled
		if ( ! $enabled ) {
			return $args;
		}

		$to      = isset( $args['to'] ) ? $args['to'] : '';
		$subject = isset( $args['subject'] ) ? $args['subject'] : 'No Subject';
		$message = isset( $args['message'] ) ? $args['message'] : '';
		$headers = isset( $args['headers'] ) ? $args['headers'] : '';
		
        // Generate deterministic hash
		$hash = $this->get_normalized_hash( $args );
        
        // Store the hash in the instance property for access in the success/failure hooks
        $this->current_mail_hash = $hash;
        
		// Handle 'to' field (convert array to comma-separated string for logging)
		$to_string = is_array( $to ) ? implode( ', ', $to ) : $to;
        
        // Handle 'headers' field (convert array to JSON string)
        $headers_json = is_array( $headers ) ? wp_json_encode( $headers ) : $headers;
		
		// Insert log entry with 'pending' status.
		// insert_pending handles the unique hash check (de-duplication) at the DB level.
		$is_newly_logged = $this->logger->insert_pending(
			$hash,
			$to_string,
			$subject,
			$message,
			'',
			$headers_json
		);
        
        // Store the hash if it was a new entry, for the success hook.
        if ( $is_newly_logged ) {
            self::$logged_hashes[ $hash ] = time();
        }

		// MUST return the args unchanged for wp_mail to work
		return $args;
	}

	/**
	 * Wrapper to track email success via return value check.
	 * 
	 * @param bool $result The result of wp_mail.
	 * @param array $args The original wp_mail arguments.
	 * @return bool
	 */
	public function track_email_result( $atts ) {
		// Store atts for potential success logging
		$this->current_mail_atts = $atts;
		return $atts;
	}

    /**
	 * Updates the log entry status to 'success'.
     *
     * This is called via a custom action added to the 'wp_mail' function 
     * immediately after it returns true.
	 *
     * @since 1.2.0
	 * @param array $args Arguments originally passed to wp_mail.
	 * @return void
	 */
    public function log_email_success( $args ) {
        $hash = $this->get_normalized_hash( $args );

        // Only proceed if this hash was recorded as 'pending' in the current request
        if ( isset( self::$logged_hashes[ $hash ] ) ) {
            // Update the status using the hash
            $this->logger->update_status( $hash, 'success', '' );

            // Clean up the hash to prevent accidental re-update
            unset( self::$logged_hashes[ $hash ] );
        }
    }

	/**
	 * Log failed emails.
	 *
	 * This hook fires after wp_mail fails and attempts to update the log status to 'failed'.
	 *
	 * @param WP_Error $wp_error The error object returned by wp_mail().
	 * @return void
	 */
	public function log_email_failure( $wp_error ) {
		$options = get_option( 'smtp_options', array() );
		$enabled = isset( $options['enable_log'] ) ? (bool) $options['enable_log'] : false;

		// Early exit if logging is disabled
		if ( ! $enabled ) {
			return;
		}

		// Use stored atts from the filter or fall back to error data
		if ( ! empty( $this->current_mail_atts ) ) {
			$hash = $this->get_normalized_hash( $this->current_mail_atts );
		} else {
			$error_data = $wp_error->get_error_data();
			$hash = $this->current_mail_hash;
			
			if ( empty( $hash ) && is_array( $error_data ) ) {
				// Attempt to derive the hash from error data if it was somehow lost
				$args = array(
					'to'      => isset( $error_data['to'] ) ? $error_data['to'] : '',
					'subject' => isset( $error_data['subject'] ) ? $error_data['subject'] : '',
					'message' => isset( $error_data['message'] ) ? $error_data['message'] : '',
					'headers' => isset( $error_data['headers'] ) ? $error_data['headers'] : '',
				);
				$hash = $this->get_normalized_hash( $args );
			}
		}

        // Only proceed if we have a hash
        if ( ! empty( $hash ) ) {
            
            // Clean up the hash from the success tracker to ensure only one status update occurs
            unset( self::$logged_hashes[ $hash ] );

            // Update the existing 'pending' log entry to 'failed'.
            $this->logger->update_status(
                $hash,
                'failed',
                $wp_error->get_error_message()
            );
        }
	}
}