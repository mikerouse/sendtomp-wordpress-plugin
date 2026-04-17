# SendToMP — Full Rebuild Plan

## Context

SendToMP is a 2024 skeleton WordPress plugin (v0.1) that was intended as a Gravity Forms addon for sending constituent messages to UK MPs. It currently contains only bootstrap scaffolding with no functionality. The user wants to rebuild it as a fully-featured, commercially licensed product supporting multiple form plugins, both Houses of Parliament, and a middleware layer for routing overrides.

The product owner (Mike Rouse / Bluetorch) is a former MP's assistant with first-hand knowledge of how MP offices process inbound correspondence. This domain expertise informs key design decisions around delivery, formatting, and credibility.

## The Four Requirements

1. **Subscription/licensing model** — licence key required for updates
2. **Multi-form-plugin support** — Gravity Forms, WPForms, Contact Form 7, Zapier
3. **Middleware layer** — between plugin and Parliament API for address overrides/routing
4. **House of Lords support** — peers as well as MPs (Commons)

---

## 1. Licensing: Freemium (Free / Plus / Pro) via Bluetorch Website

**Approach:** Freemium model with three tiers. Custom licensing built on the existing Bluetorch website stack (Next.js + Vercel + Supabase). The same Supabase project used for the middleware handles licensing. The Bluetorch website serves as the sales/marketing/purchase channel.

**Why custom over EDD/third-party:**
- Already running Supabase for middleware — no additional infrastructure
- Full control over licensing logic, no £99/year EDD addon cost or 7% Freemius cut
- Licensing is just 4 simple API endpoints + a database table
- Sales flow lives on the Bluetorch Next.js site (Stripe checkout → webhook → generate license)
- No WordPress dependency on the licensing server side

### Tier Structure

| Feature | Free | Plus | Pro |
|---------|------|------|-----|
| Confirmed messages/month | 25 | Unlimited | Unlimited |
| House of Commons | Yes | Yes | Yes |
| House of Lords | — | Yes | Yes |
| Form adapters | Gravity Forms only | All (GF, WPForms, CF7) | All + Webhook/API Platform |
| "Powered by Bluetorch's SendToMP" branding | Mandatory (not removable) | Configurable (on by default) | Removable / white-label |
| BCC support | — | Yes | Yes |
| Email template customisation | Basic (subject only) | Full (subject + body + confirmation) | Full |
| Local address overrides | — | Yes | Yes |
| Webhook API (platform integrations) | — | — | Yes (with `skip_confirmation` option) |
| Analytics dashboard | Basic (total sent, confirmation rate) | Detailed (per-MP, time series) | Detailed + CSV export |
| Campaign directory listing | — | Opt-in | Opt-in + featured |
| Site activations | 1 | 1 | Up to 5 |
| Support | Community/docs | Email support | Priority email support |
| Price | £0 | ~£19/month (annual: £190/year) | ~£49/month (annual: £490/year) |

**Free tier rationale:** 25 confirmed messages/month costs near-zero to support (Supabase free tier). Generates branded impressions on every confirmation page and MP email. Provides a zero-friction adoption path. Conversion to Plus happens when the site owner hits the message limit, needs Lords, wants additional form adapters, or wants branding control.

### Feature Gating in the Plugin

The plugin checks the license tier via the cached license response. Feature gating is done via a simple `sendtomp_can($feature)` helper:
- `sendtomp_can('lords')` — false on Free
- `sendtomp_can('bcc')` — false on Free
- `sendtomp_can('webhook_api')` — true only on Pro
- `sendtomp_can('remove_branding')` — true only on Pro
- `sendtomp_can('local_overrides')` — false on Free
- etc.

When a gated feature is accessed, the admin UI shows a contextual upgrade prompt: "This feature is available on Plus. [Upgrade →]"

The Free tier does NOT require a license key. The plugin works out of the box with Free-tier defaults. Plus and Pro require a license key obtained via the Bluetorch website.

### Supabase: Licensing Tables

**`licenses`**
- `id` (uuid, PK)
- `license_key` (text, unique, indexed) — generated on purchase (e.g. `SENDTOMP-XXXX-XXXX-XXXX-XXXX`)
- `customer_email` (text)
- `customer_name` (text)
- `product_slug` (text) — "sendtomp"
- `tier` (text) — "plus" or "pro" (free tier has no license record)
- `max_activations` (integer) — 1 for Plus, 5 for Pro
- `status` (text) — "active", "expired", "revoked"
- `expires_at` (timestamptz, nullable) — null = lifetime
- `created_at` (timestamptz)

**`license_activations`**
- `id` (uuid, PK)
- `license_id` (uuid, FK → licenses)
- `site_url` (text) — the WordPress site URL that activated
- `activated_at` (timestamptz)
- `deactivated_at` (timestamptz, nullable)

**`plugin_releases`**
- `id` (uuid, PK)
- `product_slug` (text)
- `version` (text) — e.g. "1.0.0"
- `download_url` (text) — URL to the zip (Supabase Storage or Vercel)
- `changelog` (text)
- `requires_wp` (text) — minimum WP version
- `tested_wp` (text) — tested up to WP version
- `released_at` (timestamptz)

### Supabase: Licensing Edge Functions

**`/license/activate`** — POST `{ license_key, site_url }`
- Validate key exists and status = "active"
- Check not expired
- Check activation count < max_activations
- Insert into `license_activations`
- Return `{ valid: true, expires_at, customer_email }`

**`/license/deactivate`** — POST `{ license_key, site_url }`
- Set `deactivated_at` on the matching activation row
- Return `{ deactivated: true }`

**`/license/check`** — POST `{ license_key, site_url }`
- Validate key, check status + expiry + activation exists for this site
- Return `{ valid: true/false, status, expires_at }`

