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

// Ensure the GF feed addon framework is loaded before defining the class.
// Feed add-ons need include_feed_addon_framework() specifically; the plain
// include_addon_framework() only loads GFAddOn, not GFFeedAddOn.
if ( ! class_exists( 'GFForms' ) ) {
	return;
}
if ( method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
	GFForms::include_feed_addon_framework();
}
if ( ! class_exists( 'GFFeedAddOn' ) ) {
	return;
}

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

	/**
	 * Register additional hooks beyond GFFeedAddOn's defaults.
	 *
	 * @return void
	 */
	public function init() {
		parent::init();

		add_filter( 'gform_custom_merge_tags', [ $this, 'add_sendtomp_merge_tags' ], 10, 4 );
		add_filter( 'gform_pre_render', [ $this, 'maybe_add_postcode_preview_class' ] );
		add_filter( 'gform_pre_validation', [ $this, 'maybe_add_postcode_preview_class' ] );

		if ( is_admin() ) {
			add_action( 'admin_print_footer_scripts', [ $this, 'print_rich_editor_tag_data' ], 20 );
			add_action( 'admin_notices', [ $this, 'maybe_render_confirmation_handoff_notice' ] );
		}
	}

	/**
	 * If the Show Live MP Preview setting is on, attach the
	 * `sendtomp-postcode` CSS class to the form field mapped as the
	 * constituent postcode in any active SendToMP feed for this form.
	 *
	 * The frontend postcode-lookup JS picks up the class and renders
	 * the live MP preview beneath the field. This removes the need
	 * for site owners to hand-edit CSS Class Name in the field editor.
	 *
	 * @param array $form The Gravity Forms form object.
	 * @return array The (possibly modified) form.
	 */
	public function maybe_add_postcode_preview_class( $form ) {
		if ( ! function_exists( 'sendtomp' ) || ! sendtomp()->get_setting( 'show_mp_preview' ) ) {
			return $form;
		}

		if ( empty( $form['id'] ) || empty( $form['fields'] ) ) {
			return $form;
		}

		$feeds = $this->get_feeds( $form['id'] );
		if ( empty( $feeds ) ) {
			return $form;
		}

		$postcode_field_ids = [];
		foreach ( $feeds as $feed ) {
			if ( empty( $feed['is_active'] ) ) {
				continue;
			}
			$id = rgar( $feed['meta'], 'fieldMap_constituent_postcode' );
			if ( '' !== (string) $id ) {
				$postcode_field_ids[ (string) $id ] = true;
			}
		}

		if ( empty( $postcode_field_ids ) ) {
			return $form;
		}

		foreach ( $form['fields'] as $field ) {
			if ( ! isset( $postcode_field_ids[ (string) $field->id ] ) ) {
				continue;
			}
			$existing = (string) ( $field->cssClass ?? '' );
			if ( false === strpos( $existing, 'sendtomp-postcode' ) ) {
				$field->cssClass = trim( $existing . ' sendtomp-postcode' );
			}
		}

		return $form;
	}

	/**
	 * Shared HTML body for the post-submission handoff reminder. Rendered
	 * both inside the feed editor (as a section header block) and on the
	 * form's Confirmations admin page (as an admin notice).
	 *
	 * @return string
	 */
	private function render_handoff_notice_html(): string {
		$lines = [
			esc_html__( "SendToMP sends your visitor a confirmation email — their message only reaches the MP after they click the link inside it.", 'sendtomp' ),
			esc_html__( "Gravity Forms' default confirmation says \"Thanks, we'll be in touch shortly\", which is misleading here.", 'sendtomp' ),
			esc_html__( "Edit this form's Confirmation to tell visitors to check their email for the confirmation link.", 'sendtomp' ),
		];

		return '<p>' . implode( '</p><p>', $lines ) . '</p>';
	}

	/**
	 * Show a one-off admin notice on the form's Confirmations page when
	 * a SendToMP feed is active on that form, so site owners don't forget
	 * to update the post-submission message.
	 *
	 * TODO (v2): detect whether the active confirmation already mentions
	 * email / confirmation and suppress the notice if it does. Currently
	 * we show it unconditionally on the Confirmations tab.
	 *
	 * @return void
	 */
	public function maybe_render_confirmation_handoff_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$subview = isset( $_GET['subview'] ) ? sanitize_key( wp_unslash( $_GET['subview'] ) ) : '';
		$form_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'gf_edit_forms' !== $page || 'settings' !== $view || 'confirmation' !== $subview || ! $form_id ) {
			return;
		}

		$feeds         = $this->get_feeds( $form_id );
		$has_active_fe = false;
		foreach ( (array) $feeds as $feed ) {
			if ( ! empty( $feed['is_active'] ) ) {
				$has_active_fe = true;
				break;
			}
		}
		if ( ! $has_active_fe ) {
			return;
		}

		echo '<div class="notice notice-info"><h3 style="margin-top:0.75em;">'
			. esc_html__( 'SendToMP is active on this form', 'sendtomp' )
			. '</h3>'
			. wp_kses_post( $this->render_handoff_notice_html() )
			. '</div>';
	}

	/**
	 * Expose SendToMP-specific runtime tokens in the Gravity Forms
	 * merge-tag picker so campaign owners can insert them from the UI.
	 *
	 * These tokens are not resolved by Gravity Forms — they're passed
	 * through verbatim and substituted by SendToMP_Mailer::replace_placeholders()
	 * at send time, once the MP has been resolved from the postcode.
	 *
	 * @param array $merge_tags  Existing merge tags.
	 * @param int   $form_id     Form ID being edited.
	 * @param array $fields      Form fields.
	 * @param int   $element_id  Element invoking the picker.
	 * @return array
	 */
	public function add_sendtomp_merge_tags( $merge_tags, $form_id, $fields, $element_id ) {
		$sendtomp_tags = [
			[ 'tag' => '{mp_name}',          'label' => esc_html__( "MP Name (after postcode lookup)", 'sendtomp' ) ],
			[ 'tag' => '{mp_constituency}',  'label' => esc_html__( 'MP Constituency', 'sendtomp' ) ],
			[ 'tag' => '{mp_party}',         'label' => esc_html__( 'MP Party', 'sendtomp' ) ],
			[ 'tag' => '{mp_house}',         'label' => esc_html__( 'MP House (Commons / Lords)', 'sendtomp' ) ],
			[ 'tag' => '{constituent_name}', 'label' => esc_html__( 'Constituent Name (mapped)', 'sendtomp' ) ],
			[ 'tag' => '{constituent_postcode}', 'label' => esc_html__( 'Constituent Postcode (mapped)', 'sendtomp' ) ],
			[ 'tag' => '{site_name}',        'label' => esc_html__( 'Your site name', 'sendtomp' ) ],
		];

		return array_merge( is_array( $merge_tags ) ? $merge_tags : [], $sendtomp_tags );
	}

	/**
	 * Enqueue scripts for the GF feed settings page.
	 *
	 * @return array
	 */
	public function scripts() {
		return array_merge( parent::scripts(), [
			[
				'handle'   => 'sendtomp-peer-search',
				'src'      => SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-peer-search.js',
				'version'  => $this->_version,
				'deps'     => [ 'jquery' ],
				'enqueue'  => [
					[ 'admin_page' => [ 'form_settings' ] ],
				],
				'callback' => function() {
					SendToMP_Form_Adapter_Abstract::enqueue_peer_search();
				},
			],
			[
				'handle'  => 'sendtomp-gf-rich-editor',
				'src'     => SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-gf-rich-editor.js',
				'version' => $this->_version,
				'deps'    => [ 'jquery' ],
				'enqueue' => [
					[ 'admin_page' => [ 'form_settings' ] ],
				],
			],
		] );
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
		// TODO (v2): consider auto-creating a "SendToMP: check your email" GF
		// Confirmation when a feed is first activated, and flipping it to the
		// active confirmation. The current implementation is a lightweight
		// reminder — admin notice + feed-editor header block — that relies on
		// the site owner configuring their own confirmation. Upgrade if
		// user feedback shows the reminder is being ignored.
		return [
			// Section 0: Post-submission handoff reminder.
			[
				'title'  => esc_html__( 'After your visitor hits submit', 'sendtomp' ),
				'fields' => [
					[
						'name' => 'sendtomp_handoff_notice',
						'type' => 'html',
						'html' => $this->render_handoff_notice_html(),
					],
				],
			],
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
					[
						'label'    => esc_html__( 'Target Peer', 'sendtomp' ),
						'type'     => 'text',
						'name'     => 'target_member_name',
						'class'    => 'medium sendtomp-peer-search',
						'tooltip'  => esc_html__( 'Search for and select a Peer. Required when Target House is Lords.', 'sendtomp' ),
						'dependency' => [
							'live'   => true,
							'fields' => [
								[
									'field'  => 'target_house',
									'values' => [ 'lords' ],
								],
							],
						],
					],
					[
						'label' => '',
						'type'  => 'hidden',
						'name'  => 'target_member_id',
					],
				],
			],
			// Section 2: Required field mapping — constituent identification.
			[
				'title'       => esc_html__( 'Constituent Fields', 'sendtomp' ),
				'description' => esc_html__( 'Map the form fields that identify the constituent. Postcode is used to look up their MP via the Parliament API.', 'sendtomp' ),
				'fields'      => [
					[
						'label'      => esc_html__( 'Required', 'sendtomp' ),
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
						],
					],
					[
						'label'      => esc_html__( 'Optional', 'sendtomp' ),
						'type'       => 'field_map',
						'name'       => 'fieldMapOptional',
						'field_map'  => [
							[
								'name'     => 'constituent_address',
								'label'    => esc_html__( 'Full Address', 'sendtomp' ),
								'required' => false,
								'tooltip'  => esc_html__( 'You do not need to provide a full address — a postcode alone is enough to route the message to the constituent\'s MP. If your form does collect a full address and you want it included in the message to the MP, select that field here.', 'sendtomp' ),
							],
						],
					],
				],
			],
			// Section 3: Message content — templates with merge tag support.
			[
				'title'       => esc_html__( 'Message Content', 'sendtomp' ),
				'description' => esc_html__( 'Build the subject line and body sent to the MP. Click the icon beside each field to insert form field values as merge tags.', 'sendtomp' ),
				'fields'      => [
					[
						'label'       => esc_html__( 'Message Subject', 'sendtomp' ),
						'type'        => 'text',
						'name'        => 'message_subject_template',
						'class'       => 'merge-tag-support mt-position-right mt-hide_all_fields',
						'tooltip'     => esc_html__( 'The subject line of the email sent to the MP. Use merge tags (the icon on the right) to insert form field values so each message is unique.', 'sendtomp' ),
						'description' => esc_html__( 'Example: "A message from {Name} about judicial anonymity". Leave blank to use the default template set under SendToMP → Email.', 'sendtomp' ),
					],
					[
						'label'       => esc_html__( 'Message Body', 'sendtomp' ),
						'type'        => 'textarea',
						'name'        => 'message_body_template',
						'class'       => 'sendtomp-rich-editor medium',
						'required'    => true,
						'tooltip'     => esc_html__( 'The body of the email sent to the MP. Write in Markdown — it is converted to HTML when the email is delivered.', 'sendtomp' ),
						'description' => esc_html__( 'Use the toolbar to format text, and the merge-tag dropdown to insert form field values or MP tokens. Expand the "Formatting guide" below the editor for Markdown syntax help.', 'sendtomp' ),
					],
				],
			],
			// Section 4: Conditional logic.
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
		$subject_template = (string) rgar( $feed['meta'], 'message_subject_template', '' );
		$body_template    = (string) rgar( $feed['meta'], 'message_body_template', '' );

		$resolve_template = function ( $template ) use ( $form, $entry ) {
			if ( '' === $template ) {
				return '';
			}
			if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'replace_variables' ) ) {
				return GFCommon::replace_variables( $template, $form, $entry, false, false, false, 'text' );
			}
			return $template;
		};

		// Extract mapped field values (constituent identification) plus resolved templates (message content).
		$mapped_data = [
			'constituent_name'     => sanitize_text_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_constituent_name' ) ) ),
			'constituent_email'    => sanitize_email( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_constituent_email' ) ) ),
			'constituent_postcode' => sanitize_text_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMap_constituent_postcode' ) ) ),
			'constituent_address'  => sanitize_text_field( $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'fieldMapOptional_constituent_address' ) ) ),
			'message_subject'      => sanitize_text_field( $resolve_template( $subject_template ) ),
			'message_body'         => sanitize_textarea_field( $resolve_template( $body_template ) ),
		];

		// Build the submission with consistent metadata keys.
		$submission = new SendToMP_Submission( $mapped_data );
		$submission->source_adapter = $this->get_slug();
		$submission->source_form_id = (string) $form['id'];
		$submission->target_house   = rgar( $feed['meta'], 'target_house', 'commons' );

		if ( 'lords' === $submission->target_house ) {
			$submission->target_member_id = (int) rgar( $feed['meta'], 'target_member_id', 0 );
		}

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

			SendToMP_Logger::log( $submission, 'error', $result->get_error_message() );
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

	/**
	 * Build the list of GF form-field merge tag options for the picker,
	 * then emit it as a global JS variable for the rich editor script.
	 *
	 * Runs on admin_print_footer_scripts on the form settings page.
	 *
	 * @return void
	 */
	public function print_rich_editor_tag_data(): void {
		$form = $this->get_current_form();

		$form_options = [];
		if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $gf_field ) {
				if ( in_array( $gf_field->type, [ 'html', 'section', 'page', 'captcha' ], true ) ) {
					continue;
				}

				$field_label = (string) ( $gf_field->label ?? '' );
				if ( '' === $field_label ) {
					continue;
				}

				$inputs = method_exists( $gf_field, 'get_entry_inputs' ) ? $gf_field->get_entry_inputs() : null;

				if ( is_array( $inputs ) && ! empty( $inputs ) ) {
					foreach ( $inputs as $input ) {
						$input_label = (string) rgar( $input, 'label' );
						$input_id    = (string) rgar( $input, 'id' );
						if ( '' === $input_label || '' === $input_id ) {
							continue;
						}
						$form_options[] = [
							'label' => $field_label . ' (' . $input_label . ')',
							'tag'   => '{' . $field_label . ' (' . $input_label . '):' . $input_id . '}',
						];
					}
				} else {
					$form_options[] = [
						'label' => $field_label,
						'tag'   => '{' . $field_label . ':' . $gf_field->id . '}',
					];
				}
			}
		}

		$sendtomp_options = [
			[ 'label' => __( 'MP Name (after postcode lookup)', 'sendtomp' ), 'tag' => '{mp_name}' ],
			[ 'label' => __( 'MP Constituency', 'sendtomp' ),                 'tag' => '{mp_constituency}' ],
			[ 'label' => __( 'MP Party', 'sendtomp' ),                        'tag' => '{mp_party}' ],
			[ 'label' => __( 'MP House (Commons / Lords)', 'sendtomp' ),      'tag' => '{mp_house}' ],
			[ 'label' => __( 'Constituent Name (mapped)', 'sendtomp' ),       'tag' => '{constituent_name}' ],
			[ 'label' => __( 'Constituent Postcode (mapped)', 'sendtomp' ),   'tag' => '{constituent_postcode}' ],
			[ 'label' => __( 'Your site name', 'sendtomp' ),                  'tag' => '{site_name}' ],
		];

		?>
		<script type="text/javascript">
			window.sendtompRichEditor = {
				formFields:     <?php echo wp_json_encode( $form_options ); ?>,
				sendtompTokens: <?php echo wp_json_encode( $sendtomp_options ); ?>,
				i18n: {
					insertLabel: <?php echo wp_json_encode( __( 'Insert merge tag:', 'sendtomp' ) ); ?>,
					choose:      <?php echo wp_json_encode( __( '— choose —', 'sendtomp' ) ); ?>,
					formFields:  <?php echo wp_json_encode( __( 'Form fields', 'sendtomp' ) ); ?>,
					sendtomp:    <?php echo wp_json_encode( __( 'SendToMP tokens (resolved at send time)', 'sendtomp' ) ); ?>
				}
			};
		</script>
		<?php
	}
}
