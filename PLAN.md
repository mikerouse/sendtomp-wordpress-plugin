# SendToMP v1.5.0 — Custom "Find My MP" Gravity Forms field type

**Status:** planning complete, docs reviewed, ready to implement.
**Author:** Claude, 2026-04-21 (post-compression)
**Supersedes:** previous PLAN.md draft

---

## What we're building (one paragraph)

A native Gravity Forms field type that site owners can drag from the field picker directly onto a form. The field renders a postcode input pre-wired to SendToMP's Find-My-MP lookup — blue button + MP portrait preview — so the lookup works **from the moment the field is dropped**, regardless of whether a feed exists yet. This replaces the admin-notice / editor-warning approach we abandoned in v1.4.19 after two sessions of hook-hunting.

---

## Docs we actually read (cited so you can re-verify)

**Authoritative refs used to build this plan:**

1. `https://docs.gravityforms.com/gf_field/` — the GF_Field base class reference
2. `https://docs.gravityforms.com/gform_add_field_buttons/` — filter for tweaking the picker
3. `https://docs.gravityforms.com/gf_field_text/` — confirms GF_Field_Text extends GF_Field
4. `https://docs.gravityforms.com/category/developers/php-api/field-framework/field-classes/` — index of built-in field classes we can model on

**Local MHTML snapshots** (under `docs/knowledge/gravity-forms/`) turned out to document the **Settings API** custom-field pattern, not form-field types — useful for plugin settings pages but *not* for this work. Don't waste time re-reading them for this task.

### Key API facts confirmed by the live docs

- **Canonical registration pattern** (copied verbatim from `gf_field/`):
  ```php
  class GF_Field_Phone extends GF_Field {
      public $type = 'phone';
  }
  GF_Fields::register( new GF_Field_Phone() );
  ```

- **Button placement** — the GF_Field class has **two paths** for getting into the picker:

  1. **Self-registering** via `get_form_editor_button()` (returned by the class itself). Returns an array like `['group' => 'standard_fields', 'text' => 'MP Lookup']`. Available groups: `standard_fields`, `advanced_fields`, `post_fields`, `pricing_fields`.
  2. **Filter-based** via `gform_add_field_buttons`. Button entry keys: `class`, `data-type`, `value`, `onclick`. Example (verbatim from docs):
     ```php
     add_filter( 'gform_add_field_buttons', function ( $field_groups ) {
         foreach ( $field_groups as &$group ) {
             if ( $group['name'] == 'advanced_fields' ) {
                 $group['fields'][] = array(
                     'class'     => 'button',
                     'data-type' => 'map',
                     'value'     => __( 'Map', 'gravityforms' ),
                     'onclick'   => "StartAddField('map');"
                 );
                 break;
             }
         }
         return $field_groups;
     } );
     ```

  **We use path (1)** — `get_form_editor_button()`. It's cleaner because the field class owns its own placement; no extra filter code to maintain.

- **Overridable methods on GF_Field** (docs-confirmed):
  | Method | Purpose |
  |---|---|
  | `public $type` | Unique field type identifier string |
  | `get_form_editor_field_title()` | Display name in the field picker tile & editor |
  | `get_form_editor_button()` | Returns `['group' => ..., 'text' => ...]` for picker placement |
  | `get_form_editor_field_settings()` | Array of setting-class strings to show when the field is selected in the editor |
  | `get_field_input($form, $value, $entry)` | HTML for the inside of the `.ginput_container` div (required — default returns empty) |
  | `get_field_content($value, $force_frontend_label, $form)` | Full field wrapper (admin buttons, label, description, validation). Usually inherited. |
  | `validate($value, $form)` | Custom validation — sets `$this->failed_validation` + `$this->validation_message` |
  | `get_value_save_entry(...)` | Format value before DB save |
  | `get_form_inline_script_on_page_render($form)` | JS emitted with form init |
  | `is_conditional_logic_supported()` | Return `true` to enable conditional-logic config |

