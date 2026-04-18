<?php
/**
 * SendToMP_Form_Adapter_Abstract — shared processing logic for all form adapters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SendToMP_Form_Adapter_Abstract implements SendToMP_Form_Adapter_Interface {

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
