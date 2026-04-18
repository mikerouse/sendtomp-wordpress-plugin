<?php
/**
 * SendToMP_CF7_Adapter — Contact Form 7 adapter for SendToMP.
 *
 * Hooks into CF7's submission flow and adds a SendToMP tab to the
 * CF7 form editor for field mapping configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_CF7_Adapter extends SendToMP_Form_Adapter_Abstract {

	/**
	 * Post meta key for SendToMP settings on CF7 forms.
	 */
	const META_KEY = '_sendtomp_settings';

	/**
	 * Return the adapter slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'cf7';
	}

	/**
	 * Return the human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Contact Form 7';
	}

	/**
	 * Whether Contact Form 7 is active and usable.
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool {
		return defined( 'WPCF7_VERSION' );
	}

	/**
	 * Register hooks into Contact Form 7.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wpcf7_before_send_mail', [ $this, 'handle_submission' ], 10, 3 );
		add_filter( 'wpcf7_editor_panels', [ $this, 'add_editor_panel' ] );
		add_action( 'wpcf7_save_contact_form', [ $this, 'save_editor_panel' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
	}

	/**
	 * Return a list of available CF7 forms.
	 *
	 * @return array
	 */
	public function get_available_forms(): array {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return [];
		}

		$forms  = WPCF7_ContactForm::find( [ 'posts_per_page' => -1 ] );
		$result = [];

		foreach ( $forms as $form ) {
			$result[] = [
				'id'    => $form->id(),
				'title' => $form->title(),
			];
		}

		return $result;
	}

	/**
	 * Return the fields of a given CF7 form by scanning its form tags.
	 *
	 * @param int|string $form_id The form identifier.
	 * @return array
	 */
	public function get_form_fields( $form_id ): array {
		$form = wpcf7_contact_form( $form_id );

		if ( ! $form ) {
			return [];
		}

		$tags   = $form->scan_form_tags();
		$fields = [];

		foreach ( $tags as $tag ) {
			// Skip non-input tags (submit buttons, labels, etc.).
			if ( empty( $tag->name ) || 'submit' === $tag->basetype ) {
				continue;
			}

			$fields[] = [
				'id'    => $tag->name,
				'label' => $tag->name,
			];
		}

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Submission handling
	// -------------------------------------------------------------------------

	/**
	 * Handle a CF7 form submission.
	 *
	 * Fires before CF7 sends its own mail. We never abort CF7's mail —
	 * SendToMP processing is additive.
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form instance.
	 * @param bool              $abort        Whether to abort CF7's mail (never set by us).
	 * @param WPCF7_Submission  $cf7_submission The CF7 submission instance.
	 * @return void
	 */
	public function handle_submission( $contact_form, &$abort, $cf7_submission = null ): void {
		// Fallback for CF7 < 5.2 where $submission is not passed.
		if ( ! $cf7_submission && class_exists( 'WPCF7_Submission' ) ) {
			$cf7_submission = WPCF7_Submission::get_instance();
		}
		if ( ! $cf7_submission ) {
			return;
		}

		$form_id  = $contact_form->id();
		$settings = get_post_meta( $form_id, self::META_KEY, true );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$posted_data = $cf7_submission->get_posted_data();

		$mapped_data = [
			'constituent_name'     => sanitize_text_field( $this->get_mapped_value( $posted_data, $settings, 'field_constituent_name' ) ),
			'constituent_email'    => sanitize_email( $this->get_mapped_value( $posted_data, $settings, 'field_constituent_email' ) ),
			'constituent_postcode' => sanitize_text_field( $this->get_mapped_value( $posted_data, $settings, 'field_constituent_postcode' ) ),
			'constituent_address'  => sanitize_text_field( $this->get_mapped_value( $posted_data, $settings, 'field_constituent_address' ) ),
			'message_subject'      => sanitize_text_field( $this->get_mapped_value( $posted_data, $settings, 'field_message_subject' ) ),
			'message_body'         => sanitize_textarea_field( $this->get_mapped_value( $posted_data, $settings, 'field_message_body' ) ),
		];

		$submission = $this->create_submission( $mapped_data );
		$submission->source_form_id = (string) $form_id;
		$submission->target_house   = isset( $settings['target_house'] ) ? sanitize_text_field( $settings['target_house'] ) : 'commons';

		if ( 'lords' === $submission->target_house ) {
			$submission->target_member_id = isset( $settings['target_member_id'] ) ? (int) $settings['target_member_id'] : 0;
		}

		$submission->raw_data       = $posted_data;

		$result = $this->process_submission( $submission );

		if ( is_wp_error( $result ) ) {
			SendToMP_Logger::log( $submission, 'error', $result->get_error_message() );
		}
	}

	/**
	 * Extract a mapped field value from CF7 posted data.
	 *
	 * CF7 may return arrays for multi-value fields (checkboxes).
	 *
	 * @param array  $posted_data The posted data array.
	 * @param array  $settings    SendToMP settings for this form.
	 * @param string $setting_key The settings key (e.g. 'field_constituent_name').
	 * @return string
	 */
	private function get_mapped_value( array $posted_data, array $settings, string $setting_key ): string {
		$field_name = isset( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : '';

		if ( '' === $field_name || ! isset( $posted_data[ $field_name ] ) ) {
			return '';
		}

		$value = $posted_data[ $field_name ];

		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}

		return (string) $value;
	}

	// -------------------------------------------------------------------------
	// CF7 editor integration
	// -------------------------------------------------------------------------

	/**
	 * Add a SendToMP panel to the CF7 form editor.
	 *
	 * @param array $panels Existing editor panels.
	 * @return array
	 */
	public function add_editor_panel( array $panels ): array {
		$panels['sendtomp'] = [
			'title'    => __( 'SendToMP', 'sendtomp' ),
			'callback' => [ $this, 'render_editor_panel' ],
		];

		return $panels;
	}

	/**
	 * Render the SendToMP settings panel inside the CF7 editor.
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form being edited.
	 * @return void
	 */
	public function render_editor_panel( $contact_form ): void {
		$form_id  = $contact_form->id();
		$settings = get_post_meta( $form_id, self::META_KEY, true );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		// Get available field names from the form.
		$tags        = $contact_form->scan_form_tags();
		$field_names = [];

		foreach ( $tags as $tag ) {
			if ( ! empty( $tag->name ) && 'submit' !== $tag->basetype ) {
				$field_names[] = $tag->name;
			}
		}

		$enabled      = ! empty( $settings['enabled'] );
		$target_house = isset( $settings['target_house'] ) ? $settings['target_house'] : 'commons';

		$mapping_fields = [
			'field_constituent_name'     => __( 'Constituent Name', 'sendtomp' ),
			'field_constituent_email'    => __( 'Email Address', 'sendtomp' ),
			'field_constituent_postcode' => __( 'Postcode', 'sendtomp' ),
			'field_constituent_address'  => __( 'Full Address (optional)', 'sendtomp' ),
			'field_message_subject'      => __( 'Message Subject (optional)', 'sendtomp' ),
			'field_message_body'         => __( 'Message Body', 'sendtomp' ),
		];

		?>
		<h2><?php esc_html_e( 'SendToMP Settings', 'sendtomp' ); ?></h2>

		<fieldset>
			<legend><?php esc_html_e( 'When this form is submitted, send the message to the constituent\'s MP via SendToMP.', 'sendtomp' ); ?></legend>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sendtomp-enabled"><?php esc_html_e( 'Enable', 'sendtomp' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" id="sendtomp-enabled" name="sendtomp-enabled" value="1" <?php checked( $enabled ); ?> />
							<?php esc_html_e( 'Enable SendToMP for this form', 'sendtomp' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sendtomp-target_house"><?php esc_html_e( 'Target House', 'sendtomp' ); ?></label>
					</th>
					<td>
						<select id="sendtomp-target_house" name="sendtomp-target_house">
							<option value="commons" <?php selected( $target_house, 'commons' ); ?>><?php esc_html_e( 'House of Commons', 'sendtomp' ); ?></option>
							<option value="lords" <?php selected( $target_house, 'lords' ); ?>><?php esc_html_e( 'House of Lords', 'sendtomp' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="sendtomp-lords-only" style="<?php echo 'lords' === $target_house ? '' : 'display:none;'; ?>">
					<th scope="row">
						<label for="sendtomp-peer-search"><?php esc_html_e( 'Target Peer', 'sendtomp' ); ?></label>
					</th>
					<td>
						<input type="text" id="sendtomp-peer-search" name="sendtomp-peer-search" class="regular-text sendtomp-peer-search"
							placeholder="<?php esc_attr_e( 'Search for a Peer...', 'sendtomp' ); ?>"
							value="<?php echo esc_attr( isset( $settings['target_member_name'] ) ? $settings['target_member_name'] : '' ); ?>" />
						<input type="hidden" id="sendtomp-target_member_id" name="sendtomp-target_member_id"
							value="<?php echo esc_attr( isset( $settings['target_member_id'] ) ? $settings['target_member_id'] : '' ); ?>" />
						<p class="description"><?php esc_html_e( 'Required when Target House is Lords.', 'sendtomp' ); ?></p>
					</td>
				</tr>
			</table>

			<script>
			jQuery(function($) {
				$('#sendtomp-target_house').on('change', function() {
					if ($(this).val() === 'lords') {
						$('.sendtomp-lords-only').show();
					} else {
						$('.sendtomp-lords-only').hide();
					}
				});
			});
			</script>

			<h3><?php esc_html_e( 'Field Mapping', 'sendtomp' ); ?></h3>
			<p><?php esc_html_e( 'Map your form fields to the SendToMP submission fields.', 'sendtomp' ); ?></p>

			<table class="form-table" role="presentation">
				<?php foreach ( $mapping_fields as $key => $label ) :
					$current_value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
					?>
					<tr>
						<th scope="row">
							<label for="sendtomp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
						</th>
						<td>
							<select id="sendtomp-<?php echo esc_attr( $key ); ?>" name="sendtomp-<?php echo esc_attr( $key ); ?>">
								<option value=""><?php esc_html_e( '-- Select Field --', 'sendtomp' ); ?></option>
								<?php foreach ( $field_names as $name ) : ?>
									<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $current_value, $name ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</fieldset>
		<?php
	}

	/**
	 * Save SendToMP settings when the CF7 form editor is saved.
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form being saved.
	 * @return void
	 */
	public function save_editor_panel( $contact_form ): void {
		$form_id = $contact_form->id();

		check_admin_referer( 'wpcf7-save-contact-form_' . $form_id );

		if ( ! isset( $_POST['sendtomp-enabled'] ) && ! isset( $_POST['sendtomp-target_house'] ) ) {
			return;
		}

		$settings = [
			'enabled'                    => ! empty( $_POST['sendtomp-enabled'] ) ? '1' : '',
			'target_house'               => isset( $_POST['sendtomp-target_house'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-target_house'] ) ) : 'commons',
			'field_constituent_name'     => isset( $_POST['sendtomp-field_constituent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-field_constituent_name'] ) ) : '',
			'field_constituent_email'    => isset( $_POST['sendtomp-field_constituent_email'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-field_constituent_email'] ) ) : '',
			'field_constituent_postcode' => isset( $_POST['sendtomp-field_constituent_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-field_constituent_postcode'] ) ) : '',
			'field_constituent_address'  => isset( $_POST['sendtomp-field_constituent_address'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-field_constituent_address'] ) ) : '',
			'field_message_subject'      => isset( $_POST['sendtomp-field_message_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-field_message_subject'] ) ) : '',
			'field_message_body'         => isset( $_POST['sendtomp-field_message_body'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-field_message_body'] ) ) : '',
			'target_member_id'           => isset( $_POST['sendtomp-target_member_id'] ) ? absint( $_POST['sendtomp-target_member_id'] ) : 0,
			'target_member_name'         => isset( $_POST['sendtomp-peer-search'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp-peer-search'] ) ) : '',
		];

		// Validate target_house.
		if ( ! in_array( $settings['target_house'], [ 'commons', 'lords' ], true ) ) {
			$settings['target_house'] = 'commons';
		}

		update_post_meta( $form_id, self::META_KEY, $settings );
	}

	/**
	 * Enqueue peer search JS on CF7 editor pages.
	 *
	 * @param string $hook The admin page hook.
	 * @return void
	 */
	public function enqueue_editor_assets( string $hook ): void {
		if ( false === strpos( $hook, 'wpcf7' ) ) {
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
}
