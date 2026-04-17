<?php if (!defined('ABSPATH')) exit; ?>

<?php
$filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$filter_from   = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
$filter_to     = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
$filter_search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$paged         = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
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
?>

<h2>SendToMP &mdash; Submission Log</h2>

<div class="sendtomp-log-filters">
	<div>
		<label for="sendtomp-filter-status">Status</label>
		<select id="sendtomp-filter-status" name="status" form="sendtomp-log-filter-form">
			<option value="">All Statuses</option>
			<option value="sent" <?php selected($filter_status, 'sent'); ?>>Sent</option>
			<option value="confirmed" <?php selected($filter_status, 'confirmed'); ?>>Confirmed</option>
			<option value="failed" <?php selected($filter_status, 'failed'); ?>>Failed</option>
			<option value="pending" <?php selected($filter_status, 'pending'); ?>>Pending</option>
		</select>
	</div>

	<div>
		<label for="sendtomp-filter-date-from">From</label>
		<input type="date" id="sendtomp-filter-date-from" name="date_from"
		       value="<?php echo esc_attr($filter_from); ?>" form="sendtomp-log-filter-form" />
	</div>

	<div>
		<label for="sendtomp-filter-date-to">To</label>
		<input type="date" id="sendtomp-filter-date-to" name="date_to"
		       value="<?php echo esc_attr($filter_to); ?>" form="sendtomp-log-filter-form" />
	</div>

	<div>
		<label for="sendtomp-filter-search">Search</label>
		<input type="text" id="sendtomp-filter-search" name="search"
		       value="<?php echo esc_attr($filter_search); ?>"
		       placeholder="Name, postcode, MP..."
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

<?php if (empty($items)) : ?>
	<p>No submissions found.</p>
<?php else : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" class="column-date">Date</th>
				<th scope="col" class="column-constituent">Constituent</th>
				<th scope="col" class="column-mp">MP / Peer</th>
				<th scope="col" class="column-house">House</th>
				<th scope="col" class="column-status">Status</th>
				<th scope="col" class="column-adapter">Adapter</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($items as $item) : ?>
				<tr>
					<td class="column-date">
						<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at))); ?>
					</td>
					<td class="column-constituent">
						<?php echo esc_html($item->constituent_name); ?>
						<?php if (!empty($item->constituent_postcode)) : ?>
							<br><small><?php echo esc_html($item->constituent_postcode); ?></small>
						<?php endif; ?>
					</td>
					<td class="column-mp"><?php echo esc_html($item->target_member_name); ?></td>
					<td class="column-house"><?php echo esc_html($item->house); ?></td>
					<td class="column-status">
						<span class="sendtomp-status-<?php echo esc_attr($item->delivery_status); ?>">
							<?php echo esc_html(ucfirst($item->delivery_status)); ?>
						</span>
					</td>
					<td class="column-adapter"><?php echo esc_html($item->source_adapter); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ($total_pages > 1) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php echo esc_html(sprintf(_n('%s item', '%s items', $total_items, 'sendtomp'), number_format_i18n($total_items))); ?>
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
							&lsaquo; Previous
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled">&lsaquo; Previous</span>
					<?php endif; ?>

					<span class="paging-input">
						<?php echo esc_html($paged); ?> of <?php echo esc_html($total_pages); ?>
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
							Next &rsaquo;
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled">Next &rsaquo;</span>
					<?php endif; ?>
				</span>
			</div>
		</div>
	<?php endif; ?>
<?php endif; ?>
