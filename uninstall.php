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
];

foreach ( $didit_verify_options as $didit_verify_option ) {
	delete_option( $didit_verify_option );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'didit_wc_verification_reminder' );
}
