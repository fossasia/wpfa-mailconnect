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

		// Changed to admin_post_ to handle clear logs action securely
		add_action( 'admin_post_clear_email_logs', array( $this, 'handle_clear_logs' ) );

	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/wpfa-mailconnect-admin.css', array(), $this->version, 'all' );

		// Enqueue logs page styles only on the logs page
		$screen = get_current_screen();
		if ( $screen && 'wpfa-mailconnect_page_wpfa-mail-logs' === $screen->id ) {
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

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/wpfa-mailconnect-admin.js', array( 'jquery' ), $this->version, false );

	}

	/**
	 * Renders a compact WordPress visual editor for template settings.
	 *
	 * @since 1.0.0
	 * @param string $field_id The smtp_options field id.
	 * @param string $value The current editor value.
	 * @return void
	 */
	public static function render_email_template_editor( $field_id, $value ) {
		wp_editor(
			$value,
			'smtp_options_' . sanitize_key( $field_id ),
			array(
				'textarea_name' => 'smtp_options[' . sanitize_key( $field_id ) . ']',
				'textarea_rows' => 6,
				'media_buttons' => false,
				'teeny'         => true,
			)
		);
	}

	/**
	 * Displays an admin notice if SMTP credentials could not be decrypted.
	 *
	 * @since    1.0.0
	 */
	public function display_decryption_failure_notice() {
		// Only show to users who can manage options
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failure_time = get_option( 'wpfa_mailconnect_smtp_decryption_failed' );

		if ( $failure_time ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'WPFA Mailconnect:', 'wpfa-mailconnect' ); ?></strong>
					<?php esc_html_e( 'The stored SMTP password could not be decrypted (likely due to a security update or server change). SMTP authentication will fail until you re-enter and save your credentials.', 'wpfa-mailconnect' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=smtp-config' ) ); ?>">
						<?php esc_html_e( 'Go to Settings', 'wpfa-mailconnect' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Clears the decryption failure flag when settings are updated.
	 *
	 * @since    1.0.0
	 */
	public function clear_decryption_failure_flag() {
		$screen = get_current_screen();
		if ( ! $screen || 'wpfa-mailconnect_page_smtp-config' !== $screen->id ) {
			return;
		}
		$settings_updated = filter_input( INPUT_GET, 'settings-updated', FILTER_VALIDATE_BOOLEAN );
		if ( $settings_updated ) {
			delete_option( 'wpfa_mailconnect_smtp_decryption_failed' );
		}
	}

	/**
	 * Registers the Mail Connect top-level menu (dashboard) and submenu pages.
	 *
	 * WordPress duplicates the top-level slug as the first submenu; that entry is
	 * removed so the parent menu opens the dashboard with no redundant item.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Mail Connect', 'wpfa-mailconnect' ),
			__( 'Mail Connect', 'wpfa-mailconnect' ),
			'manage_options',
			'wpfa-mailconnect',
			array( $this, 'render_dashboard_page' ),
			'dashicons-email-alt',
			58
		);

		remove_submenu_page( 'wpfa-mailconnect', 'wpfa-mailconnect' );

		add_submenu_page(
			'wpfa-mailconnect',
			__( 'SMTP Settings', 'wpfa-mailconnect' ),
			__( 'SMTP Settings', 'wpfa-mailconnect' ),
			'manage_options',
			'smtp-config',
			array( 'Wpfa_Mailconnect_SMTP', 'render_settings_page_static' )
		);

		add_submenu_page(
			'wpfa-mailconnect',
			__( 'Email Logs', 'wpfa-mailconnect' ),
			__( 'Email Logs', 'wpfa-mailconnect' ),
			'manage_options',
			'wpfa-mail-logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Link back to the Mail Connect dashboard from submenu screens.
	 *
	 * @return void
	 */
	public static function render_back_to_mail_connect_link() {
		$url = admin_url( 'admin.php?page=wpfa-mailconnect' );
		echo '<p class="wpfa-mailconnect-back"><a href="' . esc_url( $url ) . '">&larr; ';
		esc_html_e( 'Mail Connect home', 'wpfa-mailconnect' );
		echo '</a></p>';
	}

	/**
	 * Renders the Mail Connect dashboard landing page.
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wpfa-mailconnect' ) );
		}

		?>
		<div class="wrap wpfa-mailconnect-dashboard">
			<div class="wpfa-mailconnect-dashboard__hero">
				<p class="wpfa-mailconnect-dashboard__eyebrow"><?php esc_html_e( 'FOSSASIA Mail Connect', 'wpfa-mailconnect' ); ?></p>
				<h1><?php esc_html_e( 'Mail Connect', 'wpfa-mailconnect' ); ?></h1>
				<p><?php esc_html_e( 'Configure SMTP delivery, monitor outgoing email activity, and keep WordPress mail workflows visible from one place.', 'wpfa-mailconnect' ); ?></p>
			</div>

			<div class="wpfa-mailconnect-dashboard__panel">
				<h2><?php esc_html_e( 'Get Started', 'wpfa-mailconnect' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Add your SMTP host, port, authentication, and sender details.', 'wpfa-mailconnect' ); ?></li>
					<li><?php esc_html_e( 'Send a test email to confirm delivery.', 'wpfa-mailconnect' ); ?></li>
					<li><?php esc_html_e( 'Review email logs to troubleshoot delivery status.', 'wpfa-mailconnect' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
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
		
		// UPDATE: Explicitly validate 'status' against allowed values including 'pending'.
		$filter_status = in_array( $filter_status, Wpfa_Mailconnect_Logger::ALLOWED_STATUSES, true ) ? $filter_status : '';

		$filter_search  = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

		// Get paginated and filtered logs and total count
		$logs       = $logger->get_logs( $per_page, $offset, $filter_status, $filter_search );
		$total_logs = $logger->get_total_logs( $filter_status, $filter_search );
		$total_pages = ceil( $total_logs / $per_page );

		// Base URL for links
		$base_url = admin_url( 'admin.php?page=wpfa-mail-logs' );

		// --- Start HTML Output ---
		?>
		<div class="wrap">
			<?php self::render_back_to_mail_connect_link(); ?>
			<h1><?php esc_html_e( 'Email Logs', 'wpfa-mailconnect' ); ?></h1>

			<?php $this->render_queue_status_widget(); ?>

			<?php 
			// SECURE FIX: Check for the success transient instead of a URL parameter.
			$logs_cleared = get_transient( 'wpfa_mailconnect_logs_cleared' );
			if ( false !== $logs_cleared && 'success' === $logs_cleared ) : 
				delete_transient( 'wpfa_mailconnect_logs_cleared' ); // Delete after displaying
			?>
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
					<!-- UPDATE: Add 'pending' status option -->
					<option value="pending" <?php selected( $filter_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'wpfa-mailconnect' ); ?></option>
					<option value="queued" <?php selected( $filter_status, 'queued' ); ?>><?php esc_html_e( 'Queued', 'wpfa-mailconnect' ); ?></option>
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
							<th><?php esc_html_e( 'Attachments', 'wpfa-mailconnect' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wpfa-mailconnect' ); ?></th>
							<th><?php esc_html_e( 'Error', 'wpfa-mailconnect' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
						<tr class="<?php 
							// UPDATE: Determine class based on status, including 'pending'
							$row_class = 'log-success';
							if ( 'failed' === $log->status ) {
								$row_class = 'log-failed';
							} elseif ( 'queued' === $log->status ) {
								$row_class = 'log-pending';
							} elseif ( 'pending' === $log->status ) {
								$row_class = 'log-pending';
							}
							echo esc_attr( $row_class ); 
						?>">
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td><?php echo esc_html( $log->to_email ); ?></td>
							<td><?php echo esc_html( $log->subject ); ?></td>
							<td><?php echo esc_html( $this->format_attachment_log_summary( $log->status_details ) ); ?></td>
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
				<p><?php esc_html_e( 'No email logs found', 'wpfa-mailconnect' ); ?>.</p>
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

		// SECURE FIX: Set a transient instead of using a URL parameter for success message display.
		set_transient( 'wpfa_mailconnect_logs_cleared', 'success', MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wpfa-mail-logs',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders queue status counts on the logs screen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function render_queue_status_widget() {
		if ( ! class_exists( 'Wpfa_Mailconnect_Queue' ) ) {
			return;
		}

		$queue  = new Wpfa_Mailconnect_Queue();
		$counts = $queue->get_status_counts();
		?>
		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Email Queue:', 'wpfa-mailconnect' ); ?></strong>
				<?php
				printf(
					/* translators: 1: queued count, 2: processing count, 3: sent count, 4: failed count */
					esc_html__( 'Queued: %1$d | Processing: %2$d | Sent: %3$d | Failed: %4$d', 'wpfa-mailconnect' ),
					absint( $counts['queued'] ),
					absint( $counts['processing'] ),
					absint( $counts['sent'] ),
					absint( $counts['failed'] )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Formats safe attachment metadata from a log entry.
	 *
	 * @since 1.0.0
	 * @param string $status_details JSON status details.
	 * @return string Human-readable attachment summary.
	 */
	private function format_attachment_log_summary( $status_details ) {
		$details = json_decode( (string) $status_details, true );
		if ( ! is_array( $details ) || empty( $details['attachments_included'] ) ) {
			return __( 'No', 'wpfa-mailconnect' );
		}

		$count = isset( $details['attachment_count'] ) ? absint( $details['attachment_count'] ) : 0;

		return sprintf(
			/* translators: %d: attachment count */
			_n( 'Yes (%d file)', 'Yes (%d files)', $count, 'wpfa-mailconnect' ),
			$count
		);
	}
}
