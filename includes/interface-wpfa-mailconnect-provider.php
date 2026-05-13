<?php
/**
 * Provider interface for mail delivery adapters.
 *
 * @package Wpfa_Mailconnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the contract provider adapters must follow.
 */
interface Wpfa_Mailconnect_Provider_Interface {

	/**
	 * Sends an email through a provider.
	 *
	 * Provider implementations must accept the wp_mail attachments argument and
	 * deliver those files through their transport without logging file contents.
	 *
	 * @param string|array $to Recipient email address or addresses.
	 * @param string       $subject Email subject.
	 * @param string       $message Email body.
	 * @param string|array $headers Email headers.
	 * @param string|array $attachments Attachment paths from wp_mail.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function send( $to, $subject, $message, $headers = array(), $attachments = array() );
}
