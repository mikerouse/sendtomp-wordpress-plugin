<?php
/**
 * SendToMP_GF_Field_MP_Lookup — custom Gravity Forms field for UK postcode
 * → MP lookup.
 *
 * Extends the built-in Single Line Text field so we inherit all the standard
 * editor settings, validation, and save behaviour. We only override what
 * we need: picker placement, title, and the list of editor settings shown
 * when the field is selected.
 *
 * Frontend wiring happens elsewhere — the adapter's
 * maybe_add_postcode_preview_class() tags any field of this type with the
 * `sendtomp-postcode` CSS class, which the existing frontend script
 * (assets/js/sendtomp-postcode-lookup.js) binds to.
 *
 * @package SendToMP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GF_Field_Text' ) ) {
	return;
}

/**
 * Class SendToMP_GF_Field_MP_Lookup
 */
class SendToMP_GF_Field_MP_Lookup extends GF_Field_Text {

	/**
	 * Unique field type identifier.
	 *
	 * @var string
	 */
	public $type = 'sendtomp_mp_lookup';

	/**
	 * Display title used in the field picker and the form editor sidebar.
	 *
	 * @return string
	 */
	public function get_form_editor_field_title() {
		return esc_attr__( 'Find My MP', 'sendtomp' );
	}

	/**
	 * Helper text shown under the field title in the form editor sidebar.
	 *
	 * @return string
	 */
	public function get_form_editor_field_description() {
		return esc_attr__( 'Allows users to find their MP by entering their UK post code.', 'sendtomp' );
	}

	/**
	 * Self-register into the Advanced Fields group of the field picker.
	 *
	 * @return array{group: string, text: string}
	 */
	public function get_form_editor_button() {
		return [
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		];
	}

	/**
	 * Settings shown in the form editor when this field is selected.
	 *
	 * Inherits Single Line Text's standard settings — label, description,
	 * placeholder, default value, CSS class, required, conditional logic,
	 * etc. No SendToMP-specific settings in v1.5.0; the lookup is wired
	 * purely from the field's presence in the form.
	 *
	 * @return string[]
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
}