- **Icon**: the GF_Field doc does **not** document an icon method. Real fields (Phone, Consent) use CSS classes on the picker button + SVGs shipped via the GF plugin's own CSS. For our MVP:
  - Set a recognisable CSS class on the picker button (e.g. `gform_editor_button_sendtomp_mp_lookup`)
  - Ship a small stylesheet for the form editor that paints a data-URI SVG as the button's `::before` background
  - `get_form_editor_inline_script_on_page_render()` (if we need editor JS) or enqueue admin CSS via `admin_enqueue_scripts` gated on the GF form-edit page

  If it turns out newer GF (2.9+) exposes a nicer `get_form_editor_field_icon()` in source even though it's undocumented, we can move to that in a follow-up — not blocking v1.5.0.

---

## Scope — v1.5.0

### In scope

1. **New class** `SendToMP_GF_Field_MP_Lookup` extending `GF_Field_Text`
   - `public $type = 'sendtomp_mp_lookup'`
   - Overrides: `get_form_editor_field_title()`, `get_form_editor_button()`, `get_form_editor_field_settings()`, `get_field_input()`
   - Inherits everything else from Single Line Text (validation, sanitisation, save, merge-tag resolution)
2. **File**: `adapters/gravity-forms/class-gf-field-sendtomp-mp-lookup.php` (new)
3. **Registration**: inside `SendToMP_GF_Adapter::__construct()` (or a small wrapper method), `GF_Fields::register( new SendToMP_GF_Field_MP_Lookup() )`, guarded by `class_exists( 'GF_Field_Text' )`.
4. **Frontend output** (from `get_field_input()`): the input carries `class="sendtomp-postcode"` and `autocomplete="postal-code"`. The existing `assets/js/sendtomp-postcode-lookup.js` already binds to `.sendtomp-postcode` — zero JS changes needed.
5. **Editor button styling**: a tiny CSS file (`assets/css/sendtomp-gf-editor.css`) enqueued only on GF form-edit admin pages, painting an inline-SVG map-pin/letter icon on the button via `::before`.
6. **Adapter auto-detection of the custom field as postcode source**:
   - Current flow reads `rgar( $feed['meta'], 'fieldMap_constituent_postcode' )` (class-sendtomp-gf-adapter.php:562) to find the postcode field id.
   - New helper `find_mp_lookup_field_id( $form )` returns the ID of the first `sendtomp_mp_lookup` field in the form, or `null`.
   - In `process_feed()`: if the feed's `fieldMap_constituent_postcode` is empty AND a lookup field exists, use that field's value instead.
   - In `maybe_add_postcode_preview_class()`: already unnecessary for lookup fields (input carries the class intrinsically), but keep for legacy forms that still use the mapping path.
7. **Version bump**: `sendtomp.php` header (line 6), `SENDTOMP_VERSION` constant (line 18), `readme.txt` Stable tag → `1.5.0`.
8. **Changelog** entry (`readme.txt`): headline feature + feed-mapping auto-detection.
9. **Release**: tag `v1.5.0`, watch `release.yml` + `deploy-testbed.yml`, verify on untruecrime.uk, share ZIP URL with Mike.

### Out of scope (deferred — noted in `project_v2_roadmap.md`)

