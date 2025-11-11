<?php

/**
 * SMTP configuration and management class.
 *
 * Handles SMTP settings registration, admin UI rendering,
 * test email functionality, log cleanup scheduling, and PHPMailer configuration.
 *
 * @author Ubayed Bin Sufian
 * @since 1.0.0
 * @version 1.1.0
 */
class Wpfa_Mailconnect_SMTP {

	/**
	 * WP Cron event hook for log cleanup.
	 *
	 * @since 1.1.0
	 */
	const CLEANUP_CRON_HOOK = 'wpfa_mailconnect_cleanup_logs';

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
     * Track logged emails to prevent duplicates (when using the 'wp_mail' filter)
     */
    private static $logged_emails = array();

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
					$output[ $id ] = $input[ $id ] == '1' ? '1' : '0';
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

        // If wp_mail failed and the handler didn't capture the error, check PHPMailer directly as a final fallback.
        if ( ! $success && empty( $error_message ) ) {
            global $phpmailer;
            if ( ! empty( $phpmailer->ErrorInfo ) ) {
                $error_message = $phpmailer->ErrorInfo;
            }
        }

		// Log the test email attempt. This intentionally bypasses the enable_log check since it's an admin test.
        $this->logger->log_email(
            $recipient,
            $subject,
            $body,
            $success ? 'success' : 'failed',
            $error_message
        );

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

	/* --- Email Logging Hooks --- */

	/**
	 * Log email using wp_mail filter before it attempts to send.
	 * Logged as 'success' here, corrected to 'failed' if wp_mail_failed fires later.
	 *
	 * @param array $args Arguments passed to wp_mail (to, subject, message, headers, attachments).
	 * @return array The original arguments, unchanged.
	 */
	public function log_email_on_send( $args ) {
		$options = get_option( 'smtp_options', array() );
		$enabled = isset( $options['enable_log'] ) ? (bool) $options['enable_log'] : true; // Default to enabled

		// Early exit if logging is disabled
		if ( ! $enabled ) {
			return $args;
		}

		// Extract email data from args
		$to      = isset( $args['to'] ) ? $args['to'] : '';
		$subject = isset( $args['subject'] ) ? $args['subject'] : 'No Subject';
		$message = isset( $args['message'] ) ? $args['message'] : '';
		
		// Handle 'to' field - can be string or array
		$to_string = '';
		if ( is_array( $to ) ) {
			$to_string = implode( ', ', $to );
		} else {
			$to_string = $to;
		}
		
		// Create hash to prevent duplicate logging (based on recipient and subject)
		$hash = md5( $to_string . $subject );
		
		// Only log if we haven't logged this exact email recently
		if ( ! isset( self::$logged_emails[$hash] ) ) {
			self::$logged_emails[ $hash ] = time(); // Store time to potentially implement time-based cleanup
			
			// Log with 'success' status (assumed to succeed until failure hook runs)
			$this->logger->log_email(
				$to_string,
				$subject,
				$message,
				'success',
				''
			);
			
			// Clean up old hashes (keep only the 20 most recent hashes to prevent memory bloat)
			if ( count( self::$logged_emails ) > 20 ) {
				// Only keep the most recent 20 (sorted by key as keys are md5 hashes, or sort by time if implemented)
				// Given keys are hashes, simply use array_slice on the end for simplicity in this pattern.
				self::$logged_emails = array_slice( self::$logged_emails, -20, 20, true );
			}
		}
		
		// MUST return the args unchanged for wp_mail to work
		return $args;
	}

	/**
	 * Log failed emails.
	 *
	 * This hook fires after wp_mail fails and attempts to update the log status.
	 *
	 * @param WP_Error $wp_error The error object returned by wp_mail().
	 * @return void
	 */
	public function log_email_failure( $wp_error ) {
		$options = get_option( 'smtp_options', array() );
		$enabled = isset( $options['enable_log'] ) ? (bool) $options['enable_log'] : true; // Default to enabled

		// Early exit if logging is disabled
		if ( ! $enabled ) {
			return;
		}

        $error_data = $wp_error->get_error_data();

		$to      = 'Unknown';
		$subject = 'Unknown';
		$message = '';
		
		if ( is_array( $error_data ) ) {
			if ( isset( $error_data['to'] ) ) {
				$to = is_array( $error_data['to'] ) ? implode( ', ', $error_data['to'] ) : $error_data['to'];
			}
			$subject = isset( $error_data['subject'] ) ? $error_data['subject'] : 'Unknown';
			$message = isset( $error_data['message'] ) ? $error_data['message'] : '';
		}
		
		// The original log entry created by log_email_on_send needs to be found and updated.
		// Since the Logger class is responsible for this logic, we call log_email with the failure status.
		// It is assumed the Logger implementation will find the most recent matching 'success' log entry
		// based on recipient/subject and update its status to 'failed' with the error message.
		$this->logger->log_email(
			$to,
			$subject,
			$message,
			'failed',
			$wp_error->get_error_message()
		);
	}
}