<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = [
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
	'didit_wc_position',
	'didit_wc_send_billing',
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

foreach ( $options as $option ) {
	delete_option( $option );
}
