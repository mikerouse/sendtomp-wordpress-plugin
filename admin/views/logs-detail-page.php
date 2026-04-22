<?php
/**
 * Submission log — single entry detail view.
 *
 * Routed from render_log_page() when `?view=<id>` is present.
 * The $view_id variable is available from the includer.
 *
 * @package SendToMP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped variables.

$list_url = admin_url( 'admin.php?page=sendtomp-log' );
$entry    = SendToMP_Logger::get_log_by_id( (int) $view_id );
$sendtomp_header_hide_tagline = true;
?>
<div class="wrap">
<?php include SENDTOMP_PLUGIN_DIR . 'admin/views/partials/header.php'; ?>

<h2 class="sendtomp-page-subtitle">
	<a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none;">&larr; <?php esc_html_e( 'Submission Log', 'sendtomp' ); ?></a>
</h2>

<?php if ( ! $entry ) : ?>
	<div class="notice notice-error inline"><p><?php esc_html_e( 'Log entry not found. It may have been deleted.', 'sendtomp' ); ?></p></div>
<?php else :
	$status       = (string) $entry->delivery_status;
	$status_class = 'sendtomp-status-pill sendtomp-status-pill--' . sanitize_html_class( $status );
	$can_resend   = 'pending_confirmation' === $status;
	$gf_entry_url = '';
	if ( 'gravity-forms' === (string) $entry->source_adapter && '' !== (string) $entry->source_form_id && class_exists( 'GFAPI' ) ) {
		// There's no direct entry id stored here; link to the form's entries list.
		$gf_entry_url = admin_url( 'admin.php?page=gf_entries&id=' . urlencode( $entry->source_form_id ) );
	}

	$date_display = mysql2date(
		get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
		(string) $entry->created_at,
		true
	);

	$rows = [
		[ 'label' => __( 'Log ID', 'sendtomp' ),                'value' => '#' . (int) $entry->id ],
		[ 'label' => __( 'Submitted', 'sendtomp' ),             'value' => $date_display ],
		[ 'label' => __( 'Status', 'sendtomp' ),                'value' => '<span class="' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ) . '</span>', 'raw' => true ],
		[ 'label' => __( 'Constituent name', 'sendtomp' ),      'value' => (string) $entry->constituent_name ],
		[ 'label' => __( 'Constituent email', 'sendtomp' ),     'value' => '<a href="mailto:' . esc_attr( $entry->constituent_email ) . '">' . esc_html( $entry->constituent_email ) . '</a>', 'raw' => true ],
		[ 'label' => __( 'Constituent postcode', 'sendtomp' ),  'value' => (string) $entry->constituent_postcode ],
		[ 'label' => __( 'Message subject', 'sendtomp' ),       'value' => (string) $entry->message_subject ],
		[ 'label' => __( 'MP / Peer', 'sendtomp' ),             'value' => (string) ( $entry->target_member_name ?: '—' ) ],
		[ 'label' => __( 'MP / Peer ID', 'sendtomp' ),          'value' => (int) $entry->target_member_id ? (string) $entry->target_member_id : '—' ],
		[ 'label' => __( 'House', 'sendtomp' ),                 'value' => ucfirst( (string) $entry->house ) ],
		[ 'label' => __( 'Contact quality', 'sendtomp' ),       'value' => null === $entry->contact_quality ? '—' : (string) $entry->contact_quality ],
		[ 'label' => __( 'Override applied', 'sendtomp' ),      'value' => null === $entry->override_applied ? '—' : (string) $entry->override_applied ],
		[ 'label' => __( 'Source adapter', 'sendtomp' ),        'value' => (string) $entry->source_adapter ],
		[ 'label' => __( 'Source form ID', 'sendtomp' ),        'value' => $gf_entry_url
			? '<a href="' . esc_url( $gf_entry_url ) . '">' . esc_html( $entry->source_form_id ) . '</a>'
			: esc_html( (string) $entry->source_form_id ), 'raw' => true ],
	];
	?>

	<div class="sendtomp-log-detail">
		<div class="sendtomp-log-detail-main">
			<table class="form-table sendtomp-log-detail-table" role="presentation">
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
						<td>
							<?php
							if ( ! empty( $row['raw'] ) ) {
								// Pre-escaped HTML fragments (status pill, mailto, link).
								echo $row['value']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								echo '' === (string) $row['value'] ? '—' : esc_html( (string) $row['value'] );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php if ( ! empty( $entry->error_message ) ) :
				$is_error_status = in_array( $status, [ 'error', 'failed' ], true );
				$note_heading    = $is_error_status
					? __( 'Error detail', 'sendtomp' )
					: __( 'Notes', 'sendtomp' );
				$note_class      = $is_error_status ? 'sendtomp-log-error' : 'sendtomp-log-note';
				?>
				<h3><?php echo esc_html( $note_heading ); ?></h3>
				<pre class="<?php echo esc_attr( $note_class ); ?>"><?php echo esc_html( (string) $entry->error_message ); ?></pre>
			<?php endif; ?>
		</div>

		<aside class="sendtomp-log-detail-aside">
			<div class="sendtomp-sidebar">
				<h3><?php esc_html_e( 'Actions', 'sendtomp' ); ?></h3>

				<?php if ( $can_resend ) : ?>
					<p>
						<button type="button" class="button button-primary sendtomp-log-resend" data-log-id="<?php echo (int) $entry->id; ?>">
							<?php esc_html_e( 'Resend confirmation email', 'sendtomp' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Re-sends the confirmation link email using the existing pending record. Use when the constituent says they didn\'t receive it.', 'sendtomp' ); ?>
					</p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Resend is only available when the status is "Pending confirmation".', 'sendtomp' ); ?>
					</p>
				<?php endif; ?>

				<hr />

				<p>
					<button type="button" class="button button-secondary sendtomp-log-delete" data-log-id="<?php echo (int) $entry->id; ?>">
						<?php esc_html_e( 'Delete this log entry', 'sendtomp' ); ?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Removes the row from the submission log only. Does not cancel or recall a sent message.', 'sendtomp' ); ?>
				</p>

				<p id="sendtomp-log-action-result" aria-live="polite" style="margin-top:12px;"></p>
			</div>
		</aside>
	</div>
<?php endif; ?>

</div><!-- .wrap -->
