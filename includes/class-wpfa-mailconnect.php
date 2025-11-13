<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Wpfa_Mailconnect_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * SMTP configuration manager instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Wpfa_Mailconnect_SMTP    $smtp    Handles SMTP configuration and operations.
	 */
    protected $smtp;

	/**
     * Database Updater instance.
     *
     * @since    1.0.0
     * @access   protected
     * @var      Wpfa_Mailconnect_Updater    $updater   Handles database migrations.
     */
	protected $updater;


	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'WPFA_MAILCONNECT_VERSION' ) ) {
			$this->version = WPFA_MAILCONNECT_VERSION;
		} else {
			// Updated to 1.2.0 to reflect advanced logging columns and features.
			$this->version = '1.2.0';
		}
		$this->plugin_name = 'wpfa-mailconnect';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Wpfa_Mailconnect_Loader. Orchestrates the hooks of the plugin.
	 * - Wpfa_Mailconnect_i18n. Defines internationalization functionality.
	 * - Wpfa_Mailconnect_Admin. Defines all hooks for the admin area.
	 * - Wpfa_Mailconnect_Public. Defines all hooks for the public side of the site.
     * - Wpfa_Mailconnect_Updater. Handles database migrations.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wpfa-mailconnect-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wpfa-mailconnect-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-wpfa-mailconnect-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-wpfa-mailconnect-public.php';
		
		/**
		 * The class responsible for handling all database logging.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wpfa-mailconnect-logger.php';
        
        /**
         * The class responsible for database schema versioning and migration.
         */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wpfa-mailconnect-updater.php';

		/**
		 * The class responsible for SMTP settings and functionality
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wpfa-mailconnect-smtp.php';

		$this->loader = new Wpfa_Mailconnect_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Wpfa_Mailconnect_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Wpfa_Mailconnect_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Wpfa_Mailconnect_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		// instantiate smtp manager and register its hooks
		$this->smtp = new Wpfa_Mailconnect_SMTP( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_menu', $this->smtp, 'register_admin_menu' );
		$this->loader->add_action( 'admin_init', $this->smtp, 'settings_init' );
		$this->loader->add_action( 'admin_post_smtp_send_test', $this->smtp, 'handle_test_email' );
		$this->loader->add_action( 'phpmailer_init', $this->smtp, 'phpmailer_override' );

		// Add email logging hooks
		$this->loader->add_filter( 'wp_mail', $this->smtp, 'log_email_on_send', 10, 5 );

		// Track email result (needs 5 args and a high priority to run late)
		$this->loader->add_filter( 'wp_mail', $this->smtp, 'track_email_result', 999, 5 );

		// Action hook for logging success (fired by track_email_result)
		$this->loader->add_action( 'wpfa_mailconnect_mail_sent', $this->smtp, 'log_email_success', 10, 1 );

		// Action hook for logging failure
		$this->loader->add_action( 'wp_mail_failed', $this->smtp, 'log_email_failure', 10, 1 );

		// Register the scheduled action for log cleanup
		$this->loader->add_action( 'wpfa_mailconnect_cleanup_logs', $this->smtp, 'do_log_cleanup' );

        // Instantiate the Updater and register its migration check hook.
        $this->updater = new Wpfa_Mailconnect_Updater();
		$this->loader->add_action( 'admin_init', $this->updater, 'check_for_updates' ); 
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Wpfa_Mailconnect_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Wpfa_Mailconnect_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}