# SendToMP — Roadmap after 2026-04-22 session

**Status:** next session starts here.
**Previous session wrapped at:** v1.6.7, 2026-04-22.

---

## Shipped this session (brief)

Ten releases, three feature arcs (GF field type, Email Delivery rebuild, Submission Log overhaul), plus one cascade of small polish fixes:

- **v1.5.0** — Custom "Find My MP" Gravity Forms field type (drag-and-drop from Advanced Fields; auto-detected as postcode source at submission time)
- **v1.5.1** — Custom helper text on the field in the editor sidebar
- **v1.6.0** — Email Delivery provider picker: Brevo (direct HTTP API), Custom SMTP (encrypted credentials), WP Mail fallback, branded admin header, mailer dispatcher refactor
- **v1.6.1** — Status indicators + reworded notices (retired "install an SMTP plugin" nag when delivery is configured)
- **v1.6.2** — MP Lookup field overrides stale feed mapping
- **v1.6.3** — Rich HTML confirmation email + placeholder resolution fixes + Bluetorch logo footer
- **v1.6.4** — Defaults + Media Library picker + live preview iframe on Confirmation tab
- **v1.6.5** — Duplicate confirm CTA + stale postcode merge tag recovery
- **v1.6.6** — Submission log overhaul: detail view, resend, delete, export CSV, status pills
- **v1.6.7** — Auto-resend confirmation email on duplicate submissions

Every release deployed to untruecrime.uk testbed automatically via `deploy-testbed.yml`; every tag produced a ZIP via `release.yml`.

---

## Carried over (open follow-ups from this session's work)

Small items we consciously deferred while landing the bigger arcs:

1. **Live JS preview on the Confirmation tab** — the preview iframe currently refreshes only on save. A debounced AJAX endpoint that re-renders `render_confirmation_html()` on field change would make the tab feel genuinely interactive. Scoped as a self-contained v1.6.8 — maybe half a day.
2. **Confirmation email themes** — `render_confirmation_html()` is structured so adding alternative templates is a contained change (one template-selection setting + a switch on a theme slug). Ship with 2-3 themes: "Campaign" (current blue), "Formal" (neutral grey), "Warm" (cream + serif).
3. **Bulk actions on the submission log** — select rows → delete / export selected. The existing export is all-rows only.
4. **Resend for Error / Failed rows** — currently resend only fires on `pending_confirmation`. For entries where the confirmation succeeded but the MP send failed, a "Retry MP send" action would help recover without asking the constituent to resubmit. Needs the log to store enough state to replay `send_to_mp()` — may require a schema extension.
5. **Surface auto-resend message on the GF frontend** — v1.6.7 re-sends the email silently; the constituent sees GF's default thanks page. A frontend hook that replaces the thanks-page text with "we've just re-sent your confirmation email" would close the UX loop.
6. **Rename `SendToMP_Form_Adapter_Interface::get_slug()` to `get_adapter_slug()`** — the name shadows `GFFeedAddOn::get_slug()`, which is why feeds are stored with `addon_slug='gravity-forms'` instead of `'sendtomp'`. Deferred from v2 roadmap; worth doing when the adapter interface is next touched for other reasons (WPForms / CF7 work). Needs a one-time migration of `wp_gf_addon_feed.addon_slug` values on upgrade.
7. **OAuth tiles are placeholders** — Google Workspace and Office 365 tiles on the Email Delivery tab render disabled with "Coming in v1.7 / v1.8". The full Pro-tier delivery is the next major arc (below).

---

## Next major arcs — pick one or split across sessions

### Arc A — Email Delivery OAuth (v1.7.0 + v1.8.0)

Still locked from the prior session's plan. No new decisions needed; just groundwork + build.

- **v1.7.0**: Google Workspace OAuth. Centralised on bluetorch.co.uk, client secret never touches the WP site, `gmail.send` scope. Needs a Google Cloud project + OAuth consent screen submission — **1-2 week Google review clock starts the moment we submit**, so kicking off the consent screen early is the first step even if we don't intend to ship v1.7 immediately.
- **v1.8.0**: Office 365 / Outlook OAuth via Microsoft Graph `sendMail`.

Both are Pro-tier features. Full design already in [git history for PLAN.md during v1.6 work] and the committed changelog for v1.6.0. The bluetorch.co.uk routes needed (`/sendtomp/oauth/<provider>/authorize`, `/callback`, `/exchange`, `/refresh`) are greenfield on the website repo.

### Arc B — Licensing + Bluetorch authorisation + Stripe (NEW)

This is Mike's explicit next theme. The plumbing already exists in fragments across both repos but has never been tied off.

**Current state — plugin side:**

- `SendToMP_License` class with `FEATURE_TIERS`, `TIER_FREE/PLUS/PRO`, and `can( $feature )` check used throughout (`sendtomp()->can('bcc')`, `can('lords')`, `can('csv_export')`, etc.)
- `handle_activate_license` / `handle_deactivate_license` AJAX handlers on the License settings tab
- Status cached in a 24-hour transient; fail-open on API downtime (safer than locking everyone out when Supabase hiccups)
- `FREE_MONTHLY_LIMIT = 25` enforced by `check_monthly_limit()` in the pipeline

**Current state — bluetorch.co.uk side:**

- Licensing code lives on an **unmerged branch**: `feature/sendtomp-licensing`
- Branch includes: Prisma models (`License`, `LicenseActivation`, `PluginRelease`), `/api/license/{activate,deactivate,check,check-update,portal}`, `/api/admin/licenses`, `/api/stripe/{checkout,webhook}`
- "Production-ready but never merged" per the reference memory. Left alone during the resolver middleware build because we wanted the resolver PR independent.

