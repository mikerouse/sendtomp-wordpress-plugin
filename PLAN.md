# SendToMP v1.6.0 — Email Delivery UX, bring-your-own SMTP, OAuth providers, branded admin header

**Status:** planning — awaiting Mike's calls on the flagged decisions.
**Author:** Claude, 2026-04-22 (post-v1.5.1)

---

## The problem in one paragraph

The current Email Delivery tab tells site owners "install an SMTP plugin" and leaves them to it. That's two hurdles: they have to evaluate/install a *second* plugin and then configure it with credentials our plugin can't help with. Meanwhile, WP Mail SMTP has set the UX bar visibly higher with provider tiles, click-to-OAuth for Google Workspace and Office 365, and direct-in-plugin SMTP entry. We should close most of that gap in v1.6 so the path from "just installed SendToMP" to "emails are being delivered" can happen without leaving our settings screen. Plus, the plugin's admin pages currently show a plain `<h1>SendToMP v1.5.1</h1>` — with a 2000×1000 logo now in hand, we should brand the header while we're in the area.

---

## Scope — four workstreams

### A. Branded admin header (cheap, ship it regardless)

Small, visual, zero risk.

- Partial `admin/views/partials/header.php` renders the logo + tagline + version chip, included at the top of every SendToMP admin view (replaces the current `<h1>` line in `admin/views/settings-page.php:22`, `logs-page.php`, `delivery-page.php`, `overrides-page.php`, `status-page.php`).
- Uses `assets/icon-2000x1000.png` at `max-height: 64px` via the existing `assets/css/sendtomp-admin.css`.
- The page-specific title (tab name, "Submission Log", etc.) moves to an `<h2>` under the header so it still reads as the page heading for screen readers.
- No other behaviour changes.

### B. Provider tile picker on the Email Delivery tab

Replicate the WP Mail SMTP pattern (their second screenshot) in our own voice.

**Providers we surface (in tile order):**

| Tile | Path | Tier | Notes |
|---|---|---|---|
| **Detected SMTP plugin** (if active) | passthrough | Free | "Already handled by WP Mail SMTP / FluentSMTP / Post SMTP — nothing to do here." Detected → shown first, selected by default, no setup needed. |
| **Brevo (recommended)** | direct HTTP API | Free | API-key entry. Uses Brevo's transactional-email HTTP endpoint directly — no SMTP layer. Tested send button. |
| **Google Workspace** | OAuth | **Pro** | "Connect with Google" button. Sends via Gmail API. |
| **Office 365 / Outlook** | OAuth | **Pro** | "Connect with Microsoft" button. Sends via Microsoft Graph `sendMail`. |
| **Custom SMTP** | raw credentials | Free | Host / Port / Encryption / Auth / Username / Password. |
| **Default (`wp_mail`)** | passthrough | Free | Fallback. Shown greyed-out with a warning. |

**UX details:**

- Single selection (radio-style). Switching providers shows that provider's config panel below the grid.
- "Test email" button per configured provider — sends to the site admin email, shows success/error inline.
- Each tile: logo (from `assets/images/providers/<slug>.svg` or `.png`) + name + tier badge ("Pro" for the OAuth tiles).
- "Already using WP Mail SMTP / FluentSMTP? We'll defer to it — nothing to configure here" — this is a first-class explanatory state, not a fallback.

### C. Bring-your-own SMTP (Custom SMTP tile)

The baseline self-service path — matches expectations for anyone who's used any transactional email service.

