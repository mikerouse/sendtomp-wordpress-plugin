<?php
/**
 * SendToMP_WPForms_Adapter — WPForms adapter for SendToMP.
 *
 * Hooks into WPForms' submission process and adds a SendToMP settings
 * panel to the WPForms form builder for field mapping configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_WPForms_Adapter extends SendToMP_Form_Adapter_Abstract {

	/**
	 * Return the adapter slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'wpforms';
	}

	/**
	 * Return the human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'WPForms';
	}

	/**
	 * Whether WPForms is active and usable.
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool {
		return function_exists( 'wpforms' );
	}

	/**
	 * Register hooks into WPForms.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wpforms_process_complete', [ $this, 'handle_submission' ], 10, 4 );
		add_filter( 'wpforms_builder_settings_sections', [ $this, 'add_builder_section' ], 20, 2 );
		add_action( 'wpforms_form_settings_panel_content', [ $this, 'render_builder_section' ], 20 );
		add_action( 'wpforms_builder_enqueues', [ $this, 'enqueue_builder_assets' ] );
	}

	/**
	 * Return a list of available WPForms forms.
	 *
	 * @return array
	 */
	public function get_available_forms(): array {
		if ( ! function_exists( 'wpforms' ) ) {
			return [];
		}

		$forms = wpforms()->form->get( '', [ 'orderby' => 'title' ] );

		if ( empty( $forms ) ) {
			return [];
		}

		$result = [];

		foreach ( $forms as $form ) {
			$result[] = [
				'id'    => $form->ID,
				'title' => $form->post_title,
			];
		}

		return $result;
	}

	/**
	 * Return the fields of a given WPForms form.
	 *
	 * @param int|string $form_id The form identifier.
	 * @return array
	 */
	public function get_form_fields( $form_id ): array {
		if ( ! function_exists( 'wpforms' ) ) {
			return [];
		}

		$form_obj = wpforms()->form->get( absint( $form_id ) );

		if ( ! $form_obj ) {
			return [];
		}

		$form_data = wpforms_decode( $form_obj->post_content );

		if ( empty( $form_data['fields'] ) ) {
			return [];
		}

		$fields = [];

		foreach ( $form_data['fields'] as $field ) {
			$fields[] = [
				'id'    => $field['id'],
				'label' => $field['label'],
			];
		}

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Submission handling
	// -------------------------------------------------------------------------

	/**
	 * Handle a WPForms form submission.
	 *
	 * @param array $fields    Sanitised field data.
	 * @param array $entry     Entry data.
	 * @param array $form_data Form settings and field configuration.
	 * @param int   $entry_id  Entry ID.
	 * @return void
	 */
	public function handle_submission( array $fields, array $entry, array $form_data, int $entry_id ): void {
		$settings = isset( $form_data['settings']['sendtomp'] ) ? $form_data['settings']['sendtomp'] : [];

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$mapped_data = [
			'constituent_name'     => sanitize_text_field( $this->get_mapped_value( $fields, $settings, 'field_constituent_name' ) ),
			'constituent_email'    => sanitize_email( $this->get_mapped_value( $fields, $settings, 'field_constituent_email' ) ),
			'constituent_postcode' => sanitize_text_field( $this->get_mapped_value( $fields, $settings, 'field_constituent_postcode' ) ),
			'constituent_address'  => sanitize_text_field( $this->get_mapped_value( $fields, $settings, 'field_constituent_address' ) ),
			'message_subject'      => sanitize_text_field( $this->get_mapped_value( $fields, $settings, 'field_message_subject' ) ),
			'message_body'         => sanitize_textarea_field( $this->get_mapped_value( $fields, $settings, 'field_message_body' ) ),
		];

		$submission = $this->create_submission( $mapped_data );
		$submission->source_form_id = (string) $form_data['id'];
		$submission->target_house   = isset( $settings['target_house'] ) ? sanitize_text_field( $settings['target_house'] ) : 'commons';
		$submission->raw_data       = $entry;

		$result = $this->process_submission( $submission );

		if ( is_wp_error( $result ) ) {
			SendToMP_Logger::log( $submission, 'error', $result->get_error_message() );
		}
	}

	/**
	 * Extract a mapped field value from the WPForms submission data.
	 *
	 * @param array  $fields      WPForms field data keyed by field ID.
	 * @param array  $settings    SendToMP settings for this form.
	 * @param string $setting_key The settings key (e.g. 'field_constituent_name').
	 * @return string
	 */
	private function get_mapped_value( array $fields, array $settings, string $setting_key ): string {
		$field_id = isset( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : '';

		if ( '' === $field_id || ! isset( $fields[ $field_id ] ) ) {
			return '';
		}

		return isset( $fields[ $field_id ]['value'] ) ? (string) $fields[ $field_id ]['value'] : '';
	}

	// -------------------------------------------------------------------------
	// WPForms builder integration
	// -------------------------------------------------------------------------

	/**
	 * Add a SendToMP section to the WPForms builder Settings panel.
	 *
	 * @param array  $sections Existing settings sections.
	 * @param string $form_id  The current form ID (unused).
	 * @return array
	 */
	public function add_builder_section( array $sections, $form_id ): array {
		$sections['sendtomp'] = esc_html__( 'SendToMP', 'sendtomp' );

		return $sections;
	}

	/**
	 * Render the SendToMP settings section inside the WPForms builder.
	 *
	 * @param object $instance The WPForms builder instance.
	 * @return void
	 */
	public function render_builder_section( $instance ): void {
		echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-sendtomp">';
		echo '<div class="wpforms-panel-content-section-title">';
		echo esc_html__( 'SendToMP', 'sendtomp' );
		echo '</div>';

		// Enable toggle.
		wpforms_panel_field(
			'toggle',
			'settings',
			'enabled',
			$instance->form_data,
			esc_html__( 'Enable SendToMP for this form', 'sendtomp' ),
			[
				'parent'  => 'settings',
				'subsection' => 'sendtomp',
			]
		);

		// Target house.
		wpforms_panel_field(
			'select',
			'settings',
			'target_house',
			$instance->form_data,
			esc_html__( 'Target House', 'sendtomp' ),
			[
				'parent'  => 'settings',
				'subsection' => 'sendtomp',
				'default' => 'commons',
				'options' => [
					'commons' => esc_html__( 'House of Commons', 'sendtomp' ),
					'lords'   => esc_html__( 'House of Lords', 'sendtomp' ),
				],
				'tooltip' => esc_html__( 'Select which House of Parliament to send messages to.', 'sendtomp' ),
			]
		);

		// Field mapping.
		$mapping_fields = [
			'field_constituent_name'     => __( 'Constituent Name', 'sendtomp' ),
			'field_constituent_email'    => __( 'Email Address', 'sendtomp' ),
			'field_constituent_postcode' => __( 'Postcode', 'sendtomp' ),
			'field_constituent_address'  => __( 'Full Address (optional)', 'sendtomp' ),
			'field_message_subject'      => __( 'Message Subject (optional)', 'sendtomp' ),
			'field_message_body'         => __( 'Message Body', 'sendtomp' ),
		];

		echo '<div class="wpforms-panel-content-section-title">';
		echo esc_html__( 'Field Mapping', 'sendtomp' );
		echo '</div>';
		echo '<p class="wpforms-panel-content-section-description">';
		echo esc_html__( 'Map your form fields to the SendToMP submission fields.', 'sendtomp' );
		echo '</p>';

		// Build options from form fields.
		$field_options = [ '' => esc_html__( '-- Select Field --', 'sendtomp' ) ];

		if ( ! empty( $instance->form_data['fields'] ) ) {
			foreach ( $instance->form_data['fields'] as $field ) {
				$field_options[ $field['id'] ] = $field['label'];
			}
		}

		foreach ( $mapping_fields as $key => $label ) {
			wpforms_panel_field(
				'select',
				'settings',
				$key,
				$instance->form_data,
				$label,
				[
					'parent'     => 'settings',
					'subsection' => 'sendtomp',
					'options'    => $field_options,
				]
			);
		}

		echo '</div>';
	}

	/**
	 * Enqueue builder assets for the SendToMP panel.
	 *
	 * @return void
	 */
	public function enqueue_builder_assets(): void {
		wp_enqueue_script(
			'sendtomp-wpforms-builder',
			SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-wpforms-builder.js',
			[ 'wpforms-builder' ],
			SENDTOMP_VERSION,
			true
		);
	}
}
