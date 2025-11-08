<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/admin
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

		add_action('admin_menu', array($this, 'add_logs_page'));
		add_action('admin_post_clear_email_logs', array($this, 'handle_clear_logs'));

	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Wpfa_Mailconnect_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Wpfa_Mailconnect_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/wpfa-mailconnect-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Wpfa_Mailconnect_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Wpfa_Mailconnect_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/wpfa-mailconnect-admin.js', array( 'jquery' ), $this->version, false );

	}

    public function add_logs_page() {
        add_submenu_page(
            'options-general.php',
            'Email Logs',
            'Email Logs',
            'manage_options',
            'wpfa-mail-logs',
            array($this, 'render_logs_page')
        );
    }

    public function render_logs_page() {
		// Checks that the current user has permission (manage_options = administrator)
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $logger = new Wpfa_Mailconnect_Logger();
        
        // Pagination settings
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get paginated logs and total count
        $logs = $logger->get_logs($per_page, $offset);
        $total_logs = $logger->get_total_logs();
        $total_pages = ceil($total_logs / $per_page);

        ?>
        <div class="wrap">
            <h1>Email Logs</h1>
            
            <?php if (isset($_GET['cleared']) && sanitize_text_field($_GET['cleared']) === '1'): ?>
                <div class="notice notice-success">
                    <p><?php _e('Logs cleared successfully!'); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 1em 0;">
                <input type="hidden" name="action" value="clear_email_logs">
                <?php wp_nonce_field('clear_email_logs_nonce', 'clear_logs_nonce'); ?>
                <?php submit_button('Clear All Logs', 'delete', 'submit', false, 
                    array('onclick' => 'return confirm("Are you sure you want to clear all email logs?")')); ?>
            </form>

            <?php if (!empty($logs)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>To</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td><?php echo esc_html($log->to_email); ?></td>
                            <td><?php echo esc_html($log->subject); ?></td>
                            <td><?php echo esc_html($log->status); ?></td>
                            <td><?php echo esc_html($log->error_message); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                // Add pagination
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo wp_kses_post(paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page
                )));
                echo '</div>';
                echo '</div>';
                ?>
            <?php else: ?>
                <p><?php _e('No email logs found.'); ?></p>
            <?php endif; ?>
        </div>
        <?php	
    }

    public function handle_clear_logs() {
		// Ensures only admins can clear logs
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        if (!isset($_POST['clear_logs_nonce']) || !wp_verify_nonce($_POST['clear_logs_nonce'], 'clear_email_logs_nonce')) {
            wp_die('Invalid nonce');
        }

        $logger = new Wpfa_Mailconnect_Logger();
        $logger->clear_logs();

        wp_safe_redirect(add_query_arg(
            array('page' => 'wpfa-mail-logs', 'cleared' => '1'),
            admin_url('options-general.php')
        ));
        exit;
    }
}
