=== SendToMP ===
Contributors: binarybeagle
Tags: mp, parliament, democracy, constituency, advocacy
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.6.3
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

1. Looking up an MP by postcode within a Gravity Form 
2. Settings page — configure your email templates, rate limits, and form field mappings
3. Delivery log — track every submission with status, timestamps, and MP details
4. Configuring a Gravity Form feed to send submissions to your visitor's MP
5. Custom field type in Gravity Forms

== Changelog ==

= 1.6.3 =
* **Rich HTML confirmation email.** The email sent to constituents asking them to confirm their message is now a branded, responsive HTML email with a prominent "Confirm and send my message" button and a styled preview of the message as it will reach the MP. Plain-text fallback is still sent alongside for accessibility and older clients.
* **Customisable confirmation email** — two new fields on the Confirmation tab: "Confirmation Email Logo" (URL to a site logo shown at the top of the email) and "Confirmation Email Intro" (optional message shown above the confirmation body, supports basic HTML). Themes for further customisation are on the roadmap.
* Fix: SendToMP tokens (`{mp_name}`, `{mp_constituency}`, etc.) inside the message preview shown to the constituent are now resolved at confirmation-send time — previously the constituent saw the raw placeholders in their preview.
* Fix: any Gravity Forms merge tags (`{Post Code:4}` etc.) that survived feed processing because they referenced deleted fields are now stripped from the preview rather than shown as literal text.
* Footer of the confirmation email uses the Bluetorch logo (unless the Pro "Hide Bluetorch branding" setting is on).

= 1.6.2 =
* Fix the "Find My MP" custom field not being used for postcode lookup when a feed also had a legacy `fieldMap_constituent_postcode` mapping pointing at a deleted text field. The custom field now takes priority over any feed mapping whenever it's present on the form — matching the drag-and-drop "just works" UX promise introduced in v1.5.0. Forms that still use a plain postcode text field plus a mapping continue to work unchanged.

= 1.6.1 =
* Email Delivery tab now shows a green success banner and a tick on the active provider tile when delivery is configured — a clear "this is working" signal without having to send a test email.
* Dismissed the "No SMTP plugin detected" warning when SendToMP's own Email Delivery is configured via Brevo or Custom SMTP. The warning (and the matching sidebar box) now reflect that delivery is OK whenever either a built-in provider is configured or a third-party SMTP plugin is active.
* Renamed the sidebar "SMTP Required" box to "Email Delivery" and rewrote its copy — an SMTP plugin is one of two ways to configure delivery now, not the only one.

= 1.6.0 =
* **New: provider tile picker on the Email Delivery tab.** Site owners now choose how SendToMP sends mail from a visual grid of options — detected SMTP plugin (passthrough), Brevo via direct HTTP API, Custom SMTP with your own host/port/credentials, Google Workspace (coming v1.7, Pro), Office 365 (coming v1.8, Pro), or the WordPress default fallback. Selecting a tile reveals that provider's config panel; SendToMP dispatches sends through the chosen provider. No more "read the docs and install a second plugin" shuffle.
* **New: built-in Brevo integration** (recommended for parliamentary deliverability). Paste your Brevo v3 API key and every MP message and confirmation email is sent via the Brevo transactional HTTP API — better deliverability, bounce handling, and observability than raw SMTP.
* **New: bring-your-own SMTP.** Enter host, port, encryption, username, and password directly in SendToMP without needing a separate SMTP plugin. Credentials are encrypted at rest via AES-256-GCM (optionally with an external key via the `SENDTOMP_SECRET_KEY` wp-config constant) so a database dump doesn't leak them. Defers gracefully when a dedicated SMTP plugin is also active.
* **New: branded admin header.** Every SendToMP admin page now opens with the SendToMP logo, tagline, and a version chip — the utility plugin is starting to feel like a product.
* Assets served by the admin now cache-bust on file mtime, so CSS/JS tweaks within a release cycle land in browsers immediately instead of waiting on a version bump.
* Under the hood: mailer refactored around a provider interface (`SendToMP_Provider_Interface`), new `SendToMP_Secret` helper for encrypted settings, and a `SendToMP_Mailer::dispatch()` seam that routes every send through the selected provider. Ground-work for the Pro-tier Google Workspace and Office 365 OAuth flows landing in v1.7 and v1.8.

= 1.5.1 =
* The "Find My MP" field now shows its own helper text in the form editor sidebar ("Allows users to find their MP by entering their UK post code") instead of the generic Single Line Text description inherited from Gravity Forms.

= 1.5.0 =
* **New: "Find My MP" custom Gravity Forms field.** Site owners can now drag a first-class "Find My MP" field straight from the Gravity Forms field picker (Advanced Fields group) onto any form. The field renders a UK postcode input pre-wired to the SendToMP lookup — visitors see the Find my MP button and MP portrait preview immediately, without any CSS class or feed mapping configuration. Dramatically simpler to set up than the previous "add a text field, then go to the feed, then map it to constituent_postcode" flow, especially for non-technical site owners.
* When a SendToMP feed doesn't have a postcode field mapped but the form contains a "Find My MP" field, the plugin now auto-uses that field as the postcode source at submission time. Existing forms that still use a plain text field + feed mapping continue to work unchanged.
* Minor: the form-editor warning introduced in 1.4.19 (postcode field without a feed) stays in place as a safety net for forms that still take the legacy mapping path.

= 1.4.19 =
* Rename the "Show Live MP Preview" setting to "Enable Find My MP Button for Post Code" so its label matches the button it actually controls.
* New warning on the Gravity Forms form editor: when a form has a postcode field but no active SendToMP feed, a yellow admin notice appears at the top of the editor with a "Create SendToMP feed" button that deep-links to the feed creation screen. Avoids the confusing "I built the postcode field but the Find My MP button isn't showing up" trap at preview time.

