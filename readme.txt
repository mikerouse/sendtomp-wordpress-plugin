=== SendToMP ===
Contributors: binarybeagle
Tags: mp, parliament, democracy, constituency, advocacy
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.4.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send verified constituent messages to UK Members of Parliament and Peers. Built by a former parliamentary assistant.

== Description ==

SendToMP lets your website visitors send verified messages directly to their MP or a member of the House of Lords. Every message goes through a double opt-in confirmation flow to ensure only genuine constituents can contact their representatives.

Built by a former parliamentary assistant who understands how MP offices process inbound correspondence, SendToMP is designed to produce messages that get read and acted upon.

**How it works:**

1. A visitor fills in your contact form with their name, email, postcode, and message
2. SendToMP looks up their MP based on postcode (or lets them select a Peer)
3. A confirmation email is sent to the visitor to verify their identity
4. The visitor clicks through and confirms on your site — only then is the message sent to the MP
5. The MP receives a properly formatted email with the constituent's details and can reply directly

**Form plugin support:**

* Gravity Forms
* WPForms (Plus and above)
* Contact Form 7 (Plus and above)
* Webhook/REST API for custom integrations (Pro)

**Key features:**

* Double opt-in confirmation prevents abuse and ensures GDPR compliance
* Postcode-based MP lookup via the Parliament API
* House of Lords member search and selection
* Configurable email templates
* Submission logging with delivery status tracking
* Rate limiting and anti-spam protection (per-email, per-IP, per-postcode)
* Address override system for known MP office changes
* SMTP plugin detection with health checks
* Privacy-first design — no constituent PII sent to external services