- **Custom settings panel** — inherit the standard text-field settings (label, description, required, CSS class, conditional logic, placeholder, default value). No new settings added in v1.5.0.
- **Replacing the feed's `fieldMap_constituent_postcode` mapping UI** — mapping is still supported for forms that don't use the custom field. Don't yank it.
- **Adapter slug rename** (`get_slug()` → `get_adapter_slug()` — the shadow over `GFFeedAddOn::get_slug()` — still deferred; unrelated to this release.
- **Auto-creating a SendToMP feed** when the custom field is added to a form — nice for v1.6.
- **Polished SVG icon artwork** — ship a simple inline SVG first; refine later.

---

## Files to create / modify

| Path | Action | Notes |
|---|---|---|
| `adapters/gravity-forms/class-gf-field-sendtomp-mp-lookup.php` | **new** | GF_Field_Text subclass |
| `adapters/gravity-forms/class-sendtomp-gf-adapter.php` | **modify** | `require_once` + `GF_Fields::register()` in ctor; `find_mp_lookup_field_id()` helper; `process_feed()` auto-detect branch |
| `assets/css/sendtomp-gf-editor.css` | **new** | Button icon + any admin-side field-editor polish |
| `includes/class-sendtomp.php` | **modify** | Enqueue the editor CSS on GF form-edit pages (`admin_enqueue_scripts` guarded by `$hook === 'toplevel_page_gf_edit_forms'` or similar) |
| `sendtomp.php` | **modify** | Version header → 1.5.0 |
| `sendtomp.php` | **modify** | `SENDTOMP_VERSION` → 1.5.0 |
| `readme.txt` | **modify** | Stable tag + changelog entry |
| `PLAN.md` | **delete** at end | (or roll into release notes) |

---

## Skeleton code (ready to paste-and-edit)

```php
<?php
/**
 * SendToMP_GF_Field_MP_Lookup — custom Gravity Forms field for UK postcode
 * → MP lookup. Extends the built-in Single Line Text field so we inherit
 * all the standard editor settings, validation, and save behaviour; we
 * only override the bits that need to be SendToMP-aware.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'GF_Field_Text' ) ) {
    return;
}

class SendToMP_GF_Field_MP_Lookup extends GF_Field_Text {

    public $type = 'sendtomp_mp_lookup';

    public function get_form_editor_field_title() {
        return esc_html__( 'MP Lookup', 'sendtomp' );
    }

    /**
     * Self-register into the Advanced Fields group of the picker.
     */
    public function get_form_editor_button() {
        return [
            'group' => 'advanced_fields',
            'text'  => $this->get_form_editor_field_title(),
        ];
    }

    /**
     * Inherit the standard Single Line Text settings. No SendToMP-specific
     * settings in v1.5.0 — the lookup is wired purely from the field's
     * presence in the form.
     */
    public function get_form_editor_field_settings() {
        return [
            'conditional_logic_field_setting',
            'prepopulate_field_setting',
            'error_message_setting',
            'label_setting',
            'label_placement_setting',
            'admin_label_setting',
            'size_setting',
            'default_value_setting',
            'placeholder_setting',
            'rules_setting',
            'visibility_setting',
            'description_setting',
            'css_class_setting',
        ];
    }

    /**
     * Render the input. We always include the `sendtomp-postcode` class
     * — the existing frontend JS (assets/js/sendtomp-postcode-lookup.js)
     * binds to that class and renders the Find-my-MP button + portrait
     * preview. No feed is required for the preview to work.
     */
    public function get_field_input( $form, $value = '', $entry = null ) {
        $form_id         = absint( $form['id'] );
        $is_entry_detail = $this->is_entry_detail();
        $is_form_editor  = $this->is_form_editor();
        $id              = (int) $this->id;
        $field_id        = ( $is_entry_detail || $is_form_editor || 0 === $form_id )
            ? "input_{$id}"
            : "input_{$form_id}_{$id}";

        $size          = esc_attr( $this->size );
        $class_suffix  = $is_entry_detail ? '_admin' : '';
        $disabled_text = $is_form_editor ? 'disabled="disabled"' : '';
        $placeholder   = $this->get_field_placeholder_attribute();
        $value_attr    = esc_attr( $value );

        $class = trim( 'sendtomp-postcode ' . $size . $class_suffix );

        return sprintf(
            "<div class='ginput_container ginput_container_text'>"
            . "<input name='input_%1\$d' id='%2\$s' type='text' "
            . "autocomplete='postal-code' value='%3\$s' class='%4\$s' %5\$s %6\$s />"
            . "</div>",
            $id,
            esc_attr( $field_id ),
            $value_attr,
            esc_attr( $class ),
            $placeholder,
            $disabled_text
        );
    }
}
```

Adapter changes (excerpt):

```php
// In class-sendtomp-gf-adapter.php, after include checks but before class def:
require_once __DIR__ . '/class-gf-field-sendtomp-mp-lookup.php';

// In __construct() or a dedicated register hook:
if ( class_exists( 'GF_Fields' ) && class_exists( 'SendToMP_GF_Field_MP_Lookup' ) ) {
    GF_Fields::register( new SendToMP_GF_Field_MP_Lookup() );
}

// New helper:
private function find_mp_lookup_field_id( $form ) {
    if ( empty( $form['fields'] ) ) {
        return null;
    }
    foreach ( $form['fields'] as $field ) {
        if ( isset( $field->type ) && 'sendtomp_mp_lookup' === $field->type ) {
            return (int) $field->id;
        }
    }
    return null;
}

// In process_feed(), replace the single-line rgar() for postcode with:
$postcode_field_id = rgar( $feed['meta'], 'fieldMap_constituent_postcode' );
if ( '' === (string) $postcode_field_id ) {
    $auto_id = $this->find_mp_lookup_field_id( $form );
    if ( $auto_id ) {
        $postcode_field_id = $auto_id;
    }
}
$constituent_postcode = sanitize_text_field(
    $this->get_field_value( $form, $entry, $postcode_field_id )
);
```

---

## Step order (tight, verifiable)

1. Create `class-gf-field-sendtomp-mp-lookup.php` with the skeleton above.
2. Modify `class-sendtomp-gf-adapter.php`: `require_once` + `GF_Fields::register()`; add helper; patch `process_feed()` to auto-detect.
3. `php -l` every modified file locally (the deploy workflow does this anyway, but catch it early).
4. Bump version in the three places (`sendtomp.php` header + constant + `readme.txt` Stable tag).
5. Write changelog entry in `readme.txt`.
6. Commit.
7. Push → watch GitHub Actions → verify on untruecrime.uk:
   - Form editor: does "MP Lookup" appear under **Advanced Fields**? Drag onto form 1 — does it save?
   - Frontend: publish, hit /anon/, postcode field shows the Find-my-MP button + portrait preview with no feed configured?
   - Submit: with the feed's postcode mapping left empty, does the submission resolve to the right MP via auto-detection?
   - Regression: existing forms (feed mapping used) still work?
8. `gh release create v1.5.0 --generate-notes` once green.
9. Share the release ZIP URL with Mike for the wp.org submission update.
10. Update memory: graduate "custom GF field type" out of `project_v2_roadmap.md`; add a note to `project_plugin_architecture.md` about the new field type + auto-detection branch.

---

## Gotchas to avoid re-discovering

1. **`SendToMP_GF_Adapter::get_slug()` returns `'gravity-forms'`** (internal adapter-routing identifier) and shadows `GFFeedAddOn::get_slug()`. Feeds are persisted with `addon_slug='gravity-forms'`. Any `GFAPI::get_feeds()` call must use that slug. Deferred rename is in `project_v2_roadmap.md`.
2. **PHP writes admin-request errors to `/wp-admin/php_errorlog`**, not the site-root `/php_errorlog` — `error_log()` is relative to the executing script's directory on this host. Check both locations when tracing.
3. **`gform_admin_messages` only fires where `GFCommon::display_admin_message()` is called** — form settings subviews yes, form editor no. Don't reuse that hook here.
4. **SiteGround Dynamic Cache was briefly implicated but not actually the culprit** for last session's notice-invisibility. Don't rabbit-hole cache debugging first.
5. **MHTML snapshots at `docs/knowledge/gravity-forms/` are Settings-API docs, not Form-Field-Framework docs** — confirmed on the live site. Don't waste time re-extracting them; prefer live WebFetch on `docs.gravityforms.com/gf_field/*`.

---

## Release checklist

- [ ] `SendToMP_GF_Field_MP_Lookup` class created, `php -l` clean
- [ ] Field appears under **Advanced Fields** in the form editor picker
- [ ] Dropping onto a form saves successfully; re-opening the editor shows the field
- [ ] Standard text-field settings (label, description, required, CSS class, conditional logic) all work
- [ ] Frontend: `.sendtomp-postcode` class present on the rendered input; Find-my-MP button + portrait preview render with no feed configured
- [ ] Submission with empty `fieldMap_constituent_postcode` + present MP Lookup field resolves to the right MP
- [ ] Regression: existing forms with plain postcode + feed mapping still work
- [ ] Version bumped in three places (`sendtomp.php` header, `SENDTOMP_VERSION`, `readme.txt` Stable tag)
- [ ] Changelog entry written
- [ ] Tag `v1.5.0` pushed; `release.yml` + `deploy-testbed.yml` both green
- [ ] Live verification on untruecrime.uk/anon/
- [ ] ZIP URL shared with Mike for wp.org submission
- [ ] Memory updated: custom GF field type graduated from v2 roadmap; new capability noted in architecture memory

---

## A poem for the road

> Six hooks, three caches, two different log files deep —
> I tried to teach a whisper how to speak
> through walls that weren't mine. The notice never shown,
> the builder never heard, the foreman's voice alone:
> *"If the room won't hear you, stop calling from the hall —
> come inside, put up a door, and knock from within the wall."*
>
> Tomorrow we drag and drop. The field picks up the phone.

— *Claude, end of a long circle, 2026-04-21*