**`/license/check-update`** — POST `{ license_key, site_url, current_version }`
- Validate license (same as check)
- Query latest `plugin_releases` row for this product
- If newer version exists AND license valid: return `{ update_available: true, version, download_url, changelog }`
- If license invalid/expired: return `{ update_available: false, reason: "license_expired" }`

### Bluetorch Website (Next.js/Vercel)

- Product/sales page for SendToMP
- Stripe Checkout for purchases
- Stripe webhook handler (Vercel API route) → on successful payment, generate license key, insert into Supabase `licenses` table, email key to customer
- Customer portal page: view license key, activations, expiry, download latest version
- Stripe webhook for subscription renewal/cancellation → update `licenses.status` and `expires_at`

### What Goes in the WordPress Plugin

- `includes/class-sendtomp-license.php` — license key storage (`wp_options`), activation/deactivation calls to Supabase edge functions, tier detection, `sendtomp_can($feature)` helper, status caching (24hr transient)
- `includes/class-sendtomp-updater.php` — custom updater class (~200 lines) that hooks `pre_set_site_transient_update_plugins` and `plugins_api` to check for updates via the `/license/check-update` endpoint
- License/tier settings on the plugin's admin settings page
- Free tier works without a license key — no registration required
- Contextual upgrade prompts when gated features are accessed
- Plugin continues to function with expired Plus/Pro license at Free tier level, but updates stop

---

## 2. Architecture: Standalone Plugin with Form Adapters

The plugin is **no longer a Gravity Forms addon**. It becomes a standalone WordPress plugin with a modular adapter pattern for form integrations.

### Plugin Structure

```
sendtomp/
  sendtomp.php                              # Bootstrap, constants, autoloader, updater init
  uninstall.php                             # Cleanup on uninstall

  includes/
    class-sendtomp.php                      # Main singleton: adapter registry, detection, init
    class-sendtomp-submission.php           # Normalised submission data object
    class-sendtomp-api-client.php           # Middleware API client (calls Supabase edge fn)
    class-sendtomp-confirmation.php          # Double-opt-in: token generation, storage, verification
    class-sendtomp-mailer.php               # Email composition + sending (via wp_mail / site SMTP)
    class-sendtomp-license.php              # License management (calls Supabase licensing endpoints)
    class-sendtomp-updater.php              # Custom WP updater (hooks update_plugins transient)
    class-sendtomp-logger.php               # Submission logging
    class-sendtomp-rate-limiter.php         # Rate limiting and abuse prevention

  admin/
    class-sendtomp-admin.php                # Admin pages, menu registration
    class-sendtomp-settings.php             # Global settings page (licence, email, API, overrides)
    views/
      settings-page.php                     # Main settings (tabbed: General, Email, License, Log)
      confirmation-page.php                 # Frontend confirmation page template
      logs-page.php                         # Submission log viewer with status/filters

  adapters/
    interface-sendtomp-form-adapter.php     # Adapter contract
    abstract-sendtomp-form-adapter.php      # Shared adapter logic
    gravity-forms/
      class-sendtomp-gf-adapter.php         # Extends GFFeedAddOn for native feed UI
    wpforms/
      class-sendtomp-wpforms-adapter.php    # Hooks wpforms_process_complete
    contact-form-7/
      class-sendtomp-cf7-adapter.php        # Hooks wpcf7_before_send_mail
    webhook/
      class-sendtomp-webhook-adapter.php    # WP REST API endpoint for inbound webhooks

  assets/
    css/sendtomp-admin.css
    js/sendtomp-admin.js
```

### Adapter Pattern

Each adapter implements `SendToMP_Form_Adapter_Interface`:
- `is_plugin_active()` — detection (e.g. `class_exists('GFForms')`)
- `register_hooks()` — hook into form submission events
- `get_available_forms()` / `get_form_fields($form_id)` — for field mapping UI

All adapters normalise submission data into a common `SendToMP_Submission` object:
- `constituent_name`, `constituent_email`, `constituent_postcode`, `constituent_address` (optional)
- `message_subject`, `message_body`
- `target_house` — "commons" or "lords"
- `target_member_id` — optional, if targeting a specific peer/MP
- `source_adapter`, `source_form_id`, `raw_data`, `metadata`

### Form Plugin Integration Details

| Plugin | Hook | Field Mapping UI | Notes |
|--------|------|-----------------|-------|
| **Gravity Forms** | `GFFeedAddOn::process_feed()` | Native GF `field_map` setting type in feed editor | Best integration — GF's feed framework does the heavy lifting |
| **WPForms** | `wpforms_process_complete` | Custom panel via `wpforms_builder_settings_sections` filter | No formal addon framework; use hooks directly |
| **Contact Form 7** | `wpcf7_before_send_mail` | Custom tab via `wpcf7_editor_panels` filter | Use `scan_form_tags()` to extract available fields |
| **Webhook/API Platform** | WP REST API `POST /wp-json/sendtomp/v1/submit` | N/A — caller sends named fields | **Pro tier only.** Authenticated via API key; works with Zapier, n8n, Make, CRMs, or any HTTP client |

**Webhook/API Platform (Pro tier only):** This is a full REST API for custom integrations, positioned as the "SendToMP API Platform." It enables CRM systems (Salesforce, HubSpot), petition platforms, automation tools (Zapier, n8n, Make), and custom applications to send verified messages to MPs programmatically. Documented with an OpenAPI spec. No Zapier app listing is required — any tool that can POST JSON can use it.

**Webhook confirmation bypass:** The webhook adapter supports a `skip_confirmation` flag (default: `false`). When set to `true` in the plugin settings, webhook submissions skip the double opt-in flow and send directly to the MP. This is for cases where the upstream system has already verified the sender independently. This flag requires a separate, higher-privilege API key (distinct from the standard webhook key) to prevent misuse. The admin UI clearly warns that enabling this bypasses GDPR consent collection and that the site owner takes responsibility for obtaining consent upstream.

