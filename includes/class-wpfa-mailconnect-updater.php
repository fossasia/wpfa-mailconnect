<?php

/**
 * Handles database schema versioning and migration.
 *
 * This class checks the stored database version against the expected version
 * and runs necessary update routines sequentially.
 *
 * @link       https://fossasia.org
 * @since      1.0.0
 *
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

/**
 * Wpfa_Mailconnect_Updater class.
 *
 * @since      1.0.0
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect_Updater {

    /**
     * The database option key used to store the current schema version.
     *
     * @since   1.0.0
     * @var string
     */
    const DB_VERSION_OPTION = 'wpfa_mailconnect_db_version';

    /**
     * Retrieves the currently installed database version.
     *
     * @since   1.0.0
     * @return string The installed DB version, or '0' if not set (pre-versioning install).
     */
    private function get_installed_db_version() {
        return get_option( self::DB_VERSION_OPTION, '0' );
    }

    /**
     * Updates the database version option to the latest expected version.
     *
     * @since   1.0.0
     * @param string $version The version string to save.
     * @return bool
     */
    private function update_db_version( $version ) {
        return update_option( self::DB_VERSION_OPTION, $version );
    }

    /**
     * Main method hooked to 'admin_init' to check and run updates.
     *
     * Compares the installed version with the required version constant.
     *
     * @since   1.0.0
     * @return void
     */
    public function check_for_updates() {
        // Only run if the user has permissions to update settings.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $installed_version = $this->get_installed_db_version();
        $expected_version  = WPFA_MAILCONNECT_DB_VERSION;

        // If versions match, no update is needed.
        if ( version_compare( $installed_version, $expected_version, '>=' ) ) {
            return;
        }
        
        // If we reach here, an update is needed.
        $this->run_migrations( $installed_version );
    }

    /**
     * Executes sequential migration routines.
     *
     * This method runs all necessary updates between the installed version and the expected version.
     *
     * @since   1.0.0
     * @param string $installed_version The current version string.
     * @return void
     */
    private function run_migrations( $installed_version ) {
        // Define all migration methods in version order.
        $migration_methods = array(
            '1.0.1' => 'update_to_1_0_1',
        // Migration for 1.2.0: Adds 'body_html' and 'status_details' columns.
            '1.2.0' => 'update_to_1_2_0', 
        );

        foreach ( $migration_methods as $version => $method ) {
            // Check if the installed version is older than the required migration version.
            if ( version_compare( $installed_version, $version, '<' ) ) {
                if ( method_exists( $this, $method ) ) {
                    // Execute the migration function
                    $this->$method();
                    
                    // Update the stored version after successful migration
                    $this->update_db_version( $version );
                } else {
                    // Log an error if a migration method is defined but missing
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( sprintf( 'WPFA MailConnect Updater Error: Migration method %s missing for version %s.', $method, $version ) );
                    }
                }
            }
        }
        
        // Final check: if the loop completed, ensure the version is set to the latest expected.
        $this->update_db_version( WPFA_MAILCONNECT_DB_VERSION );
    }

    /* --- Individual Migration Methods --- */
    
    /**
     * Migration function for version 1.2.0: Adds 'body_html' and 'status_details' columns.
     *
     * @since 1.2.0
     * @return void
     */
    private function update_to_1_2_0() {
        // Since create_log_table uses dbDelta, calling it again with the
        // updated schema from Wpfa_Mailconnect_Logger will automatically
        // add the new columns (body_html and status_details).

        // Ensure logger class is available
        require_once plugin_dir_path( __FILE__ ) . 'class-wpfa-mailconnect-logger.php';
        
        // Run the table creation/check routine to apply the schema update
        Wpfa_Mailconnect_Logger::create_log_table();
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WPFA MailConnect Database migrated to 1.2.0 (added body_html and status_details).' );
        }
    }


    /**
     * Example migration function for version 1.0.1.
     *
     * In a real-world scenario, this might add a new column, change a data type, etc.
     *
     * @since   1.0.1
     * @return void
     */
    private function update_to_1_0_1() {
        // Example: If we were to add a 'from_email' column in a future release, 
        // the logic would go here, often calling dbDelta() again with the updated schema.
        // For now, this is a placeholder.
        
        // Ensure logger class is available for potential table changes
        require_once plugin_dir_path( __FILE__ ) . 'class-wpfa-mailconnect-logger.php';
        
        // Run the table creation/check routine to apply any minor schema changes
        Wpfa_Mailconnect_Logger::create_log_table();
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WPFA MailConnect Database migrated to 1.0.1.' );
        }
    }

}