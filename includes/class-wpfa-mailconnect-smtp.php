<?php

/**
 * SMTP configuration and management class.
 *
 * Handles SMTP settings registration, admin UI rendering,
 * test email functionality, and PHPMailer configuration.
 *
 * @author Ubayed Bin Sufian
 * @since 1.0.0
 */
class Wpfa_Mailconnect_SMTP {

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
     * Track logged emails to prevent duplicates
     */
    private static $logged_emails = array();

    /**
     * Initialize the SMTP configuration class.
     *
     * @param string $plugin_name The plugin identifier.
     * @param string $version The plugin version.
     */
    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        $this->fields = array(
            'smtp_user'   => array( 'label' => 'SMTP User Email', 'default' => 'youremail@example.com', 'type' => 'text' ),
            'smtp_pass'   => array( 'label' => 'SMTP Password/App Key', 'default' => 'yourpassword', 'type' => 'password' ),
            'smtp_host'   => array( 'label' => 'SMTP Host', 'default' => 'smtp.gmail.com', 'type' => 'text' ),
            'smtp_from'   => array( 'label' => 'SMTP From Email Address', 'default' => 'youremail@example.com', 'type' => 'text' ),
            'smtp_name'   => array( 'label' => 'SMTP User Name', 'default' => get_bloginfo( 'name' ), 'type' => 'text' ),
            'smtp_port'   => array( 'label' => 'SMTP Port', 'default' => '587', 'type' => 'number' ),
            'smtp_secure' => array( 'label' => 'Encryption', 'default' => 'tls', 'type' => 'select', 'options' => array( 'tls' => 'TLS (Recommended)', 'ssl' => 'SSL', '' => 'None' ) ),
            'smtp_auth'   => array( 'label' => 'Authentication Required?', 'default' => '1', 'type' => 'select', 'options' => array( '1' => 'Yes', '0' => 'No' ) ),
        );

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
	 * Registers the settings group, adds the core SMTP credentials section
	 * and the test email section, then registers each configured field.
	 *
	 * @return void
	 */
	public function settings_init() {
		register_setting( 'smtp_settings_group', 'smtp_options' );

		add_settings_section(
			'smtp_main_section',
			__( 'Core SMTP Credentials', 'wpfa-mailconnect' ),
			array( $this, 'section_callback' ),
			'smtp-config'
		);

        foreach ( $this->fields as $id => $args ) {
            add_settings_field(
                $id,
                $args['label'],
                array( $this, 'render_field' ),
                'smtp-config',
                'smtp_main_section',
                array_merge( $args, array( 'id' => $id ) )
            );
        }

		add_settings_section(
			'smtp_test_section',
			__( 'Send a Test Email', 'wpfa-mailconnect' ),
			array( $this, 'test_section_callback' ),
			'smtp-config'
		);
	}

	/**
	 * Callback for the main settings section description.
	 *
	 * Outputs a short description displayed above the SMTP credentials fields.
	 *
	 * @return void
	 */
	public function section_callback() {
		echo '<p>' . esc_html__( 'Enter your SMTP credentials below. These settings will override the default WordPress email behavior.', 'wpfa-mailconnect' ) . '</p>';
	}

	/**
	 * Callback for the test section description.
	 *
	 * Outputs a short description displayed above the test email form.
	 *
	 * @return void
	 */
	public function test_section_callback() {
		echo '<p>' . esc_html__( 'Verify your settings by sending a test email. Use the default email or enter a custom address.', 'wpfa-mailconnect' ) . '</p>';
	}

