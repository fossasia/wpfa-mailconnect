<?php
/**
 * Default HTML email template.
 *
 * Available variables:
 * $header, $body, $footer, $primary_color, $background_color,
 * $content_background_color, $max_width, $site_name.
 *
 * @package Wpfa_Mailconnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- wpfa-mailconnect-template -->
<!doctype html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title><?php echo esc_html( $site_name ); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $background_color ); ?>;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:<?php echo esc_attr( $background_color ); ?>;margin:0;padding:24px 12px;">
		<tr>
			<td align="center">
				<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:<?php echo esc_attr( $max_width ); ?>px;border-collapse:collapse;">
					<tr>
						<td style="background:<?php echo esc_attr( $primary_color ); ?>;color:#ffffff;padding:24px;border-radius:8px 8px 0 0;font-size:20px;line-height:1.4;font-weight:700;">
							<?php echo wp_kses_post( $header ); ?>
						</td>
					</tr>
					<tr>
						<td style="background:<?php echo esc_attr( $content_background_color ); ?>;padding:28px 24px;font-size:16px;line-height:1.6;">
							<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Original wp_mail HTML body must remain intact. ?>
						</td>
					</tr>
					<?php if ( ! empty( $footer ) ) : ?>
						<tr>
							<td style="background:<?php echo esc_attr( $content_background_color ); ?>;border-top:1px solid #e5e7eb;padding:18px 24px;border-radius:0 0 8px 8px;color:#6b7280;font-size:13px;line-height:1.5;">
								<?php echo wp_kses_post( $footer ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
