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
		$this->version     = $version;

		add_action( 'admin_menu', array( $this, 'add_logs_page' ) );
		// Changed to admin_post_ to handle clear logs action securely
		add_action( 'admin_post_clear_email_logs', array( $this, 'handle_clear_logs' ) );

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

        // Enqueue logs page styles only on the logs page
        $screen = get_current_screen();
        if ( $screen && 'settings_page_wpfa-mail-logs' === $screen->id ) {
            wp_enqueue_style(
                $this->plugin_name . '-logs',
                plugin_dir_url( __FILE__ ) . 'css/wpfa-mailconnect-logs.css',
                array(),
                $this->version,
                'all'
            );
        }
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

    /**
     * Add the Email Logs submenu page under Settings.
     */
    public function add_logs_page() {
        // Menu Label and Page Title
        $page_title = esc_html__( 'Email Logs', 'wpfa-mailconnect' );
        $menu_title = esc_html__( 'Email Logs', 'wpfa-mailconnect' );

        add_submenu_page(
            'options-general.php',
            $page_title,
            $menu_title,
            'manage_options',
            'wpfa-mail-logs',
            array( $this, 'render_logs_page' )
        );
    }

    /**
     * Renders the Email Logs page, including the filter form and the log table.
     */
    public function render_logs_page() {
		// Checks that the current user has permission (manage_options = administrator)
        if ( ! current_user_can( 'manage_options' ) ) {
            // Permission denied message
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wpfa-mailconnect' ) );
        }

        $logger = new Wpfa_Mailconnect_Logger();

		// --- Filtering and Pagination setup ---
        $per_page     = 20;
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset       = ( $current_page - 1 ) * $per_page;

		// Filtering parameters
		$filter_status  = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        // SECURITY FIX: Explicitly validate 'status' against allowed values.
        $filter_status = in_array( $filter_status, array( 'success', 'failed' ), true ) ? $filter_status : '';
		$filter_search  = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        // Get paginated and filtered logs and total count
		$logs       = $logger->get_logs( $per_page, $offset, $filter_status, $filter_search );
		$total_logs = $logger->get_total_logs( $filter_status, $filter_search );
        $total_pages = ceil( $total_logs / $per_page );

		// Base URL for links
		$base_url = admin_url( 'options-general.php?page=wpfa-mail-logs' );

        // --- Start HTML Output ---
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Email Logs', 'wpfa-mailconnect' ); ?></h1>

            <?php if ( isset( $_GET['cleared'] ) && sanitize_text_field( $_GET['cleared'] ) === '1' ) : ?>
                <div class="notice notice-success">
                    <p><?php esc_html_e( 'Logs cleared successfully!', 'wpfa-mailconnect' ); ?></p>
                </div>
            <?php endif; ?>

			<?php
			// Generate nonce for the form
			$clear_nonce  = wp_create_nonce( 'clear_email_logs_nonce' );
			$confirm_text = esc_js( __( 'Are you sure you want to clear all email logs?', 'wpfa-mailconnect' ) );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
				<input type="hidden" name="action" value="clear_email_logs" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $clear_nonce ); ?>" />
				<p class="submit">
					<input type="submit" 
						name="submit" 
						class="button button-delete" 
						value="<?php esc_attr_e( 'Clear All Logs', 'wpfa-mailconnect' ); ?>"
						onclick="return confirm('<?php echo $confirm_text; ?>');" />
				</p>
			</form>

            <!-- Log Filter Form -->
            <form method="get" class="search-form">
                <input type="hidden" name="page" value="wpfa-mail-logs" />
                
                <label for="status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by Status', 'wpfa-mailconnect' ); ?></label>
                <select name="status" id="status-filter">
                    <option value=""><?php esc_html_e( 'All Statuses', 'wpfa-mailconnect' ); ?></option>
                    <option value="success" <?php selected( $filter_status, 'success' ); ?>><?php esc_html_e( 'Success', 'wpfa-mailconnect' ); ?></option>
                    <option value="failed" <?php selected( $filter_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'wpfa-mailconnect' ); ?></option>
                </select>

                <label for="log-search-input" class="screen-reader-text"><?php esc_html_e( 'Search Recipient', 'wpfa-mailconnect' ); ?></label>
                <input type="search" id="log-search-input" name="s" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Search Recipient...', 'wpfa-mailconnect' ); ?>" />

                <?php submit_button( esc_html__( 'Filter/Search', 'wpfa-mailconnect' ), 'primary', 'submit', false ); ?>
            </form>
            <!-- End Log Filter Form -->


			<?php if ( ! empty( $logs ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'wpfa-mailconnect' ); ?></th>
							<th><?php esc_html_e( 'To', 'wpfa-mailconnect' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'wpfa-mailconnect' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wpfa-mailconnect' ); ?></th>
							<th><?php esc_html_e( 'Error', 'wpfa-mailconnect' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
						<tr class="<?php echo 'failed' === $log->status ? 'log-failed' : 'log-success'; ?>">
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td><?php echo esc_html( $log->to_email ); ?></td>
							<td><?php echo esc_html( $log->subject ); ?></td>
							<td>
								<span class="log-status log-status-<?php echo esc_attr( $log->status ); ?>">
									<?php echo esc_html( ucfirst( $log->status ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $log->error_message ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

                <?php
                // Add pagination
				$pagination_args = array(
					'base'      => add_query_arg( 'paged', '%#%', $base_url ),
					'format'    => '',
					'prev_text' => __( '&laquo;', 'wpfa-mailconnect' ),
					'next_text' => __( '&raquo;', 'wpfa-mailconnect' ),
					'total'     => $total_pages,
					'current'   => $current_page,
				);

				// Ensure filters are carried over in pagination links
				if ( $filter_status ) {
					$pagination_args['base'] = add_query_arg( 'status', $filter_status, $pagination_args['base'] );
				}
				if ( $filter_search ) {
					$pagination_args['base'] = add_query_arg( 's', $filter_search, $pagination_args['base'] );
				}

                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
				echo wp_kses_post( paginate_links( $pagination_args ) );
                echo '</div>';
                echo '</div>';
                ?>
            <?php else : ?>
                <p><?php esc_html_e( 'No email logs found.', 'wpfa-mailconnect' ); ?></p>
            <?php endif; ?>
        </div>
		<?php
	}

    /**
     * Handles the clearing of all email logs after security checks.
     */
    public function handle_clear_logs() {
		// Ensures only admins can clear logs
		if ( ! current_user_can( 'manage_options' ) ) {
            // Unauthorized access message
			wp_die( esc_html__( 'Unauthorized access', 'wpfa-mailconnect' ) );
        }

		// Security check: Use wp_verify_nonce for the action's nonce
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'clear_email_logs_nonce' ) ) {
            // Invalid nonce message
			wp_die( esc_html__( 'Invalid nonce', 'wpfa-mailconnect' ) );
		}

		$logger = new Wpfa_Mailconnect_Logger();
		$logger->clear_logs();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wpfa-mail-logs',
					'cleared' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
        exit;
    }
}