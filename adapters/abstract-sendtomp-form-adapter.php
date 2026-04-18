<?php
/**
 * SendToMP_Form_Adapter_Abstract — shared processing logic for all form adapters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SendToMP_Form_Adapter_Abstract implements SendToMP_Form_Adapter_Interface {

	/**
	 * Canonical mapping field definitions shared across all adapters.
	 */
	const MAPPING_FIELDS = [
		'constituent_name'     => [ 'label' => 'Constituent Name', 'required' => true, 'sanitize' => 'sanitize_text_field' ],
		'constituent_email'    => [ 'label' => 'Email Address', 'required' => true, 'sanitize' => 'sanitize_email' ],
		'constituent_postcode' => [ 'label' => 'Postcode', 'required' => false, 'sanitize' => 'sanitize_text_field' ],
		'constituent_address'  => [ 'label' => 'Full Address', 'required' => false, 'sanitize' => 'sanitize_text_field' ],
		'message_subject'      => [ 'label' => 'Message Subject', 'required' => false, 'sanitize' => 'sanitize_text_field' ],
		'message_body'         => [ 'label' => 'Message Body', 'required' => true, 'sanitize' => 'sanitize_textarea_field' ],
	];

	/**
	 * Sanitize raw field values using the canonical mapping field definitions.
	 *
	 * @param array $raw_values Associative array of raw values keyed by field name.
	 * @return array Sanitized values.
	 */
	public static function sanitize_mapped_data( array $raw_values ): array {
		$sanitized = [];

		foreach ( self::MAPPING_FIELDS as $key => $field ) {
			$value = isset( $raw_values[ $key ] ) ? $raw_values[ $key ] : '';
			$sanitized[ $key ] = call_user_func( $field['sanitize'], $value );
		}

		return $sanitized;
	}

	/**
	 * Create a SendToMP_Submission from mapped form data.
	 *
	 * @param array $mapped_data Keys matching submission properties.
	 * @return SendToMP_Submission
	 */
	public function create_submission( array $mapped_data ): SendToMP_Submission {
		$mapped_data['source_adapter'] = $this->get_slug();

		$mapped_data['metadata'] = array_merge(
			isset( $mapped_data['metadata'] ) && is_array( $mapped_data['metadata'] ) ? $mapped_data['metadata'] : [],
			[
				'submitted_at' => gmdate( 'Y-m-d H:i:s' ),
				'client_ip'    => SendToMP_Pipeline::get_client_ip(),
			]
		);

		return new SendToMP_Submission( $mapped_data );
	}

	/**
	 * Main processing pipeline for a submission.
	 *
	 * @param SendToMP_Submission $submission The submission to process.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function process_submission( SendToMP_Submission $submission ) {
		return SendToMP_Pipeline::process( $submission );
	}

	/**
	 * Enqueue the shared peer search autocomplete script with localisation.
	 *
	 * Call from adapter-specific enqueue hooks to avoid copy-pasting
	 * the same enqueue + localize block in every adapter.
	 *
	 * @return void
	 */
	public static function enqueue_peer_search(): void {
		if ( wp_script_is( 'sendtomp-peer-search', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'sendtomp-peer-search',
			SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-peer-search.js',
			[ 'jquery' ],
			SENDTOMP_VERSION,
			true
		);

		wp_localize_script( 'sendtomp-peer-search', 'sendtomp_peer_search', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sendtomp_admin' ),
		] );
	}

	/**
	 * @inheritDoc
	 */
	abstract public function get_slug(): string;

	/**
	 * @inheritDoc
	 */
	abstract public function get_label(): string;

	/**
	 * @inheritDoc
	 */
	abstract public function is_plugin_active(): bool;

	/**
	 * @inheritDoc
	 */
	abstract public function register_hooks(): void;

	/**
	 * @inheritDoc
	 */
	abstract public function get_available_forms(): array;

	/**
	 * @inheritDoc
	 */
	abstract public function get_form_fields( $form_id ): array;
}
