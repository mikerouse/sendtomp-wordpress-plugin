<?php
/**
 * SendToMP_Form_Adapter_Interface — contract for form plugin adapters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SendToMP_Form_Adapter_Interface {

	/**
	 * Return the adapter slug (e.g. 'gravity-forms').
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Return the human-readable label (e.g. 'Gravity Forms').
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Whether the underlying form plugin is active and usable.
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool;

	/**
	 * Register hooks into the form plugin (e.g. submission handlers).
	 *
	 * @return void
	 */
	public function register_hooks(): void;

	/**
	 * Return a list of available forms for the field-mapping UI.
	 *
	 * Each element should be an associative array with at least 'id' and 'title'.
	 *
	 * @return array
	 */
	public function get_available_forms(): array;

	/**
	 * Return the fields of a given form for the field-mapping UI.
	 *
	 * Each element should be an associative array with at least 'id' and 'label'.
	 *
	 * @param int|string $form_id The form identifier.
	 * @return array
	 */
	public function get_form_fields( $form_id ): array;
}
