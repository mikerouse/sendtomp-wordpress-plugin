<?php if (!defined('ABSPATH')) exit; ?>

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped variables used only in this included view.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter parameter, no state change.
$filter_status = isset($_GET['status']) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter parameter, no state change.
$filter_from   = isset($_GET['date_from']) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter parameter, no state change.
$filter_to     = isset($_GET['date_to']) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter parameter, no state change.
$filter_search = isset($_GET['search']) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter parameter, no state change.
$paged         = isset($_GET['paged']) ? max(1, absint( $_GET['paged'] )) : 1;
$per_page      = 20;

$logs = SendToMP_Logger::get_logs(array(
	'status'    => $filter_status,
	'date_from' => $filter_from,
	'date_to'   => $filter_to,
	'search'    => $filter_search,
	'page'      => $paged,
	'per_page'  => $per_page,
));

$items       = isset($logs['items']) ? $logs['items'] : array();
$total_items = isset($logs['total']) ? intval($logs['total']) : 0;
$total_pages = ceil($total_items / $per_page);

$base_url = admin_url('admin.php?page=sendtomp&tab=log');

$sendtomp_header_hide_tagline = true;
?>

<div class="wrap">
<?php include SENDTOMP_PLUGIN_DIR . 'admin/views/partials/header.php'; ?>

<h2 class="sendtomp-page-subtitle"><?php esc_html_e( 'Submission Log', 'sendtomp' ); ?></h2>

<div class="sendtomp-log-filters">
	<div>
		<label for="sendtomp-filter-status"><?php esc_html_e( 'Status', 'sendtomp' ); ?></label>
		<select id="sendtomp-filter-status" name="status" form="sendtomp-log-filter-form">
			<option value=""><?php esc_html_e( 'All Statuses', 'sendtomp' ); ?></option>
			<option value="confirmed" <?php selected($filter_status, 'confirmed'); ?>><?php esc_html_e( 'Confirmed & Sent', 'sendtomp' ); ?></option>
			<option value="failed" <?php selected($filter_status, 'failed'); ?>><?php esc_html_e( 'Failed', 'sendtomp' ); ?></option>
			<option value="pending_confirmation" <?php selected($filter_status, 'pending_confirmation'); ?>><?php esc_html_e( 'Pending Confirmation', 'sendtomp' ); ?></option>
			<option value="rate_limited" <?php selected($filter_status, 'rate_limited'); ?>><?php esc_html_e( 'Rate Limited', 'sendtomp' ); ?></option>
		</select>
	</div>

	<div>
		<label for="sendtomp-filter-date-from"><?php esc_html_e( 'From', 'sendtomp' ); ?></label>
		<input type="date" id="sendtomp-filter-date-from" name="date_from"
		       value="<?php echo esc_attr($filter_from); ?>" form="sendtomp-log-filter-form" />
	</div>

	<div>
		<label for="sendtomp-filter-date-to"><?php esc_html_e( 'To', 'sendtomp' ); ?></label>
		<input type="date" id="sendtomp-filter-date-to" name="date_to"
		       value="<?php echo esc_attr($filter_to); ?>" form="sendtomp-log-filter-form" />
	</div>

	<div>
		<label for="sendtomp-filter-search"><?php esc_html_e( 'Search', 'sendtomp' ); ?></label>
		<input type="text" id="sendtomp-filter-search" name="search"
		       value="<?php echo esc_attr($filter_search); ?>"
		       placeholder="<?php echo esc_attr__( 'Name, postcode, MP...', 'sendtomp' ); ?>"
		       form="sendtomp-log-filter-form" />
	</div>

	<div>
		<form id="sendtomp-log-filter-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
			<input type="hidden" name="page" value="sendtomp" />
			<input type="hidden" name="tab" value="log" />
			<?php submit_button('Filter', 'secondary', 'submit', false); ?>
		</form>
	</div>
</div>

<?php
$can_export = function_exists( 'sendtomp' ) && sendtomp()->can( 'csv_export' );
$export_url = add_query_arg( [
	'action' => 'sendtomp_export_logs_csv',
	'nonce'  => wp_create_nonce( 'sendtomp_admin' ),
], admin_url( 'admin-ajax.php' ) );
?>

<div class="sendtomp-log-actions-bar">
	<?php if ( $can_export ) : ?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
			<?php esc_html_e( 'Export CSV', 'sendtomp' ); ?>
		</a>
	<?php else : ?>
		<span class="sendtomp-export-locked">
			<?php esc_html_e( 'CSV export is a Pro feature.', 'sendtomp' ); ?>
			<a href="https://bluetorch.co.uk/sendtomp#pricing" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade', 'sendtomp' ); ?> &rarr;</a>
		</span>
	<?php endif; ?>
</div>

<?php if (empty($items)) : ?>
	<p><?php esc_html_e( 'No submissions found.', 'sendtomp' ); ?></p>
<?php else : ?>
	<table class="wp-list-table widefat fixed striped sendtomp-log-table">
		<thead>
			<tr>
				<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'sendtomp' ); ?></th>
				<th scope="col" class="column-constituent"><?php esc_html_e( 'Constituent', 'sendtomp' ); ?></th>
				<th scope="col" class="column-mp"><?php esc_html_e( 'MP / Peer', 'sendtomp' ); ?></th>
				<th scope="col" class="column-house"><?php esc_html_e( 'House', 'sendtomp' ); ?></th>
				<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'sendtomp' ); ?></th>
				<th scope="col" class="column-adapter"><?php esc_html_e( 'Source', 'sendtomp' ); ?></th>
				<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'sendtomp' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($items as $item) :
				$view_url        = admin_url( 'admin.php?page=sendtomp-log&view=' . (int) $item->id );
				$status          = (string) $item->delivery_status;
				$pill_class      = 'sendtomp-status-pill sendtomp-status-pill--' . sanitize_html_class( $status );
				$has_note        = ! empty( $item->error_message );
				$is_error_status = in_array( $status, [ 'error', 'failed' ], true );
				$note_short      = $has_note ? wp_trim_words( (string) $item->error_message, 10, ' …' ) : '';
			?>
				<tr<?php echo ( $has_note && $is_error_status ) ? ' class="sendtomp-log-row--has-error"' : ''; ?>>
					<td class="column-date">
						<a href="<?php echo esc_url( $view_url ); ?>">
							<?php echo esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime( $item->created_at ) ) ); ?>
						</a>
					</td>
					<td class="column-constituent">
						<?php echo esc_html( $item->constituent_name ); ?>
						<?php if ( ! empty( $item->constituent_postcode ) ) : ?>
							<br><small><?php echo esc_html( $item->constituent_postcode ); ?></small>
						<?php endif; ?>
					</td>
					<td class="column-mp"><?php echo '' !== (string) $item->target_member_name ? esc_html( $item->target_member_name ) : '—'; ?></td>
					<td class="column-house"><?php echo esc_html( ucfirst( (string) $item->house ) ); ?></td>
					<td class="column-status">
						<span class="<?php echo esc_attr( $pill_class ); ?>">
							<?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?>
						</span>
						<?php if ( $has_note ) : ?>
							<div class="sendtomp-log-note-preview <?php echo $is_error_status ? 'sendtomp-log-note-preview--error' : 'sendtomp-log-note-preview--info'; ?>" title="<?php echo esc_attr( $item->error_message ); ?>">
								<?php echo esc_html( $note_short ); ?>
							</div>
						<?php endif; ?>
					</td>
					<td class="column-adapter"><?php echo esc_html( $item->source_adapter ); ?></td>
					<td class="column-actions">
						<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'sendtomp' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ($total_pages > 1) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					/* translators: %s: formatted number of log items */
					echo esc_html(sprintf(_n('%s item', '%s items', $total_items, 'sendtomp'), number_format_i18n($total_items)));
					?>
				</span>
				<span class="pagination-links">
					<?php if ($paged > 1) : ?>
						<a class="prev-page button"
						   href="<?php echo esc_url(add_query_arg(array(
							   'page'      => 'sendtomp',
							   'tab'       => 'log',
							   'status'    => $filter_status,
							   'date_from' => $filter_from,
							   'date_to'   => $filter_to,
							   'search'    => $filter_search,
							   'paged'     => $paged - 1,
						   ), admin_url('admin.php'))); ?>">
							&lsaquo; <?php esc_html_e( 'Previous', 'sendtomp' ); ?>
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled">&lsaquo; <?php esc_html_e( 'Previous', 'sendtomp' ); ?></span>
					<?php endif; ?>

					<span class="paging-input">
						<?php
						/* translators: 1: current page number, 2: total number of pages */
						echo esc_html( sprintf( __( '%1$s of %2$s', 'sendtomp' ), $paged, $total_pages ) );
						?>
					</span>

					<?php if ($paged < $total_pages) : ?>
						<a class="next-page button"
						   href="<?php echo esc_url(add_query_arg(array(
							   'page'      => 'sendtomp',
							   'tab'       => 'log',
							   'status'    => $filter_status,
							   'date_from' => $filter_from,
							   'date_to'   => $filter_to,
							   'search'    => $filter_search,
							   'paged'     => $paged + 1,
						   ), admin_url('admin.php'))); ?>">
							<?php esc_html_e( 'Next', 'sendtomp' ); ?> &rsaquo;
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled"><?php esc_html_e( 'Next', 'sendtomp' ); ?> &rsaquo;</span>
					<?php endif; ?>
				</span>
			</div>
		</div>
	<?php endif; ?>
<?php endif; ?>

</div><!-- .wrap -->