**What needs to happen:**

1. **Merge `feature/sendtomp-licensing` into master** on the website repo. Review the branch against master (merge conflicts likely after the resolver work landed); resolve; merge; deploy to Vercel.
2. **End-to-end test the activate → check → deactivate loop** against the deployed endpoints. The plugin already hits `/api/license/activate` via `handle_activate_license` — confirm the wire protocol still matches (payload shape, response shape, error codes).
3. **Finish Stripe checkout + webhook flow:**
   - Checkout: `/api/stripe/checkout` creates a Stripe Checkout session; customer goes through Stripe-hosted payment; on success Stripe fires webhook
   - Webhook: `/api/stripe/webhook` creates a License row keyed to the Stripe customer, emails the licence key to the buyer, and provisions a portal URL
   - Products needed: Plus (monthly + annual), Pro (monthly + annual). Annual = discount. Confirm the Stripe product IDs + prices on the website dashboard.
   - Webhook signing secret in Vercel env: `STRIPE_WEBHOOK_SECRET`
   - Test mode first; live mode once confirmed
4. **Customer portal wiring:**
   - `/api/license/portal` generates a Stripe Billing Portal session URL so customers can manage payment methods, download invoices, cancel
   - Link surfaced from the plugin's License tab ("Manage billing on Bluetorch →") and from the licence-expired admin notice
5. **Plugin-side UX polish:**
   - License tab currently a basic key-entry input. Needs: clear tier/status display with renewal date, feature-tier comparison table ("what does Plus unlock?"), one-click "buy now" CTA that bounces the user to Stripe Checkout on bluetorch.co.uk
   - License status refresh button (manual force-check) + last-checked-at timestamp
   - On expired: clear banner + renewal link; no silent degradation
6. **Plugin updater (updates from Bluetorch, not wp.org, for Plus/Pro):**
   - We removed all updater code in v1.3.1 (wp.org Guideline 8 — can't serve updates from elsewhere if you're listed there)
   - For Plus/Pro, wp.org rules don't apply (they're licenced add-ons). Check the current thinking: do we ship a separate "SendToMP Pro" plugin file that handles its own updates, or only update Pro-tier *features* (unlocked by the licence key, code stays in the free wp.org plugin)?
   - The "code stays free, licence unlocks" model is simpler and is what `FEATURE_TIERS` already assumes. Confirm this is the locked design before building any updater. If so, no updater work is needed — licence key alone gates features.

**Key decisions needed from Mike before picking this arc up:**

1. **"Code stays, licence unlocks features" vs "separate Pro plugin with own updates"** — I assume the first (matches current code + wp.org constraints). Confirm.
2. **Stripe products / prices** — what's the final price matrix? Plus monthly, Plus annual, Pro monthly, Pro annual. VAT handled on Stripe's side or ours?
3. **Trial period** — offer a 14-day free trial on Plus / Pro? Stripe supports this directly in the checkout flow.
4. **Activation limit per licence** — how many WP sites can activate one licence key? Plus = 1? Pro = 5? Unlimited? The `LicenseActivation` model exists but the quota rule isn't locked.
5. **Renewal email cadence** — Brevo transactional emails for 30 days before expiry, 7 days, day-of, day-after? We can drive this from Stripe's subscription events.

**Files / places involved:**

- `E:\Dev\bluetorch.co.uk\website` — the whole licensing branch
  - `/prisma/schema.prisma` — License, LicenseActivation, PluginRelease models
  - `/src/app/api/license/*` — activate / deactivate / check / check-update / portal routes
  - `/src/app/api/stripe/*` — checkout + webhook
  - `/src/app/api/admin/licenses` — admin management
  - `/src/app/sendtomp/pricing` — pricing page (if not already on master)
- `E:\Dev\SendToMP`
  - `includes/class-sendtomp-license.php` — client of the licensing API
  - `admin/class-sendtomp-settings.php` — `handle_activate_license`, `handle_deactivate_license`
  - `admin/views/settings-page.php` — License tab UI (currently a plain input)

---

## Starter sequence for next session

If Mike wants to pick this up and the above decisions are locked, sensible order:

1. **Merge `feature/sendtomp-licensing` to master** on the website repo. Resolve conflicts with resolver work. Deploy to Vercel. Smoke-test the endpoints manually.
2. **Wire the plugin against the live endpoints.** Test activate → check → deactivate. Surface any protocol mismatches and fix on one side.
3. **Stripe checkout + webhook.** Start in test mode. Buy a test Plus licence end-to-end: checkout → webhook fires → licence row created → email sent → paste key into plugin → tier flips to Plus → `can('bcc')` returns true.
4. **License tab polish** — tier / status / renewal date, feature table, buy-now CTA.
5. **Customer portal link.** One round-trip test.
6. **Go live on Stripe.** Switch to live keys. First real purchase.

Or, for parallelism: fork off an hour to submit the Google OAuth consent screen (Arc A groundwork) at the start of whichever session tackles Arc B, so the Google review clock is ticking while Stripe work proceeds.

---

## Memory updates to make when the next session starts

- Graduate v1.5.0 custom field type out of the "v2 roadmap" memory (done earlier but worth double-checking).
- Add a memory entry for the v1.6.x Email Delivery architecture (encrypted secrets helper, provider interface, Brevo HTTP, tile picker, mailer dispatcher). This is load-bearing knowledge the next Claude will need.
- Note the "fail-open on license-API-downtime" behaviour of `SendToMP_License` so future work doesn't accidentally invert it into a fail-closed path that would brick Plus/Pro sites during Supabase outages.