**Upgrade to Plus or Pro** for additional form plugin support, House of Lords targeting, BCC copies, full email template customisation, local address overrides, webhook API integrations, analytics with CSV export, and priority support. Visit [bluetorch.co.uk/sendtomp](https://www.bluetorch.co.uk/sendtomp) for details.

== Installation ==

1. Upload the `sendtomp` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to SendToMP in the admin menu to configure your settings
4. Set up a form using a supported form plugin (Gravity Forms on the free tier)
5. Map the required fields: name, email, postcode, subject, and message body

**Requirements:**

* An SMTP plugin is strongly recommended for reliable email delivery (e.g. WP Mail SMTP, FluentSMTP)
* At least one supported form plugin must be installed and active

== Frequently Asked Questions ==

= Why does SendToMP require email confirmation? =

Email confirmation (double opt-in) ensures that only the actual person who filled in the form can send a message to an MP. This prevents abuse, satisfies GDPR consent requirements, and ensures MPs receive genuine constituent correspondence. Messages sent via SendToMP carry more credibility because they are verified.

= Which form plugins are supported? =

The free tier supports Gravity Forms. Plus subscribers can also use WPForms and Contact Form 7. Pro subscribers additionally get a webhook/REST API endpoint for custom integrations with platforms like Zapier, n8n, Make, or CRM systems.

= Does this work with the House of Lords? =

Yes. Plus and Pro tiers support targeting members of the House of Lords. Visitors can search for and select a Peer by name.

= How does the postcode lookup work? =

SendToMP uses the UK Parliament API to resolve a postcode to the correct constituency and sitting MP. Only the postcode is sent to the API — no other personal information leaves your site.

= Do I need an SMTP plugin? =

It is strongly recommended. WordPress's default `wp_mail()` function uses PHP's `mail()`, which is unreliable for delivery. An SMTP plugin (WP Mail SMTP, FluentSMTP, Post SMTP, etc.) configured with a transactional email service (Brevo, SendGrid, Postmark, Amazon SES) ensures both confirmation emails and MP messages are delivered.

= Is SendToMP GDPR compliant? =

SendToMP is designed with privacy in mind. The double opt-in flow collects explicit consent. No personally identifiable information is sent to external services — only the postcode is used for MP lookup. All constituent data is stored on your own WordPress site. Submission logs can be auto-purged after a configurable retention period, and a WP-CLI command is available for right-to-erasure requests.

= What happens if my licence expires? =

The plugin continues to work at the free tier level. Plus/Pro features become unavailable, but existing functionality and data are preserved. You can reactivate at any time.

= Can I enable auto-updates? =

Yes. When installed from WordPress.org, you can enable auto-updates from the Plugins page in your WordPress admin — just click "Enable auto-updates" next to SendToMP. Plus and Pro subscribers with a licence key receive updates directly from Bluetorch, which also supports WordPress auto-updates.

== Screenshots ==

1. Settings page — configure your email templates, rate limits, and form field mappings
2. Delivery log — track every submission with status, timestamps, and MP details

== Changelog ==

= 1.4.5 =
* Add SendToMP merge tags to the Gravity Forms merge-tag picker so campaign owners can insert MP Name, MP Constituency, MP Party, MP House, Constituent Name/Postcode, and Site Name from the UI
* These tokens are resolved at send time after the postcode lookup, so the MP's actual name and constituency appear in the delivered email

= 1.4.4 =
* Message Body in the Gravity Forms feed is now a rich WYSIWYG editor with bold, italics, lists, links, and blockquotes
* MP emails automatically switch to HTML format when the body template contains HTML so formatting is preserved
* Confirmation emails to constituents strip any HTML from the preview so the plain-text confirmation stays clean and readable

= 1.4.3 =
* Message Subject and Message Body in the Gravity Forms feed are now editable templates with full merge-tag support, rather than rigid field-to-field mappings
* Campaign owners can now write a template letter like "Dear {member_name}, ... {Your message}" — the form user's typed message merges in alongside any fixed text
* Full Address field is clearly marked optional with a helper explaining that postcode alone is sufficient for MP lookup
* Feed settings reorganised into Constituent Fields / Message Content / Conditional Logic sections for clarity

= 1.4.2 =
* Fix Gravity Forms adapter not appearing under Form Settings — registration now handles the case where gform_loaded has already fired (hook priority ordering)

= 1.4.1 =
* Fix fatal error on sites with Gravity Forms active but older feed addon framework loading
* Use include_feed_addon_framework() instead of include_addon_framework() for the GF adapter
* Register the GF adapter on the gform_loaded action so the framework is guaranteed to be ready

= 1.4.0 =
* New Status dashboard — see at a glance which form plugins are installed, whether SMTP is set up, your current plan, and activity totals
* Plugin activation now redirects to the Status tab so first-time users know what to do next
* New dismissible notice appears if no supported form plugin is active, with a "Don't remind me again" option
* One-click Install/Activate buttons for free form plugins available on WordPress.org (WPForms Lite, Contact Form 7)
* Gravity Forms card includes affiliate disclosure text ready for when the link is configured

= 1.3.3 =
* Removed Update URI header (not permitted on wp.org-hosted plugins)
* Updated "Tested up to" to WordPress 6.9

= 1.3.2 =
* Cleared remaining Plugin Check warnings for a clean PCP report
* Added phpcs:ignore annotations to legitimate direct DB queries in logger
* Added phpcs:ignore for confirmation token checks (signed random token acts as capability)
* Suppressed non-prefixed variable warnings in admin view templates

= 1.3.1 =
* Removed custom plugin updater — updates now distributed via WordPress.org
* Fixed all Plugin Check (PCP) errors for WordPress.org compliance
* Added translators comments to all i18n strings with placeholders
* Improved escaping at point of output across admin settings pages
* Added phpcs:ignore annotations for legitimate direct DB queries
* Replaced direct fclose() with justified annotation for CSV stream download

= 1.3.0 =
* Prepared for WordPress.org plugin directory submission
* Added escaping at point of output for all template variables
* Moved all inline scripts to properly enqueued JavaScript files
* Wrapped all user-facing strings in translation functions (i18n)
* Added optional branding toggle for all tiers including free
* Added GPLv2 LICENSE file and phpcs.xml coding standards config

= 1.2.0 =
* Hardcoded API URL for improved reliability
* Removed api_url and api_key settings from admin interface
* Simplified initial setup experience

= 1.1.0 =
* Improved settings page UX
* Enhanced form field mapping interface

= 1.0.0 =
* Initial release
* Gravity Forms integration
* WPForms integration (Plus)
* Contact Form 7 integration (Plus)
* Webhook/REST API endpoint (Pro)
* Double opt-in confirmation flow
* Postcode-based MP lookup
* House of Lords member search (Plus)
* Submission logging and delivery tracking
* Rate limiting and anti-spam protection
* Configurable email templates
* Address override system
* SMTP plugin detection and health checks
* Licence key activation and tier-based feature gating

== Upgrade Notice ==

= 1.4.5 =
Adds MP Name, MP Constituency, and other SendToMP-specific tokens to the Gravity Forms merge-tag picker.

= 1.4.4 =
Message Body template now has a rich WYSIWYG editor; emails to MPs render with formatting intact.

= 1.4.3 =
Major improvement to the Gravity Forms feed: Message Subject and Body are now editable templates with merge-tag support. Existing feeds will need the subject and body reconfigured.

= 1.4.2 =
Fixes the SendToMP tab not appearing in Gravity Forms form settings.

= 1.4.1 =
Fixes a fatal error when activating alongside Gravity Forms on some configurations.

= 1.4.0 =
Adds a new Status dashboard that shows which form plugins and SMTP providers are set up, so you know what's needed to start sending messages.

= 1.3.3 =
Fixes WordPress.org automated scan errors — removed Update URI header, updated tested-up-to version.

= 1.3.2 =
Code quality improvements for a clean Plugin Check report.

= 1.3.1 =
WordPress.org compliance release — removed custom updater, fixed all Plugin Check errors.

= 1.3.0 =
Improved WordPress.org compliance — better escaping, i18n support, and optional branding for all tiers.

= 1.2.0 =
Simplified setup — API URL is now hardcoded for reliability. No action needed on upgrade.
