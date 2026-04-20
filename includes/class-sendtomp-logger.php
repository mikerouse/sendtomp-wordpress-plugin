<?php
/**
 * SendToMP_Logger — submission logging to a custom WordPress DB table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Logger {

	/**
	 * Create the custom log table using dbDelta().
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			constituent_name varchar(255) NOT NULL DEFAULT '',
			constituent_email varchar(255) NOT NULL DEFAULT '',
			constituent_postcode varchar(10) NOT NULL DEFAULT '',
			message_subject varchar(255) NOT NULL DEFAULT '',
			target_member_name varchar(255) NOT NULL DEFAULT '',
			target_member_id int(11) NOT NULL DEFAULT 0,
			house varchar(10) NOT NULL DEFAULT 'commons',
			override_applied varchar(10) DEFAULT NULL,
			contact_quality varchar(20) DEFAULT NULL,
			delivery_status varchar(20) NOT NULL DEFAULT 'pending_confirmation',
			error_message text DEFAULT NULL,
			source_adapter varchar(50) NOT NULL DEFAULT '',
			source_form_id varchar(50) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY delivery_status (delivery_status),
			KEY constituent_email (constituent_email),
			KEY house (house),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a log entry from a submission.
	 *
	 * @param SendToMP_Submission $submission The submission object.
	 * @param string              $status     Delivery status.
	 * @param string              $error      Optional error message.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function log( SendToMP_Submission $submission, string $status, string $error = '' ) {
		global $wpdb;

		$member = $submission->resolved_member;

		// Check resolved_member first (set by pipeline/overrides), fall back to metadata.
		$override_applied = null;
		if ( ! empty( $member['override_applied'] ) ) {
			$override_applied = $member['override_applied'];
		} elseif ( isset( $submission->metadata['override_applied'] ) ) {
			$override_applied = $submission->metadata['override_applied'];
		}

		$data = [
			'constituent_name'    => $submission->constituent_name,
			'constituent_email'   => $submission->constituent_email,
			'constituent_postcode' => $submission->constituent_postcode,
			'message_subject'     => $submission->message_subject,
			'target_member_name'  => isset( $member['name'] ) ? $member['name'] : '',
			'target_member_id'    => isset( $member['id'] ) ? (int) $member['id'] : $submission->target_member_id,
			'house'               => $submission->target_house,
			'override_applied'    => $override_applied,
			'contact_quality'     => isset( $member['contact_quality'] ) ? $member['contact_quality'] : null,
			'delivery_status'     => $status,
			'error_message'       => $error,
			'source_adapter'      => $submission->source_adapter,
			'source_form_id'      => $submission->source_form_id,
			'created_at'          => gmdate( 'Y-m-d H:i:s' ),
		];

		$formats = [
			'%s', // constituent_name
			'%s', // constituent_email
			'%s', // constituent_postcode
			'%s', // message_subject
			'%s', // target_member_name
			'%d', // target_member_id
			'%s', // house
			'%s', // override_applied
			'%s', // contact_quality
			'%s', // delivery_status
			'%s', // error_message
			'%s', // source_adapter
			'%s', // source_form_id
			'%s', // created_at
		];

		$result = $wpdb->insert( self::get_table_name(), $data, $formats );

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Query logs with optional filters and pagination.
	 *
	 * @param array $args Query arguments.
	 * @return array Array with 'items' and 'total' keys.
	 */
	public static function get_logs( array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'status'    => '',
			'house'     => '',
			'adapter'   => '',
			'date_from' => '',
			'date_to'   => '',
			'search'    => '',
			'per_page'  => 20,
			'page'      => 1,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		];

		$args  = wp_parse_args( $args, $defaults );
		$table = self::get_table_name();

		$where  = [];
		$values = [];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'delivery_status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['house'] ) ) {
			$where[]  = 'house = %s';
			$values[] = $args['house'];
		}

		if ( ! empty( $args['adapter'] ) ) {
			$where[]  = 'source_adapter = %s';
			$values[] = $args['adapter'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $args['date_to'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(constituent_name LIKE %s OR constituent_postcode LIKE %s OR target_member_name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		// Sanitise orderby to prevent SQL injection.
		$allowed_orderby = [
			'id', 'constituent_name', 'constituent_email', 'constituent_postcode',
			'message_subject', 'target_member_name', 'house', 'delivery_status',
			'source_adapter', 'created_at',
		];
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = absint( $args['per_page'] );
		$page     = absint( $args['page'] );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $page < 1 ) {
			$page = 1;
		}
		$offset = ( $page - 1 ) * $per_page;

		// Get total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql built from hardcoded clauses above.
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
		} else {
			// No user input in query — safe to execute directly.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
		}

		// Get items.
		$query = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table, $where_sql, $orderby, $order built from internal hardcoded values; direct query required for plugin tables.
		$items = $wpdb->get_results( $wpdb->prepare( $query, $query_values ) );

		return [
			'items' => $items ? $items : [],
			'total' => $total,
		];
	}

	/**
	 * Return aggregate statistics.
	 *
	 * @return array Associative array of stats.
	 */
	public static function get_stats(): array {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$total_sent      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE delivery_status = %s", 'sent' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$total_confirmed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE delivery_status = %s", 'confirmed' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$total_failed    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE delivery_status = %s", 'failed' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$total_pending   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE delivery_status = %s", 'pending_confirmation' ) );

		$denominator = $total_confirmed + $total_pending + $total_sent;
		$confirmation_rate = $denominator > 0
			? round( ( $total_confirmed / $denominator ) * 100, 2 )
			: 0;

		return [
			'total_sent'        => $total_sent,
			'total_confirmed'   => $total_confirmed,
			'total_failed'      => $total_failed,
			'total_pending'     => $total_pending,
			'confirmation_rate' => $confirmation_rate,
		];
	}

	/**
	 * Delete all logs matching an email address (GDPR erasure).
	 *
	 * @param string $email The email address to purge.
	 * @return int Number of rows deleted.
	 */
	public static function purge_by_email( string $email ): int {
		global $wpdb;

		$deleted = $wpdb->delete(
			self::get_table_name(),
			[ 'constituent_email' => $email ],
			[ '%s' ]
		);

		return $deleted ? (int) $deleted : 0;
	}

	/**
	 * Delete logs older than the specified number of days.
	 *
	 * @param int $days Number of days to retain. Default 90.
	 * @return int Number of rows deleted.
	 */
	public static function purge_old( int $days = 90 ): int {
		global $wpdb;

		$table    = self::get_table_name();
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$deleted  = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);

		return $deleted ? (int) $deleted : 0;
	}

	/**
	 * Get the full table name including prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'sendtomp_log';
	}
}
