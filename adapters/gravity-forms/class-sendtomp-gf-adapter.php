<?php
/**
 * SendToMP_GF_Adapter — Gravity Forms Feed AddOn adapter for SendToMP.
 *
 * Bridges Gravity Forms' Feed AddOn framework with the SendToMP submission
 * pipeline. Because PHP does not support multiple inheritance, the processing
 * pipeline from SendToMP_Form_Adapter_Abstract is inlined as a private helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure the adapter interface is available.
require_once dirname( __DIR__ ) . '/interface-sendtomp-form-adapter.php';

// Ensure the GF addon framework is loaded before defining the class.
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
	protected $_version                = SENDTOMP_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                   = 'sendtomp';
	protected $_path                   = 'sendtomp/sendtomp.php';
	protected $_full_path              = __FILE__;
	protected $_title                  = 'SendToMP';
	protected $_short_title            = 'SendToMP';

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
	 * Extracts mapped field values, builds a submission, and runs the
	 * full SendToMP processing pipeline.
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

		$target_house  = rgar( $feed['meta'], 'target_house', 'commons' );
		$source_form_id = (string) $form['id'];

		$result = $this->run_submission_pipeline( $mapped_data, $target_house, $source_form_id, $entry );

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
		return esc_html( ucfirst( rgar( $feed['meta'], 'target_house' ) ) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Run the full SendToMP submission pipeline.
	 *
	 * This mirrors the logic from SendToMP_Form_Adapter_Abstract::process_submission()
	 * but is inlined here because PHP cannot extend both GFFeedAddOn and the abstract.
	 *
	 * @param array  $mapped_data  Mapped form field values.
	 * @param string $target_house 'commons' or 'lords'.
	 * @param string $form_id      The source form ID.
	 * @param array  $entry        The raw GF entry (stored as raw_data).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function run_submission_pipeline( array $mapped_data, string $target_house, string $form_id, array $entry ) {
		// 1. Create submission from mapped data.
		$submission = new SendToMP_Submission( $mapped_data );

		// 2. Set source metadata.
		$submission->source_adapter = 'gravity-forms';
		$submission->source_form_id = $form_id;
		$submission->target_house   = $target_house;

		// 3. Set raw data and metadata.
		$submission->raw_data = $entry;
		$submission->metadata = [
			'timestamp' => gmdate( 'Y-m-d H:i:s' ),
			'ip'        => class_exists( 'GFFormsModel' ) ? GFFormsModel::get_ip() : $this->get_client_ip(),
		];

		// 4. Normalise postcode.
		$submission->normalise_postcode();

		// 5. Validate.
		$valid = $submission->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// 6. Rate limit check.
		$rate_limiter = new SendToMP_Rate_Limiter();
		$rate_check   = $rate_limiter->check( $submission );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// 7. Resolve member via API client.
		$api_client = new SendToMP_API_Client();
		$api_result = $api_client->resolve_member( $submission->constituent_postcode, $submission->target_house );
		if ( is_wp_error( $api_result ) ) {
			return $api_result;
		}

		// 8. Build resolved_member array — normalise keys to match what the mailer expects.
		$member   = ! empty( $api_result['member'] ) ? $api_result['member'] : [];
		$delivery = ! empty( $api_result['delivery'] ) ? $api_result['delivery'] : [];

		$resolved_member = [
			'id'               => isset( $member['id'] ) ? (int) $member['id'] : 0,
			'name'             => isset( $member['name'] ) ? $member['name'] : '',
			'party'            => isset( $member['party'] ) ? $member['party'] : '',
			'constituency'     => isset( $member['constituency'] ) ? $member['constituency'] : '',
			'house'            => isset( $member['house'] ) ? $member['house'] : $submission->target_house,
			'delivery_email'   => isset( $delivery['email'] ) ? $delivery['email'] : '',
			'override_applied' => isset( $delivery['override_applied'] ) ? $delivery['override_applied'] : false,
		];

		$submission->resolved_member = $resolved_member;
		$submission->target_member_id = $resolved_member['id'];

		// 9. Store pending submission.
		$confirmation = new SendToMP_Confirmation();
		$token        = $confirmation->store_pending( $submission, $resolved_member );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// 10. Send confirmation email.
		$mp_name         = isset( $resolved_member['name'] ) ? $resolved_member['name'] : '';
		$mp_constituency = isset( $resolved_member['constituency'] ) ? $resolved_member['constituency'] : '';

		$mailer      = new SendToMP_Mailer();
		$mail_result = $mailer->send_confirmation( $submission, $token, $mp_name, $mp_constituency );
		if ( is_wp_error( $mail_result ) ) {
			return $mail_result;
		}

		// 11. Log as pending_confirmation.
		if ( class_exists( 'SendToMP_Logger' ) ) {
			SendToMP_Logger::log( $submission, 'pending_confirmation' );
		}

		// 12. Success.
		return true;
	}

	/**
	 * Get the client IP address as a fallback when GFFormsModel is unavailable.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		} else {
			$ip = '0.0.0.0';
		}

		$validated = filter_var( $ip, FILTER_VALIDATE_IP );

		return $validated ? $validated : '0.0.0.0';
	}
}