**Stub note:** The Webhook/API Platform is a Pro-level feature. The REST endpoint, authentication, and basic documentation will be built. Advanced platform features (OpenAPI spec, integration guides for specific platforms, rate limit tiers per API key) are stubbed for a future pass.

### Data Flow (Double Opt-In)

The message to the MP is **never sent immediately**. Every submission goes through a confirmation step where the constituent verifies their email and explicitly consents.

```
STEP 1 — Form Submission (immediate)
  Form submission → Adapter intercepts → Normalise to SendToMP_Submission
  → Validate (required fields, email format, postcode format)
  → Rate limit check (per-email, per-IP, per-postcode)
  → Call middleware /resolve-member (postcode + house)
  → Store pending submission in DB (custom table: sendtomp_pending)
  → Send confirmation email TO THE CONSTITUENT (not the MP)
  → Show "Check your email" message to the form submitter
  → Log as "pending_confirmation"

STEP 2 — Confirmation (constituent-initiated)
  Constituent receives email with a unique token link
  → Clicks link → lands on confirmation page on the WordPress site
  → Page displays: MP name, their message preview, and a "Confirm & Send" button
  → Constituent clicks the button (POST, not just the GET — defeats link scanners)
  → Plugin retrieves pending submission by token
  → Validates token is not expired (24hr TTL)
  → Composes and sends the actual email to the MP (via site's SMTP)
  → Marks submission as "confirmed_and_sent" in DB
  → Shows thank-you page with MP details
  → Logs result
```

**Why the two-step button click:** Email security scanners (Microsoft SafeLinks, Proofpoint, Barracuda, etc.) automatically follow links in emails to check for malware. A simple "click to confirm" link would be triggered by these scanners, sending messages without the constituent's knowledge. The confirmation page with a POST button prevents this — scanners follow GET requests but do not submit forms.

### Messaging the Confirmation Step to Site Owners

