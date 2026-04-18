<?php
/**
 * SendToMP_GF_Adapter — Gravity Forms Feed AddOn adapter for SendToMP.
 *
 * Bridges Gravity Forms' Feed AddOn framework with the SendToMP submission
 * pipeline. Delegates processing to SendToMP_Pipeline to avoid duplicating
 * the shared pipeline logic (PHP single-inheritance prevents extending
 * both GFFeedAddOn and SendToMP_Form_Adapter_Abstract).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure the adapter interface is available.
require_once dirname( __DIR__ ) . '/interface-sendtomp-form-adapter.php';

// Ensure the GF addon framework is loaded before defining the class.
if ( ! class_exists( 'GFForms' ) ) {
	return;
}
GFForms::include_addon_framework();

/**
 * Class SendToMP_GF_Adapter
 *
 * Extends GFFeedAddOn for native GF feed management and implements
 * SendToMP_Form_Adapter_Interface for plugin-agnostic adapter discovery.
 */
class SendToMP_GF_Adapter extends GFFeedAddOn implements SendToMP_Form_Adapter_Interface {

	/**
	 * GFFeedAddOn required properties.
	 */
	protected $_version                  = SENDTOMP_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'sendtomp';
	protected $_path                     = SENDTOMP_PLUGIN_BASENAME;
	protected $_full_path                = __FILE__;
	protected $_title                    = 'SendToMP';
	protected $_short_title              = 'SendToMP';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the singleton instance (required by GF).
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// SendToMP_Form_Adapter_Interface methods
	// -------------------------------------------------------------------------