= 1.4.18 =
* Add a visible "Find my MP" button next to the postcode field for accessibility and usability. The existing debounced auto-lookup on typing/blur is unchanged — the button is a parallel affordance for keyboard users, screen reader users, and anyone who prefers an explicit action. The preview region is now marked as `aria-live="polite"` so assistive tech announces results as they appear.

= 1.4.17 =
* The handoff reminder on the Gravity Forms Confirmations page now includes a ready-to-paste HTML confirmation message and a one-click Copy HTML button. Site owners no longer need to write their own confirmation copy.

= 1.4.16 =
* Fix the confirmation-handoff reminder silently bailing because the plugin was querying Gravity Forms for feeds using the wrong slug. Feeds are stored with `addon_slug = 'gravity-forms'` rather than `'sendtomp'` due to an internal interface collision; the reminder now uses the correct slug and appears on the Confirmations page as intended.

= 1.4.15 =
* Fix the confirmation-handoff reminder not appearing on the Gravity Forms Confirmations page. Gravity Forms' custom admin pages don't surface WordPress' standard `admin_notices` output — they route notices through their own `gform_admin_messages` filter. Switched to that filter so the reminder shows reliably.

= 1.4.14 =
* Fix the confirmation-handoff admin notice not appearing on the Gravity Forms Confirmations page — it's now registered from the main plugin's admin class (which fires reliably on every admin page) rather than from inside the GF adapter's feed add-on lifecycle.

= 1.4.13 =
* New reminder on the Gravity Forms feed editor and the form's Confirmations page: visitors need to click a link in their email to confirm the message before it reaches the MP, so the default "Thanks, we'll be in touch" confirmation is misleading. Site owners are now prompted to update the confirmation text.

= 1.4.12 =
* Refreshed WordPress.org listing screenshots — added a shot of the live MP postcode lookup inside a Gravity Form and a shot of the Gravity Forms feed configuration screen. No functional changes.

= 1.4.11 =
* Live MP preview now shows the MP's official parliamentary portrait alongside their name and constituency — a richer at-a-glance check that the right MP has been matched before submission. Image is sourced from the Parliament Members API and shown only when served over HTTPS from that host.

= 1.4.10 =
* Fix live MP preview not appearing — Gravity Forms attaches the CSS class to the field wrapper rather than the input, so the lookup script was binding to the wrong element. Script now descends into the wrapper to find the text input.
* Set autocomplete="postal-code" on postcode inputs so browsers offer sensible address autofill.

= 1.4.9 =
* New "Show Live MP Preview" setting on the General tab — toggle on to reveal the matched MP's name and constituency under the postcode field as visitors type
* No CSS classes required: the plugin finds the field you mapped as the postcode in your Gravity Forms SendToMP feed and attaches the preview automatically

= 1.4.8 =
* Replace the TinyMCE WYSIWYG with a Markdown editor for the Gravity Forms Message Body — more reliable, cleaner to save, visible syntax, no external library dependencies
* New toolbar with Bold, Italic, Link, Bulleted List, Numbered List, Quote, and Heading buttons that insert Markdown syntax at the cursor position
* Merge tag picker is now built into the editor toolbar
* Collapsible "Formatting guide" below the editor explains the Markdown syntax
* Markdown is automatically converted to HTML when the email is delivered to the MP — bold, italic, lists, links, blockquotes, and headings all render correctly

= 1.4.7 =
* Fix Message Body not saving in the Gravity Forms feed — the custom visual_editor field type was not being persisted by Gravity Forms. Message Body is now a standard textarea (so GF handles save, load, and validation natively) that is upgraded to a TinyMCE editor client-side via wp.editor.initialize().
* Merge tag picker is now rendered by a small JavaScript file (sendtomp-gf-rich-editor.js) that also handles cursor-position insertion into TinyMCE or the raw textarea when in Code mode.

= 1.4.6 =
* Add a merge tag picker above the Message Body WYSIWYG editor — TinyMCE hides the underlying textarea so the native GF merge-tag-support class no longer attaches. The new picker groups form fields and SendToMP tokens and inserts at the cursor position.

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

= 1.6.3 =
Rich HTML confirmation email, with customisable logo and intro message on the Confirmation tab. Placeholders now resolve properly in the message preview.

= 1.6.2 =
Fixes a bug where forms migrated to the "Find My MP" field kept failing postcode lookup because the old feed mapping was overriding it.

= 1.6.1 =
Polishes the Email Delivery UX: green tick on the active provider tile, and no more "install an SMTP plugin" warning once delivery is configured.

= 1.6.0 =
New Email Delivery provider picker — choose from Brevo (direct API), Custom SMTP, or your existing SMTP plugin. Branded admin header. Encrypted storage for API keys and SMTP passwords.

= 1.5.1 =
Small polish: the Find My MP field now has its own description in the form editor instead of the generic "single line of text" text.

= 1.5.0 =
Adds a drag-and-drop "Find My MP" field to the Gravity Forms field picker — much easier to set up than the previous text-field-plus-feed-mapping flow.

= 1.4.10 =
Fixes the live MP preview on Gravity Forms postcode fields.

= 1.4.9 =
Adds a site-wide toggle for the live MP preview on forms — no per-field CSS class required.

= 1.4.8 =
Message Body is now a Markdown editor with a formatting toolbar and cheatsheet — more reliable than the previous WYSIWYG.

= 1.4.7 =
Fixes Message Body not saving in the Gravity Forms feed.

= 1.4.6 =
Restores the merge tag picker above the Message Body editor.

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