- Fields: Host, Port (default 587), Encryption (None / TLS / SSL), Auth (bool, default on), Username, Password.
- Password stored encrypted in `wp_options`, wrapped with a site-salt key (re-use WP salts or a plugin-specific key). **Never** stored plaintext, never surfaced in `get_option()` output.
- Integration: hook `phpmailer_init` to reconfigure PHPMailer only when our "Custom SMTP" provider is selected **and** no detected SMTP plugin is active (WP Mail SMTP's hook runs at priority 10; we run at priority 5 but bail if they already configured it).
- Conflict handling: if a detected SMTP plugin is live, we *don't* override. We show that on the tile ("Custom SMTP is disabled — WP Mail SMTP is handling email delivery").

### D. OAuth for Google Workspace + Office 365 (Pro)

The expensive workstream. Worth scoping tightly.

**Hosting model (recommend option 1):**

1. **Centralised OAuth app on bluetorch.co.uk** — we register a Google Cloud project + Microsoft Entra app, publish/verify them once, and every SendToMP site uses *our* client ID. The redirect URI is `https://www.bluetorch.co.uk/sendtomp/oauth/<provider>/callback`, which bounces the auth code back to the specific site over HTTPS via a signed state parameter. This is how WP Mail SMTP Pro works, and it's the only path that gives click-to-connect UX.
2. **BYO OAuth app** — the site owner registers their own Google Cloud project and pastes client ID + secret. No infra work for us. Much worse UX — most site owners have never touched Google Cloud Console.

Picking option 1 means a one-time build on bluetorch.co.uk:

- Two Next.js routes: `/sendtomp/oauth/google/authorize`, `/sendtomp/oauth/google/callback` (same pair for Microsoft)
- Google Cloud project with OAuth 2.0 client credentials + Gmail API scope (`https://www.googleapis.com/auth/gmail.send`) + OAuth consent screen verification (this takes ~1-2 weeks for Google)
- Microsoft Entra app registration with `Mail.Send` delegated permission + admin-consent workflow
- Signed-state handshake: SendToMP generates a nonce, redirects to `bluetorch.co.uk/.../authorize?state=<signed-nonce>&return=<site-url>`, we forward to Google, on callback we verify the signed state and redirect back to the site with `?code=<auth_code>&state=<verified-nonce>`; plugin exchanges the code for tokens using our client secret via a server-side call to `bluetorch.co.uk/.../exchange`. Client secret never touches the site.

**Token storage on the WP site:**

- Access token + refresh token, encrypted identically to the Custom SMTP password.
- Token refresh: on send, if expired, POST to `bluetorch.co.uk/.../refresh` with the refresh token (wrapped in HMAC-signed body); response has a new access token. Plugin never handles the client secret.
- Revocation: "Disconnect" button on the tile calls the provider's revoke endpoint + clears local tokens.

**Send path:**

- Google: `POST https://gmail.googleapis.com/gmail/v1/users/me/messages/send` with a base64-encoded RFC 5322 message, `Authorization: Bearer <access_token>`
- Microsoft: `POST https://graph.microsoft.com/v1.0/me/sendMail` with a JSON `message` payload
- Both bypass PHPMailer entirely — we build the MIME message ourselves for Gmail, or a Graph JSON payload for Microsoft.

**Locked split:** v1.7.0 ships Google only; v1.8.0 ships Microsoft. In v1.6.0 the Google and Microsoft tiles render with "Coming in Pro" disabled state so the visual layout is final from day one.

---

## Decisions locked (2026-04-22)

1. **OAuth hosting model — centralised on bluetorch.co.uk.** Underpins the premium value of Bluetorch's managed service layer; only path that gives click-to-connect UX.
2. **Google + Microsoft OAuth — Pro tier only.** Infra cost and compliance burden justify the paywall.
3. **Brevo — direct HTTP API.** Better deliverability and metrics. The Email Delivery tab must include a short "Why Brevo?" explainer so site owners understand why they're being asked to sign up for a third-party service (reputation-aware IPs, parliamentary-gateway deliverability, bounce handling — the reasons we already know).
4. **Scope split agreed:**
   - **v1.6.0** — workstreams A + B + C: branded header, provider tile picker, Custom SMTP, Brevo HTTP integration.
   - **v1.7.0** — workstream D part 1: Google Workspace OAuth (centralised on bluetorch.co.uk).
   - **v1.8.0** — workstream D part 2: Office 365 / Outlook OAuth.
5. **Artwork** — Mike will deliver to `assets/images/providers/`. Folder created. Not blocking for build: tiles can ship with placeholder text rendering until art lands. Google's restricted-scope verification wait (1–2 weeks) noted; fine while we're pre-Pro-customer.

---

## Files we'll touch (v1.6.0 as proposed)

| Path | Action | Notes |
|---|---|---|
| `assets/icon-2000x1000.png` | exists | Used in header partial at reduced size |
| `assets/images/providers/` | new dir | Artwork drop zone (created) |
| `admin/views/partials/header.php` | new | Logo + tagline + version chip |
| `admin/views/settings-page.php` | modify | Include header partial; move `<h1>` to `<h2>` for tab title |
| `admin/views/logs-page.php` | modify | Include header partial |
| `admin/views/delivery-page.php` | **rewrite** | New tile-based picker + per-provider config panels |
| `admin/views/overrides-page.php` | modify | Include header partial |
| `admin/views/status-page.php` | modify | Include header partial |
| `admin/class-sendtomp-settings.php` | modify | New settings: `smtp_provider`, `smtp_custom_host/port/...`, `smtp_brevo_api_key` (encrypted), Google/MS OAuth token fields stubbed |
| `includes/class-sendtomp-mailer.php` | modify | Provider dispatch: Brevo HTTP path, Custom SMTP via phpmailer_init, fallthrough to wp_mail |
| `includes/class-sendtomp-secret.php` | **new** | Shared encrypt/decrypt helper (AES-256-GCM using a site key derived from WP salts). Used for SMTP password + API keys + later OAuth tokens. |
| `includes/class-sendtomp-provider-brevo.php` | **new** | Brevo HTTP transactional-email client |
| `includes/class-sendtomp-provider-smtp-detect.php` | **new** | Detects active SMTP plugins |
| `assets/css/sendtomp-admin.css` | modify | Header styling; provider tile grid |
| `readme.txt` | modify | Stable tag, changelog |
| `sendtomp.php` | modify | Version 1.6.0 |

---

## Risk + gotchas (things to not re-discover)

1. **SMTP password storage** — the single most sensitive piece of state we'll hold. AES-256-GCM with a site-local key derived from `wp_salt('auth')` is adequate but not perfect (shared hosting + DB dumps still expose ciphertext + key). Consider a `SENDTOMP_SECRET_KEY` wp-config constant as an override so the key can live outside the DB. Document this clearly.
2. **OAuth redirect URI registration** — Google requires the *exact* redirect URI registered in Cloud Console. This means every SendToMP site *cannot* register its own; we must centralise through bluetorch.co.uk. Non-negotiable constraint, drives the hosting-model decision.
3. **Google OAuth consent-screen verification** — sending email via Gmail API uses a "restricted scope" (`gmail.send`) and triggers manual Google review. 1–2 week turnaround. Factor into the schedule.
4. **Microsoft admin consent** — Office 365 admin tenants often require admin consent for `Mail.Send`. Our "Connect with Microsoft" button needs to handle the admin-consent redirect variant, not just user consent.
5. **Coexistence with WP Mail SMTP** — if both are active and configured, whichever `phpmailer_init` priority runs last wins. We must bail when WP Mail SMTP is active, not race it.
6. **Detected SMTP plugin as default** — this is the *correct* UX (don't disturb working setups), but it means our tile picker must have a "detected" state distinct from "user chose this". Subtle design detail; get it right or it's confusing.
7. **`phpmailer_init` vs Gmail/Microsoft APIs** — those two bypass PHPMailer. Our mailer needs a clean provider-dispatch seam rather than always going through `wp_mail()`.

---

## What happens now

Decisions locked. v1.6.0 bundles A + B + C. OAuth work (Cloud project, consent-screen submission, bluetorch.co.uk routes) starts **after** v1.6.0 ships — not in parallel. The 1–2 week Google review clock therefore starts post-v1.6 release; v1.7.0 is gated on Google's verification turnaround.

Build order within v1.6.0:

1. **Workstream A — branded admin header.** Smallest, most visible. Good warm-up and gets the logo into every other page we're about to touch, so the Email Delivery rebuild lands with the new header already in place.
2. **Workstream C — provider engines** (behind the scenes): `SendToMP_Secret` helper, Brevo HTTP client, Custom SMTP `phpmailer_init` hook, detection of active SMTP plugins. No UI yet — just the plumbing.
3. **Workstream B — tile picker UI.** Rewrites the Email Delivery tab. Renders all six tiles from day one; Google/Microsoft tiles show disabled "Coming in Pro (v1.7/v1.8)" state so layout is final now.
4. **Wiring + test emails + ship v1.6.0.**

Artwork can land any time before step 4; tiles render text-only until it arrives.
