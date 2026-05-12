# WPFA Mail Connect

A WordPress plugin scaffold by FOSSASIA to integrate email connectivity into sites and other WPFA plugins.
It ships with a standard, maintainable structure (`admin/`, `includes/`, `public/`, `languages/`) and an uninstall routine, ready for adding SMTP/API providers, settings screens, and helper functions.
Licensed under Apache-2.0.

Current stable release: **1.0.0**.

## Features

Current repository status: minimal plugin skeleton prepared for adding mail connectivity features.

What’s included out of the box:

* WordPress-compatible plugin bootstrap (`wpfa-mailconnect.php`)
* Standard directories for admin UI, public hooks, and shared includes
* Translation domain and language folder
* `uninstall.php` stub to keep data cleanup explicit

> Note: Provider integrations (SMTP/API), settings pages, and email helpers can be implemented inside the existing structure.

## Requirements

* WordPress 6.0+ (recommended)
* PHP 7.4+ (PHP 8.x compatible recommended)

## Install

### Using Git (recommended for contributors)

From your WordPress root:

```bash
cd wp-content/plugins
git clone https://github.com/fossasia/wpfa-mailconnect.git
```

### Manual (ZIP)

1. Download the repository as ZIP.
2. In the WordPress admin go to **Plugins → Add New → Upload Plugin** and select the ZIP.

## Activate

Go to **Plugins** in the WP admin and activate **WPFA MailConnect**.

## Configure

Once features are implemented, a settings page (e.g. under **Settings → WPFA MailConnect**) can expose:

* Connection method (SMTP / Provider API)
* Host / Port / Encryption
* Authentication (username/password or token)
* From address and name
* Test-email utility

> The repository already contains `admin/` for an options page scaffold. Hook your settings there.

## Project structure

```
wpfa-mailconnect/
├─ admin/        # Admin screens, settings, assets
├─ includes/     # Core classes, loaders, helpers
├─ languages/    # .po/.mo translation files
├─ public/       # Public-facing hooks/assets
├─ index.php
├─ uninstall.php # Cleanup stub
└─ wpfa-mailconnect.php # Main plugin bootstrap
```

This layout follows common WordPress plugin practices and matches the current repository contents.

## Localization (i18n)

* Text domain: `wpfa-mailconnect`
* Translation files live in `languages/`. Add/update `.po`/`.mo` files as usual.

## Uninstall behavior

The repository includes `uninstall.php`. Extend it to remove plugin options and custom data on uninstall to keep sites clean.

## Development

1. Create feature classes in `includes/` (e.g., loaders, mail adapters).
2. Register admin screens in `admin/` (settings API, capability checks).
3. Add public hooks/assets in `public/` (should you expose shortcodes or public forms).
4. Wire everything from `wpfa-mailconnect.php` (activation/deactivation, loaders).

Suggested next tasks:

* Implement a `MailAdapterInterface` and concrete adapters (SMTP, provider APIs)
* Add a settings page with the WordPress Settings API
* Provide a **Send Test Email** action to verify connectivity
* Optionally, add a logging mechanism (WP_Logging or custom table) guarded by capability checks
* Write unit tests for adapters (mock transport)

## Coding standards

* Follow WordPress PHP coding standards
* Escape output (`esc_html`, `esc_attr`, `wp_kses`) and sanitize input (`sanitize_text_field`, etc.)
* Use nonces and capability checks for all admin actions
* Prefix everything with `wpfa_mailconnect_` or `WPFA_MailConnect_` to avoid collisions

## Contributing

Contributions are welcome! Please:

1. Fork the repository and create a feature branch
2. Keep commits focused and add tests where possible
3. Open a Pull Request with a clear description and screenshots of admin UI changes

## License

Apache License 2.0. See [`LICENSE.txt`](LICENSE.txt).
