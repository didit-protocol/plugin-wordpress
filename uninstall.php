<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$didit_verify_options = [
	'didit_mode',
	'didit_unilink_url',
	'didit_workflow_id',
	'didit_api_key',
	'didit_vendor_data_mode',
	'didit_vendor_data_prefix',
	'didit_callback_url',
	'didit_callback_method',
	'didit_language',
	'didit_require_login',
	'didit_display_mode',
	'didit_show_close_btn',
	'didit_exit_confirmation',
	'didit_close_on_complete',
	'didit_logging',
	'didit_wc_required',
	'didit_wc_mode',
	'didit_wc_scope',
	'didit_wc_position',
	'didit_wc_title',
	'didit_wc_checkout_text',
	'didit_wc_post_purchase_text',
	'didit_wc_send_billing',
	'didit_wc_hold',
	'didit_wc_reminders',
	'didit_wc_reminder_interval',
	'didit_wc_reminder_max',
	'didit_webhook_secret',
	'didit_btn_text',
	'didit_btn_success_text',
	'didit_btn_bg_color',
	'didit_btn_text_color',
	'didit_btn_border_radius',
	'didit_btn_padding_v',
	'didit_btn_padding_h',
	'didit_btn_font_size',
	'didit_vendor_data',
	'didit_console_url',
	'didit_placements',
	'didit_registration_enabled',
	'didit_gate_enabled',
	'didit_my_account_enabled',
	'didit_business_enabled',
	'didit_gate_categories',
	'didit_aml_enabled',
	'didit_aml_threshold',
	'didit_aml_monitoring',
	'didit_aml_hold',
	'didit_contact_phone',
	'didit_contact_email',
	'didit_signin_enabled',
	'didit_signin_publishable_key',
	'didit_signin_application_id',
];

foreach ( $didit_verify_options as $didit_verify_option ) {
	delete_option( $didit_verify_option );
}

delete_transient( 'didit_workflows_cache' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'didit_wc_verification_reminder' );
	as_unschedule_all_actions( 'didit_aml_screen_order' );
}
// WP-Cron fallbacks are scheduled with the order ID as argument; clear them hook-wide.
foreach ( [ 'didit_aml_screen_order', 'didit_wc_verification_reminder' ] as $didit_cron_hook ) {
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( $didit_cron_hook );
	} else {
		wp_clear_scheduled_hook( $didit_cron_hook );
	}
}

// Remove the session bindings and resume pointers created by this plugin, including object-cache entries.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time cleanup on uninstall.
global $wpdb;
foreach ( [ 'didit_bound_', 'didit_resume_', 'didit_approved_', 'didit_registration_', 'didit_contact_lock', '_transient_didit_contact_', '_transient_timeout_didit_contact_' ] as $didit_prefix ) {
	$didit_binding_keys = $wpdb->get_col( $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $didit_prefix ) . '%'
	) );
	foreach ( $didit_binding_keys as $didit_binding_key ) {
		delete_option( $didit_binding_key );
	}
}
$didit_user_meta_keys = [
	'_didit_confirmed_session_id',
	'_didit_pending_session_id',
	'_didit_verified',
	'_didit_session_id',
	'_didit_status',
	'_didit_verified_at',
	'_didit_report',
	'_didit_approved_workflows',
	'_didit_kyb_pending_session_id',
	'_didit_kyb_session_id',
	'_didit_kyb_status',
	'_didit_kyb_verified_at',
	'_didit_kyb_report',
	'_didit_auth_sub',
	'_didit_workflow_sessions',
];
// Sign-in links are stored per site and issuer under keys starting with _didit_auth_link_.
$didit_user_meta_keys = array_merge( $didit_user_meta_keys, (array) $wpdb->get_col( $wpdb->prepare(
	"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
	$wpdb->esc_like( '_didit_auth_link_' ) . '%'
) ) );
foreach ( $didit_user_meta_keys as $didit_meta_key ) {
	delete_metadata( 'user', 0, $didit_meta_key, '', true );
}

// Order notes the plugin marked as carrying a Didit result. Merchant notes are not marked and stay.
$didit_note_ids = $wpdb->get_col( $wpdb->prepare(
	"SELECT c.comment_ID FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID WHERE c.comment_type = %s AND m.meta_key = %s",
	'order_note',
	'_didit_result_note'
) );
foreach ( $didit_note_ids as $didit_note_id ) {
	wp_delete_comment( (int) $didit_note_id, true );
}

// Verification results stored on orders and products, with and without WooCommerce order tables.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_didit_' ) . '%' ) );
$didit_orders_meta = $wpdb->prefix . 'wc_orders_meta';
if ( $didit_orders_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $didit_orders_meta ) ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$didit_orders_meta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_didit_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from the prefix and checked to exist.
}
// phpcs:enable