The confirmation flow will reduce the raw number of messages sent (some people won't complete the second step). This is deliberately positioned as a **feature, not a limitation**, in the plugin's admin UI and documentation.

**On the settings page (Confirmation tab), a prominent info panel explains:**

> **Why email confirmation matters for your campaign**
>
> Every message sent through SendToMP is verified before it reaches your MP. This means:
>
> - **GDPR compliant.** Each sender gives explicit, auditable consent before their personal data is shared. This protects you as the site owner and data controller.
> - **Every message is real.** No fake email addresses, no bot submissions, no accidental sends. Every message that lands in an MP's inbox comes from a verified, real person who actively chose to send it.
> - **MPs take verified messages seriously.** MP offices receive thousands of emails. Mass-generated messages with no verification are routinely ignored or filtered. Verified messages from confirmed constituents are read and acted upon. Quality beats quantity.
> - **Your campaign's reputation is protected.** One spam complaint from an MP's office could get your domain blacklisted. The confirmation step ensures you never send a message someone didn't explicitly ask to send.
>
> Campaigns using verified messages consistently achieve higher response rates from MPs than those that prioritise volume.

**On the dashboard/general tab, a stats summary shows:**
- Submissions received (form completions)
- Confirmations completed (emails verified and sent)
- Confirmation rate (percentage)
- This gives the site owner visibility into the funnel without treating drop-off as a problem

### Adapter Loading

On `plugins_loaded` (priority 20), the core class iterates registered adapters, calls `is_plugin_active()`, and only loads matching ones. Webhook adapter always loads.

---

## 3. Email Delivery

**This is the most critical design decision.** The plugin does NOT rely on WordPress's default `mail()` function.

### Approach: Site Owner's SMTP Infrastructure

The plugin sends emails via `wp_mail()` but **requires the site owner to have a proper SMTP plugin configured** (e.g. WP Mail SMTP, FluentSMTP, Post SMTP) connected to a transactional email service (Brevo, SendGrid, Postmark, Amazon SES, etc.). This is explicitly stated during setup and enforced with a health check.

**Why this approach:**
- The site owner already has email infrastructure for their WordPress site
- Transactional email services handle SPF/DKIM/DMARC alignment
- The email comes from a domain the site owner controls, with proper authentication
- Parliament email gateways are far more likely to accept properly authenticated email from a real domain than from a random server's MTA
- Bluetorch is not the sender of record — no liability for content, no email reputation to manage
- The site owner has full control over deliverability

### Email Configuration Settings (Plugin Admin)

The site owner configures in the SendToMP settings page:

- **From Address:** The email address messages are sent from (e.g. `campaigns@their-site.org`). Defaults to the WordPress admin email but should be customisable.
- **From Name:** The display name (e.g. "Their Campaign Name" or the constituent's name — site owner chooses)
- **Reply-To:** Set to the constituent's email address so the MP can reply directly to the constituent
- **BCC Address(es):** Optional. The site owner may want a copy of every outbound message (e.g. for record-keeping, compliance, or campaign tracking). Multiple addresses supported, comma-separated.
- **Email Template:** A configurable template with placeholders: `{constituent_name}`, `{constituent_email}`, `{constituent_postcode}`, `{constituent_address}`, `{message_body}`, `{mp_name}`, `{mp_constituency}`. Default template provided but fully editable.
- **Subject Line Template:** Configurable with placeholders (e.g. "Message from {constituent_name} in {mp_constituency}")

### Email Health Check

On the settings page, a "Test Email Configuration" button:
1. Sends a test email to the admin's address via the configured SMTP
2. Checks that `wp_mail()` returns true
3. Displays a warning if no SMTP plugin is detected (checks for known SMTP plugin classes)
4. Displays the detected sending method (SMTP plugin name, configured service)
5. Warns if the From Address domain doesn't match the site domain (SPF risk)

### What the MP Receives

A well-formatted email that looks like genuine constituent correspondence:
- **From:** `campaigns@their-site.org` (the site's configured sender)
- **Reply-To:** `constituent@their-email.com` (the actual constituent)
- **Subject:** "Message from Jane Smith in Bromsgrove" (configurable template)
- **Body:** The constituent's message, with their name, address/postcode, and email clearly shown. Plain text preferred (MP offices often strip HTML). A brief footer identifying the sending system.

The MP can hit "Reply" and it goes directly to the constituent. This mirrors how WriteToThem works.

### SMTP Setup Guidance

The plugin settings page includes a brief guidance section:
- "SendToMP requires a properly configured SMTP service to deliver emails reliably."
- Links to recommended SMTP plugins (WP Mail SMTP, FluentSMTP)
- **Recommended transactional email service: Brevo** (featured first, with Bluetorch partner/affiliate link). Brevo offers a generous free tier (300 emails/day) and excellent deliverability. Bluetorch is a Brevo partner — affiliate commission on signups via the plugin's recommendation link.
- Alternative services listed: SendGrid, Postmark, Amazon SES
- A warning banner if no SMTP plugin is detected
- A "Quick Start with Brevo" mini-guide with step-by-step instructions for the most common setup path (install WP Mail SMTP → connect to Brevo → verify domain → done)

---

## 4. Abuse Prevention and Rate Limiting

### Primary Defence: Double Opt-In

The confirmation flow (Section 2, Data Flow) is the single most effective abuse prevention measure. No message reaches an MP unless the constituent:
1. Submitted the form (intent)
2. Received the confirmation email (proves real email)
3. Clicked the link AND clicked the "Confirm & Send" button (proves human, proves intent)

This eliminates: bots, fake email addresses, accidental submissions, and email scanner false triggers.

### Rate Limiting (`class-sendtomp-rate-limiter.php`)

Rate limits are applied at **Step 1 (form submission)**, before the confirmation email is sent. This prevents abuse of the confirmation email system itself.

Implemented using WordPress transients (no additional database tables needed):

- **Per email address:** Max 3 submissions per 24 hours (prevents one person spamming)
- **Per IP address:** Max 10 submissions per 24 hours (prevents bot floods from one source)
- **Per postcode:** Max 20 submissions per 24 hours per site (prevents targeted MP flooding)
- **Global per site:** Max 100 submissions per 24 hours (safety net)
- All limits configurable by the site owner in settings

Rate limit keys: `sendtomp_rl_{type}_{hash}` as transients with TTL.

When a limit is hit, the form submission is rejected with a user-friendly message ("You have already sent a message recently. Please try again later.") and the attempt is logged.

### Additional Abuse Measures

- **Honeypot field:** A hidden form field that bots fill in but humans don't. If populated, submission is silently rejected.
- **Duplicate detection:** Hash the `email + postcode + message_body`. If an identical pending or confirmed submission exists within 24 hours, reject at form submission stage.
- **Token security:** Confirmation tokens are 64-character cryptographically random strings (via `wp_generate_password(64, false)`). Tokens are single-use — once confirmed, the token is invalidated. Tokens expire after 24 hours.
- **Confirmation page CSRF protection:** The "Confirm & Send" button submits a POST request with a WordPress nonce, preventing cross-site forgery.
- **Integration with form plugin CAPTCHAs:** GF, WPForms, and CF7 all have their own CAPTCHA/reCAPTCHA integrations. The plugin documentation recommends enabling these on SendToMP forms. The plugin does NOT add its own CAPTCHA (avoids duplication and UX friction).

### Blocklist (future enhancement)

A `sendtomp_blocklist` option storing blocked email patterns and IPs. Initially managed via Supabase Studio or WP CLI. Admin UI can be added later if needed.

---

## 5. Address Overrides: Two-Tier System

### Tier 1: Global Overrides (Bluetorch-managed, via Supabase)

Stored in the `member_address_overrides` table in Supabase. These apply to ALL sites using the plugin.

**Use case:** An MP has told Bluetorch directly "send my messages to my constituency office, not my Parliament address." Or the Parliament API data is known to be wrong/outdated.

**Management:** Via Supabase Studio initially. Bluetorch admin adds/edits/disables overrides. A simple admin UI on the Bluetorch website can be built later if volume warrants it.

**`member_address_overrides`** (Supabase)
- `parliament_member_id` (integer, unique) — Parliament API member ID
- `member_name` (text) — for admin convenience
- `house` (text) — "commons" or "lords"
- `override_email` (text, nullable)
- `override_address_line1`–`line5`, `override_postcode` (nullable)
- `override_notes` (text) — why this override exists (audit trail)
- `is_active` (boolean)
- `created_by` (text) — who created it
- Timestamps

### Tier 2: Local Overrides (Site owner-managed, in WordPress)

Stored in WordPress `wp_options` or a small custom table. These apply only to the individual site.

**Use case:** A campaign organisation has a direct relationship with a specific MP and knows their preferred contact address. Or the site owner wants to route messages to a specific aide/office.

**Management:** Via the SendToMP settings page in WordPress admin, under an "Address Overrides" tab. Simple form: search for MP by name → enter override email/address → save.

**How the two tiers interact:**
1. Plugin calls middleware `/resolve-member` → middleware checks Supabase global overrides → returns delivery details
2. Plugin then checks local overrides for that member ID
3. **Local overrides take precedence over global overrides** — the site owner's direct knowledge of their target MP trumps Bluetorch's general data
4. Both layers are logged in the delivery log so it's clear which override was applied

---

## 6. Middleware: Supabase Edge Functions + Database

**Why Supabase:**
- Already in use for the Bluetorch website and for licensing (single Supabase project for everything)
- Free tier covers this scale (50K MAU, 500MB DB, 2M edge function invocations)
- Edge Functions (Deno) = serverless API with zero server management
- Postgres DB for overrides, cache, routing rules
- Supabase Studio = instant admin UI for managing overrides (no custom UI needed initially)
- Total cost: £0–25/month

### Supabase Downtime Handling

Supabase downtime is accepted as a risk. Short periods of downtime are manageable so long as the user experience is handled gracefully:

- **Middleware unreachable:** Plugin catches the error and displays a user-friendly message: "We're temporarily unable to process your message. Please try again in a few minutes." The form submission is NOT lost — it is queued in a WordPress transient and retried on next page load or via WP-Cron (max 3 retries over 1 hour).
- **License check fails:** Plugin uses the cached license status (24hr transient). If the transient is also expired, the plugin assumes the license is still valid (fail-open for functionality, fail-closed for updates).
- **Admin notice:** If the middleware has been unreachable for >1 hour, show an admin notice to the site owner.

### Database Tables

**`member_address_overrides`** — see Section 5 (Tier 1)

**`member_cache`**
- `cache_key` (text, PK) — e.g. `postcode:SW1A1AA`, `member:5257:contact`
- `response_data` (jsonb) — cached Parliament API response
- `expires_at` (timestamptz)

**`delivery_log`**
- `id` (uuid, PK)
- `request_postcode` (text)
- `resolved_member_id` (integer)
- `resolved_member_name` (text)
- `house` (text)
- `override_applied` (text, nullable) — "global", "local", or null
- `delivery_method` (text) — "email"
- `delivery_status` (text) — "sent", "failed", "queued", "rate_limited"
- `site_origin` (text) — which customer site
- `error_message` (text, nullable)
- `created_at` (timestamptz)
- **Note:** Constituent PII (name, email, message content) is NOT stored in Supabase. Only the delivery metadata. PII stays on the WordPress site's own logs. See GDPR section.

**`api_keys`**
- Per-customer-site API keys for authenticating plugin→middleware calls
- Linked to `license_activations` — generated when a license is activated

### Edge Functions

**`/resolve-member`** (primary endpoint):
1. Accept `{ postcode, house: "commons"|"lords" }` + API key header
2. Validate API key
3. Check `member_cache` → if fresh, return cached data
4. If cache miss/expired, call Parliament Members API:
   - Commons: `/api/Members/Search?Location={postcode}&House=1&IsCurrentMember=true`
   - Lords: `/api/Members/Search?House=2&Name={query}&IsCurrentMember=true` (see Section 7)
5. Cache the response
6. Fetch contact details from `/api/Members/{id}/Contact`, cache those too
7. Check `member_address_overrides` for this member → apply if active
8. Return unified response:
```json
{
  "success": true,
  "member": {
    "id": 5257,
    "name": "Rachel Blake",
    "party": "Labour",
    "constituency": "Cities of London and Westminster",
    "house": "commons"
  },
  "delivery": {
    "email": "rachel.blake.mp@parliament.uk",
    "override_applied": false
  }
}
```

**`/search-members`** (Lords and general search):
- Accept `{ house: "lords", query: "..." }` or `{ house: "lords", party: "..." }`
- Returns a list of matching members for selection

**`/log-delivery`** (fire-and-forget from plugin):
- Accept delivery metadata (no PII)
- Insert into `delivery_log`

### Cache Durations
- Postcode→member lookup: 7 days (invalidated manually after elections)
- Contact details: 24 hours
- Full member list: 7 days
- **Election mode:** A manual flag in Supabase that flushes all caches and sets TTLs to 1 hour during election periods

### Parliament API Resilience
- No documented rate limits or SLA — treat as unreliable
- Always serve from cache when available, even if slightly stale
- If Parliament API is down: return cached data with a `"cached": true, "cache_age": "3d"` flag
- If Parliament API is down AND no cache: return an error that the plugin handles gracefully
- Monitor Parliament API response times and errors via Supabase logging

---

## 7. House of Lords Support

The Parliament Members API covers both Houses:
- `House=1` = Commons (MPs)
- `House=2` = Lords (Peers)

### Key Differences from Commons

- **No constituency link** — Peers don't represent postcodes. You can't look up "my Lord" by postcode.
- **Targeting approach:** The form must allow selecting a specific Peer by name/title, OR targeting Peers by party/interest.
- **Use cases:** Lobbying ahead of a Lords vote on a specific bill, contacting a specific Peer who chairs a committee.

### Known Limitation: Lords Contact Data

Most Lords share a generic email address (`contactholmember@parliament.uk`) rather than having individual email addresses in the Parliament API. This significantly limits the usefulness of automated Lords contact.

**Mitigation strategies:**
- **Global overrides:** Bluetorch can maintain a curated set of individual Peer email addresses in the override table for Lords where individual addresses are known (some Peers do have personal/office addresses discoverable via their websites).
- **Local overrides:** Site owners running Lords-focused campaigns can add specific Peer contact details they have sourced directly.
- **Physical mail consideration (future):** For Lords with no email, a future enhancement could format messages for printing and posting via a mail service. This is out of scope for v1 but architecturally the middleware response could include a `delivery_method: "print"` flag.
- **Transparency:** The plugin UI should clearly indicate when a Peer's contact is via the shared inbox ("This message will be sent to the House of Lords general contact and marked for the attention of [Peer Name]") so the site owner and submitter understand the limitation.

### Implementation

**Member resolution for Lords:**
- The middleware `/search-members` endpoint: `{ house: "lords", query: "..." }` or `{ house: "lords", party: "..." }`
- Returns a list for the user to select from (or for the form to pre-configure)
- Each result includes a `contact_quality` field: "direct" (individual email known), "shared" (generic inbox only), "override" (custom address from overrides)

**Form field additions:**
- `target_house` field (Commons or Lords) — could be a hidden field if the form is specifically for one House
- For Lords: a member selector (autocomplete/dropdown) instead of postcode lookup
- For Commons: postcode field as before

**Submission object:**
- `target_house`: "commons" or "lords"
- `target_member_id`: required for Lords (selected peer), optional for Commons (resolved from postcode)

---

## 8. GDPR and Data Protection

### Lawful Basis

- **Explicit consent via double opt-in** — the confirmation flow (Section 2, Data Flow) provides clear, affirmative, auditable consent:
  1. The constituent submits their message via the form (initial intent)
  2. They receive a confirmation email explaining that their name, email, postcode, and message will be shared with their MP
  3. They click the confirmation link and then click "Confirm & Send" on the confirmation page
  4. This constitutes explicit consent to share their personal data with the named MP
- The confirmation page clearly states: "By confirming, you consent to your name, email address, postcode, and message being sent to [MP Name], [Constituency]. [MP Name] may reply to you directly."
- The pending submission record stores a `consent_given_at` timestamp when the button is clicked — this is the auditable consent record
- **No consent = no data shared.** If the constituent never confirms, the pending submission expires after 24 hours and is automatically purged. Their data is never sent to the MP.

### Data Flow and Storage

| Data | Where Stored | Retention | Notes |
|------|-------------|-----------|-------|
| Constituent name, email, postcode | WordPress site (`wp_options` or form plugin entries) | Site owner's responsibility | PII stays on the site owner's infrastructure |
| Message content | WordPress site (submission log) | Auto-delete after configurable period (default 90 days) | Site owner can adjust |
| Delivery metadata (postcode, member ID, status) | Supabase `delivery_log` | 12 months, then auto-purged | **No PII** — postcode is the most specific data point |
| License data (customer email, name) | Supabase `licenses` | Duration of licence + 6 months | Standard commercial relationship |

### Key Principles

- **PII does not leave the WordPress site.** The middleware receives only the postcode (to resolve the MP) and delivery metadata. The constituent's name, email, and message content are never sent to Supabase.
- **The email is sent from the WordPress site** via the site owner's SMTP — the message content flows directly from the site to the MP's inbox without passing through the middleware.
- **The site owner is the data controller.** Bluetorch (via the middleware) is a data processor only for the non-PII delivery metadata. A simple Data Processing Agreement (DPA) should be available.
- **Right to erasure:** The plugin includes a WP CLI command and admin action to purge all submission logs for a given email address.
- **Privacy notice template:** The plugin provides a suggested privacy notice paragraph that site owners can add to their privacy policy.

### Uninstall/Deactivation Cleanup

`uninstall.php` handles:
- Delete all `sendtomp_*` options from `wp_options`
- Delete all `sendtomp_*` transients
- Drop any custom database tables (if used for local logging)
- Deactivate the license (call `/license/deactivate`)
- Remove any WP-Cron scheduled events

---

## 9. Admin UI Specification

### Settings Page (Settings → Send to MP)

Tabbed interface:

**Tab: General**
- API key status (auto-generated on license activation, displayed read-only)
- Middleware connection status indicator (green/amber/red)
- Detected form plugins (with status icons)
- Default target house (Commons/Lords) for new forms

**Tab: Email**
- From Address
- From Name
- Reply-To behaviour (constituent email / fixed address)
- BCC Address(es)
- Subject Line Template (with placeholder reference)
- Email Body Template (with placeholder reference and preview)
- Test Email button + result display
- SMTP plugin detection and warnings

**Tab: Confirmation**
- Confirmation email subject template (default: "Please confirm your message to {mp_name}")
- Confirmation email body template (with placeholders)
- Confirmation page heading (default: "Confirm your message")
- Confirmation page consent text (default: "By confirming, you consent to your name, email address, postcode, and message being sent to {mp_name}, {mp_constituency}.")
- Token expiry period (default: 24 hours)
- Thank-you page message after successful confirmation
- Expired/invalid token message

**Tab: Rate Limits**
- Per-email limit (default 3/24hr)
- Per-IP limit (default 10/24hr)
- Per-postcode limit (default 20/24hr)
- Global daily limit (default 100)

**Tab: Address Overrides (Local)**
- Search for MP/Peer by name
- Add/edit/delete local overrides
- Table view of existing overrides with member name, override email, created date

**Tab: License**
- License key input
- Activate/Deactivate button
- Status display (active, expires on X, activations used)
- Current version + update status

**Tab: Log**
- Filterable table: date, constituent (first name + postcode only), target member, status, adapter source
- Export to CSV
- Purge log button (with confirmation)
- Auto-purge setting (default 90 days)

### Error States and Admin Notices

- **No license:** Dismissible notice on all admin pages: "SendToMP requires a license key. [Enter key]"
- **License expired:** Warning notice: "Your SendToMP license has expired. The plugin will continue to work but you won't receive updates. [Renew]"
- **No SMTP plugin detected:** Warning on SendToMP settings page: "No SMTP plugin detected. Email delivery may be unreliable. [Learn more]"
- **Middleware unreachable (>1hr):** Warning notice: "SendToMP is temporarily unable to connect to the member lookup service. Form submissions will be queued."

---

## 10. Constituent Verification

### Postcode-to-MP Match

For Commons submissions, the postcode entered by the constituent is used to resolve their MP via the Parliament API. This inherently verifies that the message goes to the correct MP for that postcode — but it does NOT verify the constituent actually lives there.

### Email Verification via Double Opt-In (core feature, not optional)

The confirmation flow (Section 2, Data Flow) is the primary verification mechanism. It proves:

1. **The email address is real** — they received and opened the confirmation email
2. **The person intended to send the message** — they actively clicked "Confirm & Send"
3. **They consented to sharing their data** — the confirmation page explicitly states what will be shared with whom
4. **They are not a bot** — bots/scanners don't click POST buttons on confirmation pages

This is equivalent to what WriteToThem does and is the industry standard for this type of tool.

### Additional Verification Measures

- **Postcode prominently displayed** in the email to the MP so they can see at a glance whether this is a constituent
- **Reply-To set to the constituent's verified email** — the MP can reply directly
- **Optional full address field** — the site owner can choose to require a postal address (not just postcode). This doesn't prove residency but adds friction for bad actors and gives the MP more confidence in the sender
- **Rate limiting** prevents mass submissions from one person/IP

### What the Confirmation Email Says

The confirmation email to the constituent includes:
- Their message previewed in full (so they can review before confirming)
- The name and constituency of the MP who will receive it
- A clear "Confirm & Send" link
- A note: "If you did not submit this message, simply ignore this email. It will not be sent."
- Expiry notice: "This link expires in 24 hours."
- Footer: "Powered by Bluetorch's SendToMP" (Free and Plus tiers; removable on Pro)

### Transparency to MPs

The email to the MP includes a brief, honest footer:
> "This message was sent by {constituent_name} ({constituent_postcode}) via [Campaign Name]. The sender verified their email address before this message was sent. Reply directly to {constituent_email}."
>
> "Powered by Bluetorch's SendToMP — verified constituent correspondence. Built by a former parliamentary assistant."

The second line is the product branding, which varies by tier:
- **Free tier:** Always shown (not removable). This is the primary brand awareness driver — every MP office in the country sees it.
- **Plus tier:** Shown by default but configurable (site owner can toggle it off in settings).
- **Pro tier:** Off by default (white-label). Site owner can enable it if they choose.

This sets expectations appropriately. MPs and their staff are familiar with campaign tools (38 Degrees, WriteToThem, etc.) and know that some messages come via platforms. The "verified their email" line adds credibility. The "Built by a former parliamentary assistant" line signals insider credibility to MP office staff.

### Pending Submission Storage

**Custom table: `{prefix}sendtomp_pending`**
- `id` (bigint, auto-increment, PK)
- `token` (varchar(64), unique, indexed) — secure random token for the confirmation URL
- `submission_data` (longtext) — serialised `SendToMP_Submission` object (encrypted at rest via `wp_salt`)
- `resolved_member` (text) — JSON with member name, ID, email, house (cached from middleware response)
- `constituent_email` (varchar(255), indexed) — for rate limiting lookups
- `status` (varchar(20)) — "pending", "confirmed", "expired", "cancelled"
- `consent_given_at` (datetime, nullable) — timestamp of confirmation click (the GDPR audit record)
- `created_at` (datetime)
- `expires_at` (datetime) — created_at + 24 hours

**Cleanup:** A WP-Cron job runs hourly to purge expired pending submissions (status = "pending" AND expires_at < now). These are hard-deleted, not soft-deleted — if the constituent didn't confirm, we don't keep their data.

---

## 11. Commercial: Branding, Templates, and Directory

### Branding Touchpoints

Every user-facing surface carries Bluetorch branding (on Free/Plus tiers):

| Surface | Branding | Free | Plus | Pro |
|---------|----------|------|------|-----|
| Email to MP (footer) | "Powered by Bluetorch's SendToMP" | Always on | Default on, removable | Off by default |
| Confirmation email (footer) | "Powered by Bluetorch's SendToMP" | Always on | Default on, removable | Off by default |
| Confirmation page (footer) | "Powered by Bluetorch's SendToMP" + link | Always on | Default on, removable | Off by default |
| Thank-you page (footer) | "Powered by Bluetorch's SendToMP" + social sharing | Always on | Default on, removable | Off by default |
| WordPress admin settings | Bluetorch logo, help links, upgrade prompts | Always | Always | Always (no upgrade prompts) |

### Campaign Template Library (Stub — future pass)

A library of starter templates hosted on the Bluetorch website, accessible from within the plugin admin.

**What it will include (when fleshed out):**
- Pre-written email templates for common campaign types (petition, Bill lobbying, constituency issue, Lords committee)
- Recommended form field configurations per campaign type
- Confirmation page copy optimised for different audiences
- Subject line templates proven to get opened

**Current implementation (stub):**
- A "Templates" link in the admin sidebar pointing to a Bluetorch website page
- The Bluetorch page initially shows a "Coming Soon — we're building a template library" placeholder with an email signup to be notified
- The plugin settings include a `campaign_type` dropdown (Petition, Bill Lobbying, Constituency Issue, General) that pre-fills default subject/body templates — this is the v1 equivalent

### Campaign Directory (Stub — future pass)

An opt-in directory of active campaigns on the Bluetorch website.

**What it will include (when fleshed out):**
- Site owners who opt in get their campaign listed with title, description, and link
- Directory page on Bluetorch website ranks for campaign-related search terms
- Cross-promotion potential between campaigns targeting the same MP
- Featured listings for Pro tier customers

**Current implementation (stub):**
- In the plugin admin settings (General tab), a checkbox: **"List this campaign in the Bluetorch Campaign Directory"** (default: unchecked)
- Accompanying text: "Opt in to have your campaign listed on the Bluetorch SendToMP directory. This can help your campaign gain visibility and attract more supporters."
- When enabled, the plugin sends basic campaign metadata to the middleware on activation: site name, campaign title (configurable), site URL, target house. **No constituent data.**
- A `campaign_directory` table in Supabase stores opt-in listings
- The Bluetorch website directory page is initially a simple list rendered from this table — minimal design, functional
- Plus and Pro tiers only (Free tier cannot opt in — incentivises upgrade)

### Admin Sidebar: Resources and Upsell

The plugin admin pages include a right-hand sidebar with:
- **"What's New"** panel — latest changelog from the update check response
- **"Need Help?"** — link to documentation on Bluetorch website
- **"Recommended: Set up Brevo"** — affiliate link with brief setup guide
- **Campaign tips** — rotating tips from Mike's parliamentary experience (e.g. "Tip: Messages mentioning a specific Bill by name are more likely to get a response")
- **Upgrade prompt** (Free/Plus only) — contextual, shows the next tier's key benefits

---

## Implementation Phases

### Phase 1 — Core + Middleware
1. Set up Supabase tables and migrations (middleware + licensing in same project)
2. Build `/resolve-member` edge function with Parliament API integration + caching
3. Build plugin core: bootstrap, main class, submission object, API client
4. Build mailer class with configurable From/Reply-To/BCC and SMTP detection
5. Build confirmation system: pending submissions table, token generation, confirmation page, WP-Cron cleanup
6. Build rate limiter
7. Build admin settings page (General, Email, Confirmation, Rate Limits tabs)
8. Add API key authentication on middleware

### Phase 2 — First Adapter (Gravity Forms) + End-to-End
9. Build GF feed addon adapter with field mapping
10. Implement end-to-end: form submit → validate → rate check → middleware → resolve MP → store pending → send confirmation email → constituent confirms → send to MP → log
11. Test with real postcodes and Parliament API
12. Test email delivery via Brevo/SendGrid SMTP
13. Test confirmation flow: token generation, expiry, scanner resistance, consent recording

### Phase 3 — Additional Adapters (Plus/Pro)
14. WPForms adapter (Plus+)
15. Contact Form 7 adapter (Plus+)
16. Webhook/API Platform endpoint (Pro only, stubbed — basic endpoint + auth, advanced platform features for future pass)

### Phase 4 — House of Lords
17. Add `/search-members` endpoint to middleware
18. Add member selector UI component for Lords targeting
19. Add `contact_quality` indicator for Lords contact data
20. Update adapters to support house selection and member targeting

### Phase 5 — Address Overrides
21. Build two-tier override system (Supabase global + WordPress local)
22. Add Address Overrides tab to admin settings
23. Test override precedence (local > global > Parliament API)

### Phase 6 — Licensing + Distribution
24. Add licensing edge functions to Supabase (with `tier` field: plus/pro)
25. Build `sendtomp_can($feature)` tier-gating helper in plugin
26. Build custom updater class + license management in plugin
27. Add Stripe checkout + webhook on Bluetorch Next.js site (Plus and Pro products)
28. Build customer portal page (view key, activations, tier, expiry, download)
29. Implement Free tier defaults (no key required, 25 messages/month, GF only, branding mandatory)
30. Package and test full: Free usage → upgrade to Plus → activate key → features unlock

### Phase 7 — Commercial + Branding
31. Implement branding footer on MP emails, confirmation emails, confirmation page, thank-you page (tier-gated visibility)
32. Implement Brevo partner recommendation in SMTP guidance (affiliate link)
33. Build admin sidebar (What's New, Help, Brevo link, campaign tips, upgrade prompts)
34. Stub campaign template library (admin link + "Coming Soon" page on Bluetorch site + campaign_type dropdown with default templates)
35. Stub campaign directory opt-in (settings checkbox + Supabase table + basic Bluetorch directory page)
36. Contextual upgrade prompts throughout admin UI for gated features

### Phase 8 — Polish
37. Logging tab with filters, export (CSV export Pro only), auto-purge
38. Admin notices and error handling
39. SMTP detection and health check
40. Frontend AJAX postcode lookup (show MP name before submit)
41. Graceful Supabase downtime handling (queue + retry)
42. Privacy notice template and erasure tools
43. `uninstall.php` cleanup (options, transients, tables, license deactivation, pending submissions purge)
44. Election mode cache flush mechanism

---

## Verification

- **Confirmation flow:** Submit form → receive confirmation email → click link → see confirmation page with message preview and MP name → click "Confirm & Send" → verify email arrives at MP's address. Test the full happy path.
- **Scanner resistance:** Send confirmation email to a Microsoft 365 mailbox with SafeLinks enabled. Verify the link scanner does NOT trigger the confirmation (because it requires a POST button click). Verify the message is NOT sent to the MP without human action.
- **Token expiry:** Submit form, wait >24 hours, click confirmation link — verify it shows an "expired" message and does not send.
- **Token single-use:** Confirm a submission, then click the same link again — verify it shows "already confirmed" and does not re-send.
- **Pending cleanup:** Submit form, do NOT confirm — verify the pending record is purged after 24 hours by the WP-Cron job.
- **Consent audit:** After confirmation, check the `sendtomp_pending` record has `consent_given_at` populated with the correct timestamp.
- **Email delivery:** Send test messages to a test inbox via configured SMTP. Verify SPF/DKIM pass. Verify Reply-To works. Verify BCC works. Check spam score.
- **Rate limiting:** Submit >3 messages from same email within 24hr, confirm rejection at form stage (before confirmation email is sent). Same for IP and postcode limits.
- **Licensing:** Install on a test site, activate with valid/invalid/expired keys, confirm updates appear only with valid license. Test Supabase downtime (fail-open on cached license).
- **Form adapters:** Submit test forms from each supported plugin, confirm full confirmation flow works end-to-end.
- **Middleware:** Call `/resolve-member` with known postcodes, confirm correct MP returned. Add a global override, confirm it's applied. Add a local override, confirm it takes precedence.
- **Lords:** Search for peers, select one, confirm `contact_quality` indicator is accurate. Test shared inbox vs. direct email.
- **Webhook:** POST to the REST endpoint with correct/incorrect API keys, confirm authentication and confirmation flow is triggered. Confirm endpoint returns 403 on Free/Plus tiers.
- **Tier gating:** On Free tier, confirm: Lords disabled, BCC disabled, WPForms/CF7 adapters hidden, branding not removable, 26th message in a month is rejected. Upgrade to Plus, confirm features unlock. Upgrade to Pro, confirm webhook API and white-label available.
- **Branding:** On Free tier, confirm "Powered by Bluetorch's SendToMP" appears on MP email, confirmation email, confirmation page, and thank-you page. On Plus, confirm it can be toggled off. On Pro, confirm it's off by default.
- **Brevo affiliate:** Confirm SMTP guidance page shows Brevo recommendation with correct affiliate link.
- **GDPR:** Confirm no PII reaches Supabase. Test erasure tool. Confirm auto-purge of confirmed logs after 90 days. Confirm unconfirmed pending submissions are purged after 24 hours.
- **Downtime:** Disconnect Supabase, submit a form, confirm graceful error message. Reconnect, confirm retry works.
- **Abuse:** Submit via honeypot-filled request, confirm silent rejection. Submit identical message twice, confirm duplicate detection. Submit >rate limit, confirm rejection.
