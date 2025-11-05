<?php

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
    }

    /* --- Admin menu / settings registration --- */

    public function register_admin_menu() {
        add_options_page(
            'SMTP Email Settings',
            'SMTP Config',
            'manage_options',
            'smtp-config',
            array( $this, 'render_settings_page' )
        );
    }

    public function settings_init() {
        register_setting( 'smtp_settings_group', 'smtp_options' );

        add_settings_section(
            'smtp_main_section',
            'Core SMTP Credentials',
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
            'Send a Test Email',
            array( $this, 'test_section_callback' ),
            'smtp-config'
        );
    }

    public function section_callback() {
        echo '<p>Enter your SMTP credentials below. These settings will override the default WordPress email behavior.</p>';
    }

    public function test_section_callback() {
        echo '<p>Verify your settings by sending a test email. Use the default email or enter a custom address.</p>';
    }

    public function render_field( $args ) {
        $options = get_option( 'smtp_options', array() );
        $id = sanitize_key( $args['id'] );
        $value = isset( $options[ $id ] ) ? $options[ $id ] : $args['default'];

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

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>SMTP Email Configuration</h1>
            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'smtp_settings_group' );
                do_settings_sections( 'smtp-config' );
                submit_button( 'Save SMTP Settings' );
                ?>
            </form>

            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ccc;">

            <h2>Test Email</h2>
            <?php $this->test_email_form(); ?>

        </div>
        <?php
    }

    /* --- Test email form & handler --- */

    public function test_email_form() {
        $options = get_option( 'smtp_options', array() );
        $default_to = isset( $options['smtp_user'] ) ? $options['smtp_user'] : get_option( 'admin_email' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="smtp_send_test" />
            <?php wp_nonce_field( 'smtp_test_email_nonce', 'smtp_nonce_field' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="smtp_test_recipient">Recipient Email</label></th>
                    <td>
                        <input type="email" id="smtp_test_recipient" name="smtp_test_recipient" value="<?php echo esc_attr( $default_to ); ?>" class="regular-text" required />
                        <p class="description">The email address the test email will be sent to.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Send Test Email', 'secondary', 'smtp_send_test_button' ); ?>
        </form>
        <?php
    }

    public function handle_test_email() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Cheatin&#8217; huh?' );
        }
        if ( ! isset( $_POST['smtp_nonce_field'] ) || ! wp_verify_nonce( $_POST['smtp_nonce_field'], 'smtp_test_email_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $recipient = sanitize_email( $_POST['smtp_test_recipient'] );
        if ( ! is_email( $recipient ) ) {
            add_settings_error( 'smtp_messages', 'email_invalid', 'Error: Please enter a valid recipient email address.', 'error' );
            wp_safe_redirect( add_query_arg( 'settings-updated', 'false', wp_get_referer() ) );
            exit;
        }

        $subject = 'SMTP Test Email from ' . get_bloginfo( 'name' );
        $body    = 'Congratulations! If you receive this email, your SMTP settings are configured correctly using the ' . get_bloginfo( 'name' ) . ' plugin.';
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $success = wp_mail( $recipient, $subject, $body, $headers );

        if ( $success ) {
            add_settings_error( 'smtp_messages', 'email_success', 'Success! Test email sent to ' . esc_html( $recipient ) . '.', 'updated' );
        } else {
            global $phpmailer;
            $error_message = 'Failed to send test email. ';
            if ( ! empty( $phpmailer->ErrorInfo ) ) {
                $error_message .= 'PHPMailer Error: ' . $phpmailer->ErrorInfo;
            } else {
                $error_message .= 'Check your credentials, host, and port settings.';
            }
            add_settings_error( 'smtp_messages', 'email_fail', esc_html( $error_message ), 'error' );
        }

        wp_safe_redirect( add_query_arg( 'settings-updated', $success ? 'true' : 'false', wp_get_referer() ) );
        exit;
    }

    /* --- PHPMailer override --- */

    public function phpmailer_override( $phpmailer ) {
        $options = get_option( 'smtp_options', array() );

        $user   = isset( $options['smtp_user'] ) ? $options['smtp_user'] : '';
        $pass   = isset( $options['smtp_pass'] ) ? $options['smtp_pass'] : '';
        $host   = isset( $options['smtp_host'] ) ? $options['smtp_host'] : 'localhost';
        $port   = isset( $options['smtp_port'] ) ? (int) $options['smtp_port'] : 25;
        $secure = isset( $options['smtp_secure'] ) ? $options['smtp_secure'] : '';
        $auth   = isset( $options['smtp_auth'] ) ? $options['smtp_auth'] === '1' : false;
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
                $phpmailer->From = get_bloginfo( 'admin_email' );
                // Optionally log the error or notify admin
                error_log( 'WPFA MailConnect SMTP: Invalid "From" email address provided: ' . $from );
            }
            $phpmailer->FromName   = $name;
        }
    }
}
