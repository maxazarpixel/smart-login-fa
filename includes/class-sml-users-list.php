<?php
/**
 * Adds Smart Login columns and status filters to the native
 * wp-admin/users.php list table, so accounts are managed there rather than
 * in a bespoke screen.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Users_List {

	public static function init() {
		add_filter( 'manage_users_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_users_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
		add_filter( 'views_users', array( __CLASS__, 'status_views' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'apply_query' ) );
	}

	/**
	 * @param string[] $cols
	 * @return string[]
	 */
	public static function columns( $cols ) {
		$cols['sml_verified']   = __( 'Verified', 'smart-login' );
		$cols['sml_phone']      = __( 'Phone', 'smart-login' );
		$cols['sml_last_login'] = __( 'Last login', 'smart-login' );
		$cols['sml_registered'] = __( 'Registered', 'smart-login' );
		return $cols;
	}

	/**
	 * @param array<string,string> $cols
	 * @return array<string,string>
	 */
	public static function sortable_columns( $cols ) {
		$cols['sml_last_login'] = 'sml_last_login';
		$cols['sml_registered'] = 'registered';
		return $cols;
	}

	/**
	 * @param string $out     Existing column HTML.
	 * @param string $col     Column key.
	 * @param int    $user_id
	 * @return string
	 */
	public static function column_value( $out, $col, $user_id ) {
		switch ( $col ) {
			case 'sml_verified':
				if ( SML_Verification::is_verified( $user_id ) ) {
					return '<span style="color:#12805c;">&#9679; ' . esc_html__( 'Verified', 'smart-login' ) . '</span>';
				}
				if ( SML_Verification::has_verification_record( $user_id ) ) {
					return '<span style="color:#92590a;">&#9679; ' . esc_html__( 'Pending', 'smart-login' ) . '</span>';
				}
				return '<span style="color:#9a9aa2;">&mdash; ' . esc_html__( 'Legacy', 'smart-login' ) . '</span>';

			case 'sml_phone':
				$phone = (string) get_user_meta( $user_id, 'sml_phone', true );
				if ( '' === $phone ) {
					$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
				}
				return '' !== $phone ? esc_html( $phone ) : '&mdash;';

			case 'sml_last_login':
				$ts = (int) get_user_meta( $user_id, 'sml_last_login', true );
				if ( ! $ts ) {
					return '&mdash;';
				}
				/* translators: %s: human-readable time difference, e.g. "3 hours" */
				return esc_html( sprintf( __( '%s ago', 'smart-login' ), human_time_diff( $ts, time() ) ) );

			case 'sml_registered':
				$user = get_userdata( $user_id );
				return $user
					? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $user->user_registered . ' UTC' ) ) )
					: '';
		}

		return $out;
	}

	/**
	 * Adds "Verified / Pending / Legacy" filter links above the user list.
	 *
	 * @param string[] $views
	 * @return string[]
	 */
	public static function status_views( $views ) {
		global $wpdb;

		$counts = array(
			'verified' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'sml_email_verified' AND meta_value = '1'" ), // phpcs:ignore WordPress.DB
			'pending'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'sml_email_verified' AND meta_value = '0'" ), // phpcs:ignore WordPress.DB
		);

		$current = isset( $_GET['sml_status'] ) ? sanitize_key( wp_unslash( $_GET['sml_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$labels  = array(
			'verified' => __( 'Verified', 'smart-login' ),
			'pending'  => __( 'Pending verification', 'smart-login' ),
			'legacy'   => __( 'Legacy', 'smart-login' ),
		);

		foreach ( $labels as $key => $label ) {
			$url  = esc_url( add_query_arg( 'sml_status', $key, admin_url( 'users.php' ) ) );
			$note = isset( $counts[ $key ] ) ? ' <span class="count">(' . number_format_i18n( $counts[ $key ] ) . ')</span>' : '';

			$views[ 'sml_' . $key ] = sprintf(
				'<a href="%s"%s>%s%s</a>',
				$url,
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$note // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		return $views;
	}

	/**
	 * Sort by last-login meta, and filter by the ?sml_status link.
	 *
	 * @param WP_User_Query $query
	 */
	public static function apply_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'users.php' !== $pagenow ) {
			return;
		}

		if ( 'sml_last_login' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', 'sml_last_login' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', 'meta_value_num' );
		}

		$status = isset( $_GET['sml_status'] ) ? sanitize_key( wp_unslash( $_GET['sml_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $status ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );

		if ( 'verified' === $status ) {
			$meta_query[] = array( 'key' => 'sml_email_verified', 'value' => '1' );
		} elseif ( 'pending' === $status ) {
			$meta_query[] = array( 'key' => 'sml_email_verified', 'value' => '0' );
		} elseif ( 'legacy' === $status ) {
			$meta_query[] = array( 'key' => 'sml_email_verified', 'compare' => 'NOT EXISTS' );
		}

		$query->set( 'meta_query', $meta_query );
	}
}