	/**
	 * Return the adapter slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'gravity-forms';
	}

	/**
	 * Return the human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Gravity Forms';
	}

	/**
	 * Whether Gravity Forms is active and usable.
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool {
		return class_exists( 'GFForms' );
	}

	/**
	 * No-op — GFFeedAddOn handles hook registration internally.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Intentionally empty; GFFeedAddOn registers its own hooks.
	}

	/**
	 * Return a list of available Gravity Forms.
	 *
	 * @return array
	 */
	public function get_available_forms(): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		return GFAPI::get_forms();
	}

	/**
	 * Return the fields of a given form, mapped to [ id, label ].
	 *
	 * @param int|string $form_id The form identifier.
	 * @return array
	 */
	public function get_form_fields( $form_id ): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		$form = GFAPI::get_form( $form_id );

		if ( ! $form || empty( $form['fields'] ) ) {
			return [];
		}

		$fields = [];

		foreach ( $form['fields'] as $field ) {
			$fields[] = [
				'id'    => $field->id,
				'label' => $field->label,
			];
		}

		return $fields;
	}

	// -------------------------------------------------------------------------
	// GFFeedAddOn overrides
	// -------------------------------------------------------------------------

	/**
	 * Define columns for the feed list table.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return [
			'feedName'     => esc_html__( 'Name', 'sendtomp' ),
			'target_house' => esc_html__( 'Target House', 'sendtomp' ),
		];
	}

	/**
	 * Define the feed settings fields displayed when editing a feed.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		return [
			// Section 1: Core settings.
			[
				'title'  => esc_html__( 'Send to MP Settings', 'sendtomp' ),
				'fields' => [
					[
						'label'    => esc_html__( 'Name', 'sendtomp' ),
						'type'     => 'text',
						'name'     => 'feedName',
						'required' => true,
						'class'    => 'medium',
						'tooltip'  => esc_html__( 'Enter a name to identify this feed.', 'sendtomp' ),
					],
					[
						'label'         => esc_html__( 'Target House', 'sendtomp' ),
						'type'          => 'select',
						'name'          => 'target_house',
						'default_value' => 'commons',
						'choices'       => [
							[
								'label' => esc_html__( 'House of Commons', 'sendtomp' ),
								'value' => 'commons',
							],
							[
								'label' => esc_html__( 'House of Lords', 'sendtomp' ),
								'value' => 'lords',
							],
						],
						'tooltip' => esc_html__( 'Select which House of Parliament to send messages to.', 'sendtomp' ),
					],
				],
			],
			// Section 2: Field mapping.
			[
				'title'  => esc_html__( 'Field Mapping', 'sendtomp' ),
				'fields' => [
					[
						'label'      => esc_html__( 'Map Fields', 'sendtomp' ),
						'type'       => 'field_map',
						'name'       => 'fieldMap',
						'field_map'  => [
							[
								'name'     => 'constituent_name',
								'label'    => esc_html__( 'Constituent Name', 'sendtomp' ),
								'required' => true,
							],
							[
								'name'       => 'constituent_email',
								'label'      => esc_html__( 'Email Address', 'sendtomp' ),
								'required'   => true,
								'field_type' => [ 'email', 'hidden' ],
							],
							[
								'name'     => 'constituent_postcode',
								'label'    => esc_html__( 'Postcode', 'sendtomp' ),
								'required' => true,
							],
							[
								'name'     => 'constituent_address',
								'label'    => esc_html__( 'Full Address', 'sendtomp' ),
								'required' => false,
							],
							[
								'name'     => 'message_subject',
								'label'    => esc_html__( 'Message Subject', 'sendtomp' ),
								'required' => false,
							],
							[
								'name'       => 'message_body',
								'label'      => esc_html__( 'Message Body', 'sendtomp' ),
								'required'   => true,
								'field_type' => [ 'textarea', 'text', 'hidden', 'post_body' ],
							],
						],
					],
				],
			],
			// Section 3: Conditional logic.
			[
				'title'  => esc_html__( 'Conditional Logic', 'sendtomp' ),
				'fields' => [
					[
						'label' => esc_html__( 'Condition', 'sendtomp' ),
						'type'  => 'feed_condition',
						'name'  => 'feedCondition',
					],
				],
			],
		];
	}

	/**
	 * Process the feed when a form is submitted.
	 *
	 * Extracts mapped field values, builds a submission, and delegates
	 * to the shared SendToMP_Pipeline for processing.
	 *
	 * @param array $feed  The feed configuration.
	 * @param array $entry The form entry.
	 * @param array $form  The form object.
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form ) {
		// Extract mapped field values.
		$mapped_data = [
			'constituent_name'     => sanitize_text_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_constituent_name' ) ) ),
			'constituent_email'    => sanitize_email( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_constituent_email' ) ) ),
			'constituent_postcode' => sanitize_text_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_constituent_postcode' ) ) ),
			'constituent_address'  => sanitize_text_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_constituent_address' ) ) ),
			'message_subject'      => sanitize_text_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_message_subject' ) ) ),
			'message_body'         => sanitize_textarea_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_message_body' ) ) ),
		];

		// Build the submission with consistent metadata keys.
		$submission = new SendToMP_Submission( $mapped_data );
		$submission->source_adapter = 'gravity-forms';
		$submission->source_form_id = (string) $form['id'];
		$submission->target_house   = rgar( $feed['meta'], 'target_house', 'commons' );
		$submission->raw_data       = $entry;
		$submission->metadata       = [
			'submitted_at' => gmdate( 'Y-m-d H:i:s' ),
			'client_ip'    => class_exists( 'GFFormsModel' ) ? GFFormsModel::get_ip() : SendToMP_Pipeline::get_client_ip(),
		];

		// Delegate to the shared pipeline.
		$result = SendToMP_Pipeline::process( $submission );

		if ( is_wp_error( $result ) ) {
			$this->add_feed_error(
				sprintf(
					/* translators: %s: error message */
					esc_html__( 'SendToMP feed processing failed: %s', 'sendtomp' ),
					$result->get_error_message()
				),
				$feed,
				$entry,
				$form
			);
		}
	}

	/**
	 * Render the Target House column value in the feed list.
	 *
	 * @param array $feed The feed configuration.
	 * @return string
	 */
	public function get_column_value_target_house( $feed ) {
		return esc_html( ucfirst( (string) rgar( $feed['meta'], 'target_house', 'commons' ) ) );
	}
}