	/**
	 * Renders an individual settings field.
	 *
	 * Supports text, password, number and select field types. Retrieves the
	 * current option value and outputs the corresponding input element.
	 *
	 * @param array $args Field definition and metadata (includes 'id', 'type', 'default', etc.).
	 * @return void
	 */
	public function render_field( $args ) {
		$options = get_option( 'smtp_options', array() );
		$id      = sanitize_key( $args['id'] );
		if ( isset( $args['type'] ) && 'password' === $args['type'] ) {
			// Always leave password blank by default for security
			$value = isset( $options[ $id ] ) ? $options[ $id ] : '';
		} else {
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
            return;
        }

        // text/password/number
        printf(
            '<input type="%s" id="%s" name="smtp_options[%s]" value="%s" class="regular-text" />',
            esc_attr( $args['type'] ),
            esc_attr( $id ),
            esc_attr( $id ),
            esc_attr( $value )
        );
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
			/* translators: %s: Blog name */
			__( 'Congratulations! If you receive this email, your SMTP settings are configured correctly using the %s plugin.', 'wpfa-mailconnect' ),
			get_bloginfo( 'name' )
		);
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        // Capture PHPMailer errors by registering a temporary handler.
        $error_message = '';
        
        // Store the closure in a variable so we can remove it later.
        $error_capture_handler = function( $error ) use ( &$error_message ) {
            $error_message = $error->get_error_message();
        };

        // Add the temporary action hook
        add_action( 'wp_mail_failed', $error_capture_handler );

        $success = wp_mail( $recipient, $subject, $body, $headers );
        
        // Crucial: Remove the action hook immediately after wp_mail() is done
        // to prevent duplicate hooks from accumulating on subsequent submissions.
        remove_action( 'wp_mail_failed', $error_capture_handler );
        
        // If wp_mail failed and the handler didn't capture the error, check PHPMailer directly.
        if ( ! $success && empty( $error_message ) ) {
            global $phpmailer;
            if ( ! empty( $phpmailer->ErrorInfo ) ) {
                $error_message = $phpmailer->ErrorInfo;
            }
        }

        // Log the test email attempt
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

        $user   = isset( $options['smtp_user'] ) ? $options['smtp_user'] : '';
        $pass   = isset( $options['smtp_pass'] ) ? $options['smtp_pass'] : '';
        $host   = isset( $options['smtp_host'] ) ? $options['smtp_host'] : 'localhost';
        $port   = isset( $options['smtp_port'] ) ? (int) $options['smtp_port'] : 25;
        // Validate port range (1-65535)
        if ( $port < 1 || $port > 65535 ) {
            $port = 25; // fallback to default SMTP port
        }
        $secure = isset( $options['smtp_secure'] ) ? $options['smtp_secure'] : '';
        $auth   = isset( $options['smtp_auth'] ) ? (bool) $options['smtp_auth'] : false;
        $from   = isset( $options['smtp_from'] ) ? $options['smtp_from'] : get_option( 'admin_email' );
        $name   = isset( $options['smtp_name'] ) ? $options['smtp_name'] : get_bloginfo( 'name' );

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
                // Optionally set a default or handle invalid email
                $phpmailer->From = get_option( 'admin_email' );
                // Optionally log the error or notify admin
                error_log( 'WPFA MailConnect SMTP: Invalid "From" email address provided: ' . $from );
            }
            $phpmailer->FromName   = $name;
        }

        // Disable debug output
        $phpmailer->SMTPDebug = 0;
    }

    /**
     * Log email using wp_mail filter
     * This hook fires before wp_mail sends, allowing us to capture data without blocking
     */
    public function log_email_on_send( $args ) {
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
            self::$logged_emails[ $hash ] = true;
            
            // Log with 'success' status
            $this->logger->log_email(
                $to_string,
                $subject,
                $message,
                'success',
                ''
            );
            
            // Clean up old hashes
            if ( count( self::$logged_emails ) > 20 ) {
                self::$logged_emails = array_slice( self::$logged_emails, -10, 10, true );
            }
        }
        
        // MUST return the args unchanged for wp_mail to work
        return $args;
    }

    /**
     * Log failed emails
     */
    public function log_email_failure( $wp_error ) {
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
        
        $this->logger->log_email(
            $to,
            $subject,
            $message,
            'failed',
            $wp_error->get_error_message()
        );
    }
}