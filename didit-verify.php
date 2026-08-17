<?php
/**
 * Plugin Name: Didit Verify
 * Plugin URI:  https://github.com/didit-protocol/plugin-wordpress
 * Description: Identity verification for WordPress & WooCommerce using the Didit SDK.
 * Version:     0.3.1
 * Author:      Didit
 * Author URI:  https://didit.me
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: didit-verify
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
  exit;
}

define('DIDIT_VERIFY_VERSION', '0.3.1');
define('DIDIT_VERIFY_URL', plugin_dir_url(__FILE__));
define('DIDIT_API_URL', 'https://verification.didit.me/v3/session/');

final class Didit_Verify
{

  private $block_session_id = '';

  public static function init()
  {
    static $instance = null;
    if (null === $instance) {
      $instance = new self();
    }
    return $instance;
  }

  private function __construct()
  {
    add_action('init', [$this, 'load_textdomain']);

    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'admin_register_settings']);
    add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);

    add_filter('manage_users_columns', [$this, 'users_column']);
    add_filter('manage_users_custom_column', [$this, 'users_column_content'], 10, 3);

    add_action('rest_api_init', [$this, 'register_routes']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    add_shortcode('didit_verify', [$this, 'render_shortcode']);
    add_shortcode('didit_status', [$this, 'render_status_shortcode']);
    add_shortcode('didit_gate', [$this, 'render_gate_shortcode']);

    add_action('woocommerce_loaded', [$this, 'wc_hooks']);
  }

  public function load_textdomain()
  {
    load_plugin_textdomain('didit-verify', false, dirname(plugin_basename(__FILE__)) . '/languages');
  }

  public function admin_menu()
  {
    add_options_page(
      __('Didit Verify', 'didit-verify'),
      __('Didit Verify', 'didit-verify'),
      'manage_options',
      'didit-verify',
      [$this, 'admin_render_page']
    );
  }

  public function admin_register_settings()
  {
    $fields = [
      'didit_mode' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'unilink'],
      'didit_unilink_url' => ['sanitize_callback' => 'esc_url_raw', 'default' => ''],
      'didit_workflow_id' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_api_key' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_vendor_data_mode' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'user_id'],
      'didit_vendor_data_prefix' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_callback_url' => ['sanitize_callback' => 'esc_url_raw', 'default' => ''],
      'didit_callback_method' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_language' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'auto'],
      'didit_require_login' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => true],
      'didit_display_mode' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'modal'],
      'didit_show_close_btn' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => true],
      'didit_exit_confirmation' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => true],
      'didit_close_on_complete' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => false],
      'didit_logging' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => false],
      'didit_wc_required' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => false],
      'didit_wc_mode' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_wc_scope' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'all'],
      'didit_wc_position' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'before_submit'],
      'didit_wc_send_billing' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => true],
      'didit_wc_hold' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => false],
      'didit_wc_reminders' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => false],
      'didit_wc_reminder_interval' => ['sanitize_callback' => 'absint', 'default' => 3],
      'didit_wc_reminder_max' => ['sanitize_callback' => 'absint', 'default' => 3],
      'didit_wc_title' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_wc_checkout_text' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_wc_post_purchase_text' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_webhook_secret' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_btn_text' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_btn_success_text' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
      'didit_btn_bg_color' => ['sanitize_callback' => 'sanitize_hex_color', 'default' => '#2667ff'],
      'didit_btn_text_color' => ['sanitize_callback' => 'sanitize_hex_color', 'default' => '#ffffff'],
      'didit_btn_border_radius' => ['sanitize_callback' => 'absint', 'default' => 8],
      'didit_btn_padding_v' => ['sanitize_callback' => 'absint', 'default' => 12],
      'didit_btn_padding_h' => ['sanitize_callback' => 'absint', 'default' => 24],
      'didit_btn_font_size' => ['sanitize_callback' => 'absint', 'default' => 16],
    ];

    foreach ($fields as $name => $args) {
      register_setting('didit_verify', $name, $args);
    }

    add_settings_section('didit_connection', __('Connection', 'didit-verify'), '__return_false', 'didit-verify');
    add_settings_field('didit_mode', __('Mode', 'didit-verify'), [$this, 'field_mode'], 'didit-verify', 'didit_connection');
    add_settings_field('didit_unilink_url', __('UniLink URL', 'didit-verify'), [$this, 'field_unilink'], 'didit-verify', 'didit_connection');
    add_settings_field('didit_workflow_id', __('Workflow ID', 'didit-verify'), [$this, 'field_workflow_id'], 'didit-verify', 'didit_connection');
    add_settings_field('didit_api_key', __('API Key', 'didit-verify'), [$this, 'field_api_key'], 'didit-verify', 'didit_connection');

    add_settings_section('didit_session', __('Session Options (API mode)', 'didit-verify'), '__return_false', 'didit-verify');
    add_settings_field('didit_vendor_data_mode', __('Vendor Data', 'didit-verify'), [$this, 'field_vendor_data'], 'didit-verify', 'didit_session');
    add_settings_field('didit_callback_url', __('Callback URL', 'didit-verify'), [$this, 'field_callback_url'], 'didit-verify', 'didit_session');
    add_settings_field('didit_callback_method', __('Callback Method', 'didit-verify'), [$this, 'field_callback_method'], 'didit-verify', 'didit_session');
    add_settings_field('didit_language', __('Language', 'didit-verify'), [$this, 'field_language'], 'didit-verify', 'didit_session');

    add_settings_section('didit_sdk', __('Display Options', 'didit-verify'), '__return_false', 'didit-verify');
    add_settings_field('didit_display_mode', __('Display Mode', 'didit-verify'), [$this, 'field_display_mode'], 'didit-verify', 'didit_sdk');
    add_settings_field('didit_show_close_btn', __('Close Button', 'didit-verify'), [$this, 'field_show_close_btn'], 'didit-verify', 'didit_sdk');
    add_settings_field('didit_exit_confirmation', __('Exit Confirmation', 'didit-verify'), [$this, 'field_exit_confirmation'], 'didit-verify', 'didit_sdk');
    add_settings_field('didit_close_on_complete', __('Auto-close', 'didit-verify'), [$this, 'field_close_on_complete'], 'didit-verify', 'didit_sdk');
    add_settings_field('didit_logging', __('Debug Logging', 'didit-verify'), [$this, 'field_logging'], 'didit-verify', 'didit_sdk');

    add_settings_section('didit_button', __('Button Appearance', 'didit-verify'), [$this, 'section_button_preview'], 'didit-verify');
    add_settings_field('didit_btn_text', __('Button Text', 'didit-verify'), [$this, 'field_btn_text'], 'didit-verify', 'didit_button');
    add_settings_field('didit_btn_success_text', __('Success Text', 'didit-verify'), [$this, 'field_btn_success_text'], 'didit-verify', 'didit_button');
    add_settings_field('didit_btn_bg_color', __('Background Color', 'didit-verify'), [$this, 'field_btn_bg_color'], 'didit-verify', 'didit_button');
    add_settings_field('didit_btn_text_color', __('Text Color', 'didit-verify'), [$this, 'field_btn_text_color'], 'didit-verify', 'didit_button');
    add_settings_field('didit_btn_border_radius', __('Border Radius', 'didit-verify'), [$this, 'field_btn_border_radius'], 'didit-verify', 'didit_button');
    add_settings_field('didit_btn_padding', __('Padding', 'didit-verify'), [$this, 'field_btn_padding'], 'didit-verify', 'didit_button');
    add_settings_field('didit_btn_font_size', __('Font Size', 'didit-verify'), [$this, 'field_btn_font_size'], 'didit-verify', 'didit_button');

    add_settings_section('didit_security', __('Security', 'didit-verify'), '__return_false', 'didit-verify');
    add_settings_field('didit_require_login', __('Require Login', 'didit-verify'), [$this, 'field_require_login'], 'didit-verify', 'didit_security');
    add_settings_field('didit_webhook_secret', __('Webhook Secret', 'didit-verify'), [$this, 'field_webhook_secret'], 'didit-verify', 'didit_security');

    add_settings_section('didit_woocommerce', __('WooCommerce', 'didit-verify'), '__return_false', 'didit-verify');
    add_settings_field('didit_wc_mode', __('Verification Mode', 'didit-verify'), [$this, 'field_wc_mode'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_scope', __('Product Scope', 'didit-verify'), [$this, 'field_wc_scope'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_position', __('Position', 'didit-verify'), [$this, 'field_wc_position'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_title', __('Section Title', 'didit-verify'), [$this, 'field_wc_title'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_checkout_text', __('Checkout Message', 'didit-verify'), [$this, 'field_wc_checkout_text'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_post_purchase_text', __('Post-purchase Message', 'didit-verify'), [$this, 'field_wc_post_purchase_text'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_send_billing', __('Send Billing Data', 'didit-verify'), [$this, 'field_wc_send_billing'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_hold', __('Hold Orders', 'didit-verify'), [$this, 'field_wc_hold'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_reminders', __('Reminders', 'didit-verify'), [$this, 'field_wc_reminders'], 'didit-verify', 'didit_woocommerce');
  }

  public function admin_enqueue_scripts($hook)
  {
    if ('settings_page_didit-verify' !== $hook) {
      return;
    }
    wp_enqueue_script(
      'didit-admin',
      DIDIT_VERIFY_URL . 'assets/js/didit-admin.js',
      [],
      DIDIT_VERIFY_VERSION,
      true
    );
  }

  public function field_mode()
  {
    $v = get_option('didit_mode', 'unilink');
    printf(
      '<select name="didit_mode">
				<option value="unilink" %s>%s</option>
				<option value="api" %s>%s</option>
			</select>
			<p class="description">%s</p>',
      selected($v, 'unilink', false),
      esc_html__('UniLink — no backend needed', 'didit-verify'),
      selected($v, 'api', false),
      esc_html__('API Session — recommended for production', 'didit-verify'),
      esc_html__('UniLink uses a fixed URL. API mode creates a unique session per user (requires Workflow ID + API Key).', 'didit-verify')
    );
  }

  public function field_unilink()
  {
    printf(
      '<input type="url" name="didit_unilink_url" value="%s" class="regular-text" placeholder="https://verify.didit.me/u/..." />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_unilink_url', '')),
      esc_html__('Get this from Didit Console → Your Workflow → Copy Link.', 'didit-verify')
    );
  }

  public function field_workflow_id()
  {
    printf(
      '<input type="text" name="didit_workflow_id" value="%s" class="regular-text" />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_workflow_id', '')),
      esc_html__('Required for API mode. Found in Didit Console → Workflows.', 'didit-verify')
    );
  }

  public function field_api_key()
  {
    printf(
      '<input type="password" name="didit_api_key" value="%s" class="regular-text" autocomplete="off" />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_api_key', '')),
      esc_html__('Found in Didit Console → API & Webhooks. Stored server-side only — never sent to the browser.', 'didit-verify')
    );
  }

  public function field_vendor_data()
  {
    $mode = get_option('didit_vendor_data_mode', 'user_id');
    $prefix = get_option('didit_vendor_data_prefix', '');
    printf(
      '<select name="didit_vendor_data_mode" id="didit_vendor_data_mode">
				<option value="user_id" %s>%s</option>
				<option value="user_email" %s>%s</option>
				<option value="custom" %s>%s</option>
				<option value="none" %s>%s</option>
			</select>
			<p class="description">%s</p>
			<div id="didit-vendor-prefix-row" style="margin-top:0.5rem;%s">
				<input type="text" name="didit_vendor_data_prefix" value="%s" class="regular-text" placeholder="mystore-" />
				<p class="description">%s</p>
			</div>',
      selected($mode, 'user_id', false),
      esc_html__('WordPress User ID (e.g. wp-42)', 'didit-verify'),
      selected($mode, 'user_email', false),
      esc_html__('User Email (e.g. john@example.com)', 'didit-verify'),
      selected($mode, 'custom', false),
      esc_html__('Custom prefix + User ID', 'didit-verify'),
      selected($mode, 'none', false),
      esc_html__('None — do not send vendor data', 'didit-verify'),
      esc_html__('Identifies each user in the Didit dashboard. Allows session tracking and aggregation across multiple verifications.', 'didit-verify'),
      'custom' === $mode ? '' : 'display:none;',
      esc_attr($prefix),
      esc_html__('Prefix prepended to the User ID (e.g. "mystore-" → "mystore-42"). Leave empty for just the ID.', 'didit-verify')
    );
  }

  public function field_callback_url()
  {
    printf(
      '<input type="url" name="didit_callback_url" value="%s" class="regular-text" placeholder="https://yoursite.com/verification-done" />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_callback_url', '')),
      esc_html__('URL to redirect the user after verification completes. Didit appends verificationSessionId and status as query parameters. Leave empty to disable.', 'didit-verify')
    );
  }

  public function field_callback_method()
  {
    $v = get_option('didit_callback_method', '');
    printf(
      '<select name="didit_callback_method">
				<option value="" %s>%s</option>
				<option value="initiator" %s>%s</option>
				<option value="completer" %s>%s</option>
				<option value="both" %s>%s</option>
			</select>
			<p class="description">%s</p>',
      selected($v, '', false),
      esc_html__('— Default (initiator) —', 'didit-verify'),
      selected($v, 'initiator', false),
      esc_html__('Initiator — redirect the device that started the flow', 'didit-verify'),
      selected($v, 'completer', false),
      esc_html__('Completer — redirect the device that finishes the flow', 'didit-verify'),
      selected($v, 'both', false),
      esc_html__('Both — either device can trigger the callback', 'didit-verify'),
      esc_html__('Determines which device handles the redirect to the callback URL after verification.', 'didit-verify')
    );
  }

  public function field_language()
  {
    $v = get_option('didit_language', 'auto');
    $languages = [
      'auto' => 'Auto-detect (browser language)',
      'ar'   => 'العربية',
      'bg'   => 'Български',
      'bn'   => 'বাংলা',
      'ca'   => 'Català',
      'cnr'  => 'Crnogorski',
      'cs'   => 'Čeština',
      'da'   => 'Dansk',
      'de'   => 'Deutsch',
      'el'   => 'Ελληνικά',
      'en'   => 'English',
      'es'   => 'Español',
      'et'   => 'Eesti',
      'fa'   => 'فارسی',
      'fi'   => 'Suomi',
      'fr'   => 'Français',
      'he'   => 'עברית',
      'hi'   => 'हिन्दी',
      'hr'   => 'Hrvatski',
      'hu'   => 'Magyar',
      'hy'   => 'Հայերեն',
      'id'   => 'Bahasa Indonesia',
      'it'   => 'Italiano',
      'ja'   => '日本語',
      'ka'   => 'ქართული',
      'ko'   => '한국어',
      'lt'   => 'Lietuvių',
      'lv'   => 'Latviešu',
      'mk'   => 'Македонски',
      'ms'   => 'Bahasa Melayu',
      'nl'   => 'Nederlands',
      'no'   => 'Norsk',
      'pl'   => 'Polski',
      'pt'   => 'Português',
      'pt-BR'=> 'Português (Brasil)',
      'ro'   => 'Română',
      'ru'   => 'Русский',
      'sk'   => 'Slovenčina',
      'sl'   => 'Slovenščina',
      'so'   => 'Soomaali',
      'sr'   => 'Српски',
      'sv'   => 'Svenska',
      'th'   => 'ไทย',
      'tr'   => 'Türkçe',
      'uk'   => 'Українська',
      'uz'   => 'Oʻzbekcha',
      'vi'   => 'Tiếng Việt',
      'zh'   => '中文',
      'zh-CN'=> '中文 (简体)',
      'zh-TW'=> '中文 (繁體)',
    ];

    echo '<select name="didit_language">';
    foreach ($languages as $code => $label) {
      printf('<option value="%s" %s>%s</option>', esc_attr($code), selected($v, $code, false), esc_html($label));
    }
    echo '</select>';
    printf('<p class="description">%s</p>', esc_html__('Language for the verification UI shown to the user. Auto-detect uses the browser language.', 'didit-verify'));
  }

  public function field_display_mode()
  {
    $v = get_option('didit_display_mode', 'modal');
    printf(
      '<select name="didit_display_mode">
        <option value="modal" %s>%s</option>
        <option value="embedded" %s>%s</option>
      </select>
      <p class="description">%s</p>',
      selected($v, 'modal', false),
      esc_html__('Modal (popup) — opens over the page', 'didit-verify'),
      selected($v, 'embedded', false),
      esc_html__('Embedded (inline) — appears where the shortcode is placed', 'didit-verify'),
      esc_html__('Modal opens a centered overlay. Embedded renders the verification directly on the page where you placed [didit_verify] or the WooCommerce checkout section.', 'didit-verify')
    );
  }

  public function field_show_close_btn()
  {
    printf(
      '<label><input type="checkbox" name="didit_show_close_btn" value="1" %s /> %s</label>
      <p class="description">%s</p>',
      checked(get_option('didit_show_close_btn', true), true, false),
      esc_html__('Show close (X) button on the verification modal', 'didit-verify'),
      esc_html__('Uncheck to force users to complete verification (no way to close the modal). Useful for mandatory verification flows.', 'didit-verify')
    );
  }

  public function field_exit_confirmation()
  {
    printf(
      '<label><input type="checkbox" name="didit_exit_confirmation" value="1" %s /> %s</label>
      <p class="description">%s</p>',
      checked(get_option('didit_exit_confirmation', true), true, false),
      esc_html__('Show "Are you sure?" dialog when closing the modal', 'didit-verify'),
      esc_html__('Prevents accidental exits. Uncheck for a frictionless experience.', 'didit-verify')
    );
  }

  public function field_close_on_complete()
  {
    printf(
      '<label><input type="checkbox" name="didit_close_on_complete" value="1" %s /> %s</label>
      <p class="description">%s</p>',
      checked(get_option('didit_close_on_complete', false), true, false),
      esc_html__('Automatically close the modal when verification completes', 'didit-verify'),
      esc_html__('If unchecked, the modal stays open showing the result. Useful for WooCommerce checkout to return the user to the order flow quickly.', 'didit-verify')
    );
  }

  public function field_logging()
  {
    printf(
      '<label><input type="checkbox" name="didit_logging" value="1" %s /> %s</label>
      <p class="description">%s</p>',
      checked(get_option('didit_logging', false), true, false),
      esc_html__('Enable SDK debug logging in the browser console', 'didit-verify'),
      esc_html__('Logs all postMessage events and state changes. Only enable for troubleshooting.', 'didit-verify')
    );
  }

  public function section_button_preview()
  {
    $bg = get_option('didit_btn_bg_color', '#2667ff');
    $color = get_option('didit_btn_text_color', '#ffffff');
    $rad = (int) get_option('didit_btn_border_radius', 8);
    $pv = (int) get_option('didit_btn_padding_v', 12);
    $ph = (int) get_option('didit_btn_padding_h', 24);
    $fs = (int) get_option('didit_btn_font_size', 16);
    $text = $this->btn_text();
    ?>
    <div style="margin-bottom:1.5em;">
      <p class="description" style="margin-bottom:0.75em;">
        <?php esc_html_e('Live preview — changes update when you modify the fields below.', 'didit-verify'); ?>
      </p>
      <div style="padding:2rem; background:#f6f7f7; border:1px solid #ddd; border-radius:8px; text-align:center;">
        <button type="button" id="didit-btn-preview" style="background:<?php echo esc_attr($bg); ?>;
                 color:<?php echo esc_attr($color); ?>;
                 border:none;
                 border-radius:<?php echo esc_attr($rad); ?>px;
                 padding:<?php echo esc_attr($pv); ?>px <?php echo esc_attr($ph); ?>px;
                 font-size:<?php echo esc_attr($fs); ?>px;
                 font-weight:600;
                 font-family:inherit;
                 cursor:pointer;
                 line-height:1.4;">
          <?php echo esc_html($text); ?>
        </button>
      </div>
    </div>
    <?php
  }

  public function field_btn_text()
  {
    printf(
      '<input type="text" name="didit_btn_text" value="%s" class="regular-text" placeholder="%s" />
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_text', '')),
      esc_attr__('Verify your Identity', 'didit-verify'),
      esc_html__('Label shown on the button before verification. Leave empty to use the default text, which follows the site language.', 'didit-verify')
    );
  }

  public function field_btn_success_text()
  {
    printf(
      '<input type="text" name="didit_btn_success_text" value="%s" class="regular-text" placeholder="%s" />
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_success_text', '')),
      esc_attr__('Identity Verified ✓', 'didit-verify'),
      esc_html__('Label shown after successful verification. Leave empty to use the default text, which follows the site language.', 'didit-verify')
    );
  }

  public function field_btn_bg_color()
  {
    printf(
      '<input type="color" name="didit_btn_bg_color" value="%s" />
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_bg_color', '#2667ff')),
      esc_html__('Button background color.', 'didit-verify')
    );
  }

  public function field_btn_text_color()
  {
    printf(
      '<input type="color" name="didit_btn_text_color" value="%s" />
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_text_color', '#ffffff')),
      esc_html__('Button text color.', 'didit-verify')
    );
  }

  public function field_btn_border_radius()
  {
    printf(
      '<input type="number" name="didit_btn_border_radius" value="%s" min="0" max="50" style="width:80px" /> px
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_border_radius', 8)),
      esc_html__('Corner rounding in pixels. 0 = square, 50 = pill shape.', 'didit-verify')
    );
  }

  public function field_btn_padding()
  {
    printf(
      '<input type="number" name="didit_btn_padding_v" value="%s" min="0" max="40" style="width:70px" /> px &nbsp;&times;&nbsp;
      <input type="number" name="didit_btn_padding_h" value="%s" min="0" max="60" style="width:70px" /> px
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_padding_v', 12)),
      esc_attr(get_option('didit_btn_padding_h', 24)),
      esc_html__('Vertical × Horizontal padding in pixels.', 'didit-verify')
    );
  }

  public function field_btn_font_size()
  {
    printf(
      '<input type="number" name="didit_btn_font_size" value="%s" min="10" max="32" style="width:80px" /> px
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_font_size', 16)),
      esc_html__('Button font size in pixels.', 'didit-verify')
    );
  }

  public function field_require_login()
  {
    printf(
      '<label><input type="checkbox" name="didit_require_login" value="1" %s /> %s</label>
			<p class="description">%s</p>',
      checked(get_option('didit_require_login', true), true, false),
      esc_html__('Only logged-in WordPress users can create verification sessions', 'didit-verify'),
      esc_html__('Strongly recommended. Prevents anonymous abuse. Uncheck only if you need guest checkout in WooCommerce.', 'didit-verify')
    );
  }

  public function field_wc_mode()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    $v = $this->wc_mode();
    printf(
      '<select name="didit_wc_mode">
        <option value="off" %s>%s</option>
        <option value="checkout" %s>%s</option>
        <option value="after_purchase" %s>%s</option>
      </select>
      <p class="description">%s</p>',
      selected($v, 'off', false),
      esc_html__('Off — no verification in WooCommerce', 'didit-verify'),
      selected($v, 'checkout', false),
      esc_html__('Require at checkout — order cannot be placed until verified', 'didit-verify'),
      selected($v, 'after_purchase', false),
      esc_html__('After purchase — low-barrier checkout, verify on the order confirmation page', 'didit-verify'),
      esc_html__('After-purchase mode shows the verification box on the order confirmation page, My Account order view, and order emails.', 'didit-verify')
    );
  }

  public function field_wc_scope()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    $v = $this->wc_scope();
    printf(
      '<select name="didit_wc_scope">
        <option value="all" %s>%s</option>
        <option value="include" %s>%s</option>
        <option value="exclude" %s>%s</option>
      </select>
      <p class="description">%s</p>',
      selected($v, 'all', false),
      esc_html__('All products — verification applies to every order', 'didit-verify'),
      selected($v, 'include', false),
      esc_html__('Only selected products — require verification only when the cart contains a selected product', 'didit-verify'),
      selected($v, 'exclude', false),
      esc_html__('All products except selected — skip verification only when every product in the cart is selected', 'didit-verify'),
      esc_html__('Select products on the product edit page: Product data → Advanced → "Didit verification". Mixed carts require verification whenever at least one product in the cart requires it.', 'didit-verify')
    );
  }

  public function field_wc_hold()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    printf(
      '<label><input type="checkbox" name="didit_wc_hold" value="1" %s /> %s</label>
			<p class="description">%s</p>',
      checked(get_option('didit_wc_hold', false), true, false),
      esc_html__('Hold orders (status "On hold") until identity verification is approved', 'didit-verify'),
      esc_html__('After-purchase mode only. Orders are released to "Processing" automatically when the Didit webhook reports approval — configure the Webhook Secret below for this to work.', 'didit-verify')
    );
  }

  public function field_wc_reminders()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    printf(
      '<label><input type="checkbox" name="didit_wc_reminders" value="1" %s /> %s</label>
			<p style="margin-top:0.5rem;">%s <input type="number" name="didit_wc_reminder_interval" value="%s" min="1" max="60" style="width:70px;" /> %s
			<input type="number" name="didit_wc_reminder_max" value="%s" min="1" max="20" style="width:70px;" /> %s</p>
			<p class="description">%s</p>',
      checked(get_option('didit_wc_reminders', false), true, false),
      esc_html__('Email customers who have not completed verification', 'didit-verify'),
      esc_html__('Every', 'didit-verify'),
      esc_attr(max(1, (int) get_option('didit_wc_reminder_interval', 3))),
      esc_html__('day(s), at most', 'didit-verify'),
      esc_attr(max(1, (int) get_option('didit_wc_reminder_max', 3))),
      esc_html__('reminder(s) per order.', 'didit-verify'),
      esc_html__('After-purchase mode only. Reminders stop as soon as the verification is approved or declined, or the order is cancelled.', 'didit-verify')
    );
  }

  public function field_webhook_secret()
  {
    printf(
      '<input type="password" name="didit_webhook_secret" value="%s" class="regular-text" autocomplete="off" />
			<p class="description">%s<br /><code>%s</code></p>',
      esc_attr(get_option('didit_webhook_secret', '')),
      esc_html__('Webhook secret from the Didit Business Console (API & Webhooks). Point the webhook destination to:', 'didit-verify'),
      esc_url(rest_url('didit/v1/webhook'))
    );
  }

  public function field_wc_position()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    $v = get_option('didit_wc_position', 'before_submit');
    printf(
      '<select name="didit_wc_position">
        <option value="before_checkout" %s>%s</option>
        <option value="after_billing" %s>%s</option>
        <option value="after_order_notes" %s>%s</option>
        <option value="before_submit" %s>%s</option>
      </select>
      <p class="description">%s</p>',
      selected($v, 'before_checkout', false),
      esc_html__('Top of checkout page — verify before filling the form', 'didit-verify'),
      selected($v, 'after_billing', false),
      esc_html__('After billing details — verify after entering name & address', 'didit-verify'),
      selected($v, 'after_order_notes', false),
      esc_html__('After order notes — verify after all customer fields', 'didit-verify'),
      selected($v, 'before_submit', false),
      esc_html__('Before "Place Order" (recommended) — last step before payment', 'didit-verify'),
      esc_html__('Where the verification section appears on the checkout page.', 'didit-verify')
    );
  }

  private function text_option(string $option, string $default): string
  {
    $value = trim((string) get_option($option, ''));
    return '' !== $value ? $value : $default;
  }

  private function btn_text(): string
  {
    return $this->text_option('didit_btn_text', __('Verify your Identity', 'didit-verify'));
  }

  private function btn_success_text(): string
  {
    return $this->text_option('didit_btn_success_text', __('Identity Verified ✓', 'didit-verify'));
  }

  private function wc_box_title(): string
  {
    return $this->text_option('didit_wc_title', __('Identity Verification', 'didit-verify'));
  }

  private function wc_checkout_text(): string
  {
    return $this->text_option('didit_wc_checkout_text', __('Please verify your identity before placing your order.', 'didit-verify'));
  }

  private function wc_post_purchase_text(): string
  {
    return $this->text_option('didit_wc_post_purchase_text', __('Please verify your identity to complete your order.', 'didit-verify'));
  }

  public function field_wc_title()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    printf(
      '<input type="text" name="didit_wc_title" value="%s" class="regular-text" placeholder="%s" />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_wc_title', '')),
      esc_attr__('Identity Verification', 'didit-verify'),
      esc_html__('Heading of the verification section at checkout and after purchase. Leave empty to use the default text, which follows the site language.', 'didit-verify')
    );
  }

  public function field_wc_checkout_text()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    printf(
      '<input type="text" name="didit_wc_checkout_text" value="%s" class="large-text" placeholder="%s" />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_wc_checkout_text', '')),
      esc_attr__('Please verify your identity before placing your order.', 'didit-verify'),
      esc_html__('Message shown in the verification section on the checkout page. Leave empty to use the default text, which follows the site language.', 'didit-verify')
    );
  }

  public function field_wc_post_purchase_text()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    printf(
      '<input type="text" name="didit_wc_post_purchase_text" value="%s" class="large-text" placeholder="%s" />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_wc_post_purchase_text', '')),
      esc_attr__('Please verify your identity to complete your order.', 'didit-verify'),
      esc_html__('Message shown in the verification section after purchase (order confirmation page, My Account, emails). Leave empty to use the default text, which follows the site language.', 'didit-verify')
    );
  }

  public function field_wc_send_billing()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    printf(
      '<label><input type="checkbox" name="didit_wc_send_billing" value="1" %s /> %s</label>
			<p class="description">%s</p>',
      checked(get_option('didit_wc_send_billing', true), true, false),
      esc_html__('Auto-send checkout billing data as expected_details & contact_details', 'didit-verify'),
      esc_html__('Sends name, email, phone, address, and country from the checkout form to Didit for pre-filling and cross-checking.', 'didit-verify')
    );
  }

  public function admin_render_page()
  {
    if (!current_user_can('manage_options')) {
      return;
    }
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Didit Identity Verification', 'didit-verify'); ?></h1>
      <form method="post" action="options.php">
        <?php
        settings_fields('didit_verify');
        do_settings_sections('didit-verify');
        submit_button();
        ?>
      </form>
      <hr />
      <h3><?php esc_html_e('Shortcodes', 'didit-verify'); ?></h3>
      <code>[didit_verify]</code>
      <p class="description">
        <?php esc_html_e('Verification button styled with the settings above. Place on any page or post.', 'didit-verify'); ?>
      </p>
      <br />
      <code>[didit_verify text="Verify Now" success_text="Done!"]</code>
      <p class="description"><?php esc_html_e('Override the button text for a specific page.', 'didit-verify'); ?></p>

      <h3><?php esc_html_e('Status & Content Gating', 'didit-verify'); ?></h3>
      <code>[didit_status]</code>
      <p class="description">
        <?php esc_html_e('Shows "Identity Verified" or "Not Verified" for the logged-in user.', 'didit-verify'); ?></p>
      <br />
      <code>[didit_gate]Protected content here...[/didit_gate]</code>
      <p class="description">
        <?php esc_html_e('Content inside is only visible to verified users. Others see a verification prompt.', 'didit-verify'); ?>
      </p>
    </div>
    <?php
  }

  public function register_routes()
  {
    register_rest_route('didit/v1', '/session', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_create_session'],
      'permission_callback' => [$this, 'rest_check_permission'],
    ]);

    register_rest_route('didit/v1', '/verify', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_save_verification'],
      'permission_callback' => function ($request) {
        return is_user_logged_in() || (bool) $this->validate_order_context($request->get_json_params());
      },
    ]);

    register_rest_route('didit/v1', '/webhook', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_webhook'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function rest_check_permission($request)
  {
    $nonce = $request->get_header('x_wp_nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
      return new WP_Error('rest_forbidden', __('Invalid security token.', 'didit-verify'), ['status' => 403]);
    }

    if ($this->validate_order_context($request->get_json_params())) {
      return true;
    }

    if (get_option('didit_require_login', true) && !is_user_logged_in()) {
      return new WP_Error('rest_forbidden', __('You must be logged in to start verification.', 'didit-verify'), ['status' => 401]);
    }

    return true;
  }

  private function validate_order_context($input)
  {
    if (!is_array($input) || !class_exists('WooCommerce') || 'after_purchase' !== $this->wc_mode()) {
      return null;
    }
    $order_id = absint($input['order_id'] ?? 0);
    $order_key = sanitize_text_field($input['order_key'] ?? '');
    if (!$order_id || !$order_key) {
      return null;
    }
    $order = wc_get_order($order_id);
    if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
      return null;
    }
    return $order;
  }

  public function rest_create_session($request)
  {
    $rate_error = $this->check_rate_limit();
    if (is_wp_error($rate_error)) {
      return $rate_error;
    }

    $workflow_id = get_option('didit_workflow_id');
    $api_key = get_option('didit_api_key');

    if (empty($workflow_id) || empty($api_key)) {
      return new WP_Error('not_configured', __('Didit API credentials are not configured.', 'didit-verify'), ['status' => 500]);
    }

    $body = ['workflow_id' => $workflow_id];

    $vendor_data = $this->resolve_vendor_data();
    $callback_url = get_option('didit_callback_url', '');
    $callback_method = get_option('didit_callback_method', '');

    if ($vendor_data) {
      $body['vendor_data'] = $vendor_data;
    }
    if ($callback_url) {
      $body['callback'] = $callback_url;
    }
    if ($callback_method) {
      $body['callback_method'] = $callback_method;
    }

    $language = get_option('didit_language', 'auto');
    if ($language && 'auto' !== $language) {
      $body['language'] = $language;
    }

    $input = $request->get_json_params();

    $order = $this->validate_order_context($input);
    if ($order) {
      $existing_url = $order->get_meta('_didit_session_url');
      $existing_status = $order->get_meta('_didit_status');
      if ($existing_url && !in_array($existing_status, ['Approved', 'Declined', 'Expired', 'KYC Expired'], true)) {
        return rest_ensure_response(['url' => $existing_url]);
      }
    }

    if (!empty($input['contact_details']) && is_array($input['contact_details'])) {
      $body['contact_details'] = $this->sanitize_contact_details($input['contact_details']);
    }

    if (!empty($input['expected_details']) && is_array($input['expected_details'])) {
      $body['expected_details'] = $this->sanitize_expected_details($input['expected_details']);
    }

    if ($order && get_option('didit_wc_send_billing', true)) {
      $contact = array_filter([
        'email' => sanitize_email($order->get_billing_email()),
        'phone' => sanitize_text_field($order->get_billing_phone()),
      ]);
      $address = implode(', ', array_filter([
        $order->get_billing_address_1(),
        $order->get_billing_address_2(),
        $order->get_billing_city(),
        $order->get_billing_state(),
        $order->get_billing_postcode(),
      ]));
      $expected = array_filter([
        'first_name' => sanitize_text_field($order->get_billing_first_name()),
        'last_name' => sanitize_text_field($order->get_billing_last_name()),
        'country' => sanitize_text_field($order->get_billing_country()),
        'address' => sanitize_text_field($address),
      ]);
      if ($contact) {
        $body['contact_details'] = $this->sanitize_contact_details($contact);
      }
      if ($expected) {
        $body['expected_details'] = $this->sanitize_expected_details($expected);
      }
    }

    if ($order && !is_user_logged_in()) {
      $body['vendor_data'] = 'order-' . $order->get_id();
    }

    if (!empty($input['portrait_image']) && is_string($input['portrait_image'])) {
      if (preg_match('/^[A-Za-z0-9+\/=]+$/', $input['portrait_image']) && strlen($input['portrait_image']) <= 2 * 1024 * 1024) {
        $body['portrait_image'] = $input['portrait_image'];
      }
    }

    $meta = [];
    if (is_user_logged_in()) {
      $user = wp_get_current_user();
      $meta['wp_user_id'] = $user->ID;
      $meta['wp_email'] = $user->user_email;
    }
    if ($order) {
      $meta['wc_order_id'] = $order->get_id();
    }
    $meta['wp_ip'] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));

    if (!empty($input['metadata']) && is_string($input['metadata'])) {
      $extra = json_decode($input['metadata'], true);
      if (is_array($extra)) {
        $meta = array_merge($extra, $meta);
      }
    }
    $body['metadata'] = wp_json_encode($meta);

    $response = wp_remote_post(DIDIT_API_URL, [
      'headers' => [
        'Content-Type' => 'application/json',
        'x-api-key' => $api_key,
      ],
      'body' => wp_json_encode($body),
      'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('api_error', $response->get_error_message(), ['status' => 502]);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code < 200 || $status_code >= 300) {
      $raw = wp_remote_retrieve_body($response);
      if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log('Didit API error (' . $status_code . '): ' . $raw); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
      }
      $msg = $data['detail'] ?? $data['error'] ?? __('Didit API error', 'didit-verify');
      return new WP_Error('api_error', $msg, ['status' => $status_code]);
    }

    $url = $data['url'] ?? (isset($data['session_token'])
      ? 'https://verify.didit.me/session/' . $data['session_token']
      : null);

    if (!$url) {
      return new WP_Error('api_error', __('No verification URL returned.', 'didit-verify'), ['status' => 500]);
    }

    if ($order) {
      $order->update_meta_data('_didit_session_id', sanitize_text_field($data['session_id'] ?? ''));
      $order->update_meta_data('_didit_session_url', esc_url_raw($url));
      if (!$order->get_meta('_didit_status')) {
        $order->update_meta_data('_didit_status', 'Not Started');
      }
      $order->add_order_note(sprintf(
        /* translators: %s: Didit session id. */
        __('Didit: verification session created (%s).', 'didit-verify'),
        sanitize_text_field($data['session_id'] ?? '')
      ));
      $order->save();
    }

    do_action('didit_session_created', $url, get_current_user_id() ?: null, $vendor_data);

    return rest_ensure_response(['url' => $url]);
  }

  public function rest_save_verification($request)
  {
    $user_id = get_current_user_id();
    $input = $request->get_json_params();
    $order = $this->validate_order_context($input);

    if (!$user_id && !$order) {
      return new WP_Error('not_logged_in', 'User not logged in.', ['status' => 401]);
    }

    $type = sanitize_text_field($input['type'] ?? '');
    $session_id = sanitize_text_field($input['sessionId'] ?? '');
    $status = sanitize_text_field($input['status'] ?? '');

    if ('completed' === $type) {
      if ($user_id) {
        update_user_meta($user_id, '_didit_session_id', $session_id);
        update_user_meta($user_id, '_didit_status', $status);
        update_user_meta($user_id, '_didit_verified_at', current_time('mysql'));

        if ('Approved' === $status) {
          update_user_meta($user_id, '_didit_verified', 1);
        } else {
          delete_user_meta($user_id, '_didit_verified');
        }
      }

      if ($order && $session_id && hash_equals((string) $order->get_meta('_didit_session_id'), $session_id)) {
        $this->wc_apply_verification_to_order($order, $status, 'browser');
      }

      do_action('didit_verification_completed', $user_id, $session_id, $status);
    } elseif ('cancelled' === $type) {
      do_action('didit_verification_cancelled', $user_id, $session_id);
    }

    return rest_ensure_response(['saved' => true]);
  }

  /**
   * Rebuild the canonical JSON form Didit signs for X-Signature-V2.
   *
   * Didit produces the signed string with
   * `json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)`.
   * Three PHP defaults break that reproduction and each one changes the bytes, so
   * the digest never matches if any is left alone:
   *
   * - an associative `json_decode` collapses empty objects (`{}`) into empty arrays,
   *   which re-encode as `[]` - Didit payloads carry empty maps routinely, so the
   *   value is decoded into stdClass here instead;
   * - `ksort` compares numeric-looking keys numerically, sorting `"9"` before `"10"`,
   *   while Python sorts by code point - hence SORT_STRING;
   * - `json_encode` escapes slashes and non-ASCII, which `ensure_ascii=False` does not.
   *
   * @param mixed $value Decoded payload node (objects as stdClass).
   * @return mixed Node with every object's keys recursively sorted.
   */
  private static function canonicalize_signed_payload($value)
  {
    if ($value instanceof stdClass) {
      $props = get_object_vars($value);
      ksort($props, SORT_STRING);
      $sorted = new stdClass();
      foreach ($props as $key => $item) {
        $sorted->{$key} = self::canonicalize_signed_payload($item);
      }
      return $sorted;
    }

    if (is_array($value)) {
      // JSON arrays keep their order; only object keys are sorted.
      return array_map(
        function ($item) {
          return self::canonicalize_signed_payload($item);
        },
        $value
      );
    }

    return $value;
  }

  /**
   * X-Signature-V2 - HMAC over the canonical JSON re-encoding of the body.
   *
   * Survives middleware that re-encodes the request body, which is the failure the
   * raw-bytes variant cannot recover from.
   */
  private static function verify_signature_v2($raw, $signature, $secret)
  {
    $decoded = json_decode($raw, false);
    if (JSON_ERROR_NONE !== json_last_error()) {
      return false;
    }

    $canonical = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
      self::canonicalize_signed_payload($decoded),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (false === $canonical) {
      return false;
    }

    return hash_equals(hash_hmac('sha256', $canonical, $secret), $signature);
  }

  /**
   * X-Signature (legacy) - HMAC over the exact bytes Didit transmitted.
   *
   * Only valid while nothing between Didit and PHP rewrites the body.
   */
  private static function verify_signature_raw($raw, $signature, $secret)
  {
    return hash_equals(hash_hmac('sha256', $raw, $secret), $signature);
  }

  /**
   * X-Signature-Simple - HMAC over "{timestamp}:{session_id}:{status}:{webhook_type}".
   *
   * Encoding-independent, so it survives anything, but it authenticates the envelope
   * only: every other field of the payload is unsigned under this variant. Callers
   * must treat the rest of the body as untrusted - see rest_webhook().
   */
  private static function verify_signature_simple(array $payload, $signature, $secret)
  {
    $canonical = implode(':', [
      (string) ($payload['timestamp'] ?? ''),
      (string) ($payload['session_id'] ?? ''),
      (string) ($payload['status'] ?? ''),
      (string) ($payload['webhook_type'] ?? ''),
    ]);

    return hash_equals(hash_hmac('sha256', $canonical, $secret), $signature);
  }

  /**
   * Verify a delivery against every signature variant Didit sends, strongest first.
   *
   * Didit sends X-Signature, X-Signature-V2 and X-Signature-Simple on every delivery,
   * but reverse proxies, CDN header rules and WordPress security plugins drop
   * individual `X-*` headers, and body-rewriting middleware invalidates the raw-bytes
   * digest even when its header arrives. Trying each variant means one surviving
   * header is enough.
   *
   * @return string Variant that verified ('v2', 'raw', 'simple'), or '' if none did.
   */
  private static function verify_webhook_signature($request, $raw, array $payload, $secret)
  {
    $v2 = $request->get_header('x_signature_v2');
    if (is_string($v2) && '' !== $v2 && self::verify_signature_v2($raw, $v2, $secret)) {
      return 'v2';
    }

    $legacy = $request->get_header('x_signature');
    if (is_string($legacy) && '' !== $legacy && self::verify_signature_raw($raw, $legacy, $secret)) {
      return 'raw';
    }

    $simple = $request->get_header('x_signature_simple');
    if (is_string($simple) && '' !== $simple && self::verify_signature_simple($payload, $simple, $secret)) {
      return 'simple';
    }

    return '';
  }

  /**
   * Resolve the WordPress user a session belongs to from stored state.
   *
   * The session id is written to user meta when the verification is saved, so this
   * mapping is local and trustworthy - unlike the payload's `metadata.wp_user_id` and
   * `vendor_data`, which X-Signature-Simple does not authenticate.
   */
  private function user_id_for_session($session_id)
  {
    if (!$session_id) {
      return 0;
    }

    $users = get_users([
      'meta_key' => '_didit_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
      'meta_value' => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
      'number' => 1,
      'fields' => 'ID',
    ]);

    return $users ? absint($users[0]) : 0;
  }

  public function rest_webhook($request)
  {
    $secret = get_option('didit_webhook_secret', '');
    if (!$secret) {
      return new WP_Error('not_configured', __('Webhook secret is not configured.', 'didit-verify'), ['status' => 501]);
    }

    $raw = $request->get_body();
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
      return new WP_Error('invalid_payload', __('Invalid webhook payload.', 'didit-verify'), ['status' => 400]);
    }

    // Prefer the body's `timestamp` over the X-Timestamp header: Didit sets both to
    // the same value, but only the body copy is covered by all three signatures, so
    // checking it rejects a replayed delivery re-sent with a freshened header. It also
    // keeps the freshness check working when a proxy strips X-Timestamp along with the
    // signature headers.
    $timestamp = (int) ($payload['timestamp'] ?? 0);
    if (!$timestamp) {
      $timestamp = (int) $request->get_header('x_timestamp');
    }

    if (!$timestamp || abs(time() - $timestamp) > 5 * MINUTE_IN_SECONDS) {
      return new WP_Error('invalid_request', __('Missing or stale webhook signature.', 'didit-verify'), ['status' => 401]);
    }

    $verified_with = self::verify_webhook_signature($request, $raw, $payload, $secret);
    if (!$verified_with) {
      if (get_option('didit_logging', false)) {
        error_log(sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
          'Didit webhook signature mismatch. Headers received: %s',
          implode(', ', array_filter([
            $request->get_header('x_signature_v2') ? 'X-Signature-V2' : '',
            $request->get_header('x_signature') ? 'X-Signature' : '',
            $request->get_header('x_signature_simple') ? 'X-Signature-Simple' : '',
            $request->get_header('x_timestamp') ? 'X-Timestamp' : '',
          ])) ?: 'none'
        ));
      }
      return new WP_Error('invalid_signature', __('Webhook signature mismatch.', 'didit-verify'), ['status' => 401]);
    }

    // X-Signature-Simple signs the envelope only, so everything outside
    // timestamp/session_id/status/webhook_type stays unauthenticated on that path.
    $body_is_signed = ('simple' !== $verified_with);

    if ('status.updated' !== ($payload['webhook_type'] ?? '')) {
      return rest_ensure_response(['received' => true, 'ignored' => true]);
    }

    $session_id = sanitize_text_field($payload['session_id'] ?? '');
    $status = sanitize_text_field($payload['status'] ?? '');
    if (!$session_id || !$status) {
      return new WP_Error('invalid_payload', __('Invalid webhook payload.', 'didit-verify'), ['status' => 400]);
    }

    if (class_exists('WooCommerce')) {
      $orders = wc_get_orders([
        'limit' => -1,
        'meta_query' => [['key' => '_didit_session_id', 'value' => $session_id]],
      ]);
      foreach ($orders as $wc_order) {
        $this->wc_apply_verification_to_order($wc_order, $status, 'webhook');
      }
    }

    $wp_user_id = 0;
    if ($body_is_signed) {
      $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
      $wp_user_id = absint($meta['wp_user_id'] ?? 0);
      if (!$wp_user_id && preg_match('/^wp-(\d+)$/', (string) ($payload['vendor_data'] ?? ''), $m)) {
        $wp_user_id = absint($m[1]);
      }
    }
    if (!$wp_user_id) {
      // Falls back to the session -> user mapping this site stored itself, which is the
      // only usable source when the payload is unsigned beyond the envelope.
      $wp_user_id = $this->user_id_for_session($session_id);
    }
    if ($wp_user_id && get_userdata($wp_user_id)) {
      update_user_meta($wp_user_id, '_didit_session_id', $session_id);
      update_user_meta($wp_user_id, '_didit_status', $status);
      update_user_meta($wp_user_id, '_didit_verified_at', current_time('mysql'));
      if ('Approved' === $status) {
        update_user_meta($wp_user_id, '_didit_verified', 1);
      } else {
        delete_user_meta($wp_user_id, '_didit_verified');
      }
    }

    if (in_array($status, ['Approved', 'Declined'], true)) {
      do_action('didit_verification_completed', $wp_user_id, $session_id, $status);
    }

    return rest_ensure_response(['received' => true]);
  }

  private function check_rate_limit()
  {
    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    if (is_user_logged_in()) {
      $user_id = get_current_user_id();
      $key = 'didit_rl_u_' . $user_id;
      $limit = 10;
      $window = HOUR_IN_SECONDS;
    } else {
      $key = 'didit_rl_ip_' . md5($ip);
      $limit = 3;
      $window = HOUR_IN_SECONDS;
    }

    $count = (int) get_transient($key);
    if ($count >= $limit) {
      return new WP_Error(
        'rate_limit',
        __('Too many verification requests. Please try again later.', 'didit-verify'),
        ['status' => 429]
      );
    }
    set_transient($key, $count + 1, $window);

    return true;
  }

  private function sanitize_contact_details(array $raw): array
  {
    $clean = [];
    $allowed = ['email', 'phone', 'send_notification_emails', 'email_lang'];
    foreach ($allowed as $field) {
      if (!isset($raw[$field])) {
        continue;
      }
      if ($field === 'email') {
        $clean[$field] = sanitize_email($raw[$field]);
      } elseif ($field === 'send_notification_emails') {
        $clean[$field] = (bool) $raw[$field];
      } else {
        $clean[$field] = sanitize_text_field($raw[$field]);
      }
    }
    return $clean;
  }

  private function sanitize_expected_details(array $raw): array
  {
    $clean = [];
    $allowed = [
      'first_name',
      'last_name',
      'date_of_birth',
      'gender',
      'nationality',
      'country',
      'address',
      'identification_number',
      'ip_address',
    ];
    foreach ($allowed as $field) {
      if (!empty($raw[$field])) {
        $clean[$field] = sanitize_text_field($raw[$field]);
      }
    }

    foreach (['country', 'nationality'] as $key) {
      if (!empty($clean[$key]) && strlen($clean[$key]) === 2) {
        $alpha3 = $this->country_alpha2_to_alpha3($clean[$key]);
        if ($alpha3) {
          $clean[$key] = $alpha3;
        }
      }
    }

    return $clean;
  }

  private function country_alpha2_to_alpha3(string $alpha2): string
  {
    $map = [
      'AF' => 'AFG',
      'AL' => 'ALB',
      'DZ' => 'DZA',
      'AS' => 'ASM',
      'AD' => 'AND',
      'AO' => 'AGO',
      'AG' => 'ATG',
      'AR' => 'ARG',
      'AM' => 'ARM',
      'AU' => 'AUS',
      'AT' => 'AUT',
      'AZ' => 'AZE',
      'BS' => 'BHS',
      'BH' => 'BHR',
      'BD' => 'BGD',
      'BB' => 'BRB',
      'BY' => 'BLR',
      'BE' => 'BEL',
      'BZ' => 'BLZ',
      'BJ' => 'BEN',
      'BT' => 'BTN',
      'BO' => 'BOL',
      'BA' => 'BIH',
      'BW' => 'BWA',
      'BR' => 'BRA',
      'BN' => 'BRN',
      'BG' => 'BGR',
      'BF' => 'BFA',
      'BI' => 'BDI',
      'KH' => 'KHM',
      'CM' => 'CMR',
      'CA' => 'CAN',
      'CV' => 'CPV',
      'CF' => 'CAF',
      'TD' => 'TCD',
      'CL' => 'CHL',
      'CN' => 'CHN',
      'CO' => 'COL',
      'KM' => 'COM',
      'CG' => 'COG',
      'CD' => 'COD',
      'CR' => 'CRI',
      'CI' => 'CIV',
      'HR' => 'HRV',
      'CU' => 'CUB',
      'CY' => 'CYP',
      'CZ' => 'CZE',
      'DK' => 'DNK',
      'DJ' => 'DJI',
      'DM' => 'DMA',
      'DO' => 'DOM',
      'EC' => 'ECU',
      'EG' => 'EGY',
      'SV' => 'SLV',
      'GQ' => 'GNQ',
      'ER' => 'ERI',
      'EE' => 'EST',
      'ET' => 'ETH',
      'FJ' => 'FJI',
      'FI' => 'FIN',
      'FR' => 'FRA',
      'GA' => 'GAB',
      'GM' => 'GMB',
      'GE' => 'GEO',
      'DE' => 'DEU',
      'GH' => 'GHA',
      'GR' => 'GRC',
      'GD' => 'GRD',
      'GT' => 'GTM',
      'GN' => 'GIN',
      'GW' => 'GNB',
      'GY' => 'GUY',
      'HT' => 'HTI',
      'HN' => 'HND',
      'HK' => 'HKG',
      'HU' => 'HUN',
      'IS' => 'ISL',
      'IN' => 'IND',
      'ID' => 'IDN',
      'IR' => 'IRN',
      'IQ' => 'IRQ',
      'IE' => 'IRL',
      'IL' => 'ISR',
      'IT' => 'ITA',
      'JM' => 'JAM',
      'JP' => 'JPN',
      'JO' => 'JOR',
      'KZ' => 'KAZ',
      'KE' => 'KEN',
      'KI' => 'KIR',
      'KP' => 'PRK',
      'KR' => 'KOR',
      'KW' => 'KWT',
      'KG' => 'KGZ',
      'LA' => 'LAO',
      'LV' => 'LVA',
      'LB' => 'LBN',
      'LS' => 'LSO',
      'LR' => 'LBR',
      'LY' => 'LBY',
      'LI' => 'LIE',
      'LT' => 'LTU',
      'LU' => 'LUX',
      'MO' => 'MAC',
      'MK' => 'MKD',
      'MG' => 'MDG',
      'MW' => 'MWI',
      'MY' => 'MYS',
      'MV' => 'MDV',
      'ML' => 'MLI',
      'MT' => 'MLT',
      'MH' => 'MHL',
      'MR' => 'MRT',
      'MU' => 'MUS',
      'MX' => 'MEX',
      'FM' => 'FSM',
      'MD' => 'MDA',
      'MC' => 'MCO',
      'MN' => 'MNG',
      'ME' => 'MNE',
      'MA' => 'MAR',
      'MZ' => 'MOZ',
      'MM' => 'MMR',
      'NA' => 'NAM',
      'NR' => 'NRU',
      'NP' => 'NPL',
      'NL' => 'NLD',
      'NZ' => 'NZL',
      'NI' => 'NIC',
      'NE' => 'NER',
      'NG' => 'NGA',
      'NO' => 'NOR',
      'OM' => 'OMN',
      'PK' => 'PAK',
      'PW' => 'PLW',
      'PA' => 'PAN',
      'PG' => 'PNG',
      'PY' => 'PRY',
      'PE' => 'PER',
      'PH' => 'PHL',
      'PL' => 'POL',
      'PT' => 'PRT',
      'PR' => 'PRI',
      'QA' => 'QAT',
      'RO' => 'ROU',
      'RU' => 'RUS',
      'RW' => 'RWA',
      'KN' => 'KNA',
      'LC' => 'LCA',
      'VC' => 'VCT',
      'WS' => 'WSM',
      'SM' => 'SMR',
      'ST' => 'STP',
      'SA' => 'SAU',
      'SN' => 'SEN',
      'RS' => 'SRB',
      'SC' => 'SYC',
      'SL' => 'SLE',
      'SG' => 'SGP',
      'SK' => 'SVK',
      'SI' => 'SVN',
      'SB' => 'SLB',
      'SO' => 'SOM',
      'ZA' => 'ZAF',
      'ES' => 'ESP',
      'LK' => 'LKA',
      'SD' => 'SDN',
      'SR' => 'SUR',
      'SZ' => 'SWZ',
      'SE' => 'SWE',
      'CH' => 'CHE',
      'SY' => 'SYR',
      'TW' => 'TWN',
      'TJ' => 'TJK',
      'TZ' => 'TZA',
      'TH' => 'THA',
      'TL' => 'TLS',
      'TG' => 'TGO',
      'TO' => 'TON',
      'TT' => 'TTO',
      'TN' => 'TUN',
      'TR' => 'TUR',
      'TM' => 'TKM',
      'TV' => 'TUV',
      'UG' => 'UGA',
      'UA' => 'UKR',
      'AE' => 'ARE',
      'GB' => 'GBR',
      'US' => 'USA',
      'UY' => 'URY',
      'UZ' => 'UZB',
      'VU' => 'VUT',
      'VE' => 'VEN',
      'VN' => 'VNM',
      'YE' => 'YEM',
      'ZM' => 'ZMB',
      'ZW' => 'ZWE',
      'PS' => 'PSE',
      'XK' => 'XKX',
      'SS' => 'SSD',
    ];
    $alpha2 = strtoupper($alpha2);
    return $map[$alpha2] ?? '';
  }

  private function wc_mode(): string
  {
    $mode = get_option('didit_wc_mode', '');
    if ('' === $mode) {
      return get_option('didit_wc_required', false) ? 'checkout' : 'off';
    }
    return in_array($mode, ['checkout', 'after_purchase'], true) ? $mode : 'off';
  }

  private function wc_scope(): string
  {
    $scope = get_option('didit_wc_scope', 'all');
    return in_array($scope, ['include', 'exclude'], true) ? $scope : 'all';
  }

  private function wc_product_is_selected(int $product_id): bool
  {
    return $product_id && 'yes' === get_post_meta($product_id, '_didit_verify', true);
  }

  private function wc_items_require_verification(array $product_ids): bool
  {
    $scope = $this->wc_scope();
    if ('all' === $scope) {
      return true;
    }
    foreach ($product_ids as $product_id) {
      $selected = $this->wc_product_is_selected(absint($product_id));
      if ('include' === $scope ? $selected : !$selected) {
        return true;
      }
    }
    return false;
  }

  private function wc_cart_requires_verification(): bool
  {
    if ('all' === $this->wc_scope()) {
      return true;
    }
    if (!function_exists('WC')) {
      return true;
    }
    if (null === WC()->cart && function_exists('wc_load_cart')) {
      wc_load_cart();
    }
    if (null === WC()->cart) {
      return true;
    }
    $product_ids = [];
    foreach (WC()->cart->get_cart() as $item) {
      $product_ids[] = absint($item['product_id'] ?? 0);
    }
    return $this->wc_items_require_verification($product_ids);
  }

  private function wc_order_requires_verification($order): bool
  {
    if ('all' === $this->wc_scope()) {
      return true;
    }
    $product_ids = [];
    foreach ($order->get_items() as $item) {
      if (is_callable([$item, 'get_product_id'])) {
        $product_ids[] = absint($item->get_product_id());
      }
    }
    return $this->wc_items_require_verification($product_ids);
  }

  private function wc_apply_verification_to_order($order, string $status, string $source): bool
  {
    $changed = (string) $order->get_meta('_didit_status') !== $status;

    if ($changed) {
      $order->update_meta_data('_didit_status', $status);
      $order->add_order_note(sprintf(
        /* translators: 1: verification status, 2: source (browser/webhook). */
        __('Didit: verification status "%1$s" (via %2$s).', 'didit-verify'),
        $status,
        $source
      ));
    }

    if ('Approved' === $status && 'webhook' === $source && 'yes' === $order->get_meta('_didit_held') && $order->has_status('on-hold')) {
      $order->delete_meta_data('_didit_held');
      $order->save();
      $order->update_status('processing', __('Didit: identity verified — order released.', 'didit-verify'));
    } elseif ($changed) {
      $order->save();
    }

    return $changed;
  }

  private function wc_order_verification_url($order): string
  {
    return $order->get_customer_id()
      ? $order->get_view_order_url()
      : $order->get_checkout_order_received_url();
  }

  private function resolve_vendor_data(): string
  {
    $mode = get_option('didit_vendor_data_mode', 'user_id');
    $prefix = get_option('didit_vendor_data_prefix', '');

    switch ($mode) {
      case 'user_id':
        if (is_user_logged_in()) {
          return 'wp-' . get_current_user_id();
        }
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        return 'guest-' . substr(md5($ip), 0, 12);

      case 'user_email':
        if (is_user_logged_in()) {
          return wp_get_current_user()->user_email;
        }
        return '';

      case 'custom':
        if (is_user_logged_in()) {
          return $prefix . get_current_user_id();
        }
        return $prefix . 'guest';

      case 'none':
      default:
        return '';
    }
  }

  public function enqueue_scripts()
  {
    if (!$this->page_needs_sdk()) {
      return;
    }

    $sdk_url = apply_filters('didit_sdk_url', DIDIT_VERIFY_URL . 'assets/js/didit-sdk.umd.min.js');

    wp_enqueue_style('didit-verify', DIDIT_VERIFY_URL . 'assets/css/didit-verify.css', [], DIDIT_VERIFY_VERSION);
    wp_enqueue_script('didit-sdk', $sdk_url, [], DIDIT_VERIFY_VERSION, true);
    wp_enqueue_script('didit-verify', DIDIT_VERIFY_URL . 'assets/js/didit-verify.js', ['didit-sdk'], DIDIT_VERIFY_VERSION, true);

    wp_localize_script('didit-verify', 'diditConfig', [
      'mode' => get_option('didit_mode', 'unilink'),
      'unilinkUrl' => get_option('didit_unilink_url', ''),
      'restUrl' => esc_url_raw(rest_url('didit/v1/session')),
      'nonce' => wp_create_nonce('wp_rest'),
      'sendBilling' => (bool) get_option('didit_wc_send_billing', true),
      'displayMode' => get_option('didit_display_mode', 'modal'),
      'showCloseButton' => (bool) get_option('didit_show_close_btn', true),
      'showExitConfirmation' => (bool) get_option('didit_exit_confirmation', true),
      'closeModalOnComplete' => (bool) get_option('didit_close_on_complete', false),
      'loggingEnabled' => (bool) get_option('didit_logging', false),
      'i18n' => [
        'creatingSession' => __('Creating session…', 'didit-verify'),
        'inReview' => __('Verification In Review', 'didit-verify'),
        'verificationError' => __('Verification error:', 'didit-verify'),
        'noUrl' => __('No verification URL returned', 'didit-verify'),
      ],
    ]);

    $bg = esc_attr(get_option('didit_btn_bg_color', '#2667ff'));
    $tc = esc_attr(get_option('didit_btn_text_color', '#ffffff'));
    $rad = (int) get_option('didit_btn_border_radius', 8);
    $pv = (int) get_option('didit_btn_padding_v', 12);
    $ph = (int) get_option('didit_btn_padding_h', 24);
    $fs = (int) get_option('didit_btn_font_size', 16);

    $css = ".didit-verify-btn{background:{$bg};color:{$tc};border:none;border-radius:{$rad}px;padding:{$pv}px {$ph}px;font-size:{$fs}px;font-weight:600;font-family:inherit;cursor:pointer;line-height:1.4;transition:opacity .2s,box-shadow .2s;}"
      . ".didit-verify-btn:hover{opacity:.9;box-shadow:0 4px 12px rgba(0,0,0,.2);}"
      . ".didit-verify-btn:disabled{opacity:.5;cursor:not-allowed;box-shadow:none;}"
      . ".didit-verify-btn.didit-verified{background:#41D97F;opacity:1;}"
      . ".didit-verify-btn.didit-in-review{background:#F59E0B;opacity:1;}"
      . ".didit-verify-btn.didit-declined{background:{$bg};}";

    wp_add_inline_style('didit-verify', $css);
  }

  private function page_needs_sdk()
  {
    if (function_exists('is_checkout') && is_checkout() && 'checkout' === $this->wc_mode() && $this->wc_cart_requires_verification()) {
      return true;
    }
    if (
      'after_purchase' === $this->wc_mode() && function_exists('is_order_received_page')
      && (is_order_received_page() || is_wc_endpoint_url('view-order'))
    ) {
      return true;
    }
    global $post;
    if (is_a($post, 'WP_Post')) {
      foreach (['didit_verify', 'didit_gate'] as $sc) {
        if (has_shortcode($post->post_content, $sc)) {
          return true;
        }
      }
    }
    return false;
  }

  public function render_shortcode($atts)
  {
    static $instance = 0;
    $instance++;

    $btn_text = $this->btn_text();
    $btn_success = $this->btn_success_text();

    $a = shortcode_atts([
      'text' => $btn_text,
      'success_text' => $btn_success,
      'mode' => '',
    ], $atts, 'didit_verify');

    $display = $a['mode'] ? $a['mode'] : get_option('didit_display_mode', 'modal');
    $is_embedded = ('embedded' === $display);
    $container_id = 'didit-embed-' . $instance;
    $container_attr = $is_embedded ? sprintf(' data-container="%s"', esc_attr($container_id)) : '';

    $embed_html = $is_embedded
      ? sprintf('<div class="didit-embed-container" id="%s"></div>', esc_attr($container_id))
      : '';

    return sprintf(
      '<div class="didit-verify-wrap">
        <button type="button" class="didit-verify-btn" data-text="%s" data-success="%s"%s>%s</button>
        %s
      </div>',
      esc_attr($a['text']),
      esc_attr($a['success_text']),
      $container_attr,
      esc_html($a['text']),
      $embed_html
    );
  }

  public function render_status_shortcode($atts)
  {
    $a = shortcode_atts([
      'verified_text' => __('Identity Verified', 'didit-verify'),
      'unverified_text' => __('Not Verified', 'didit-verify'),
      'declined_text' => __('Verification Declined', 'didit-verify'),
      'pending_text' => __('Verification In Review', 'didit-verify'),
      'login_text' => __('Please log in', 'didit-verify'),
    ], $atts, 'didit_status');

    if (!is_user_logged_in()) {
      return sprintf('<span class="didit-status didit-not-logged-in">%s</span>', esc_html($a['login_text']));
    }

    $status = get_user_meta(get_current_user_id(), '_didit_status', true);

    if ('Approved' === $status) {
      return sprintf('<span class="didit-status didit-status-approved" style="color:#41D97F;">%s</span>', esc_html($a['verified_text']));
    }
    if ('Declined' === $status) {
      return sprintf('<span class="didit-status didit-status-declined" style="color:#FF4141;">%s</span>', esc_html($a['declined_text']));
    }
    if ($status && 'Approved' !== $status && 'Declined' !== $status) {
      return sprintf('<span class="didit-status didit-status-pending" style="color:#F59E0B;">%s</span>', esc_html($a['pending_text']));
    }

    return sprintf('<span class="didit-status didit-unverified">%s</span>', esc_html($a['unverified_text']));
  }

  public function render_gate_shortcode($atts, $content = null)
  {
    $a = shortcode_atts([
      'message' => __('Please verify your identity to access this content.', 'didit-verify'),
    ], $atts, 'didit_gate');

    if (!is_user_logged_in()) {
      return sprintf(
        '<div class="didit-gate didit-gate-locked"><p>%s</p></div>',
        esc_html__('Please log in to access this content.', 'didit-verify')
      );
    }

    $status = get_user_meta(get_current_user_id(), '_didit_status', true);
    if ('Approved' === $status) {
      return '<div class="didit-gate didit-gate-unlocked">' . do_shortcode($content) . '</div>';
    }

    if ($status && 'Approved' !== $status && 'Declined' !== $status) {
      return sprintf(
        '<div class="didit-gate didit-gate-locked"><p style="color:#F59E0B;">%s</p></div>',
        esc_html__('Your identity verification is being reviewed. Please check back shortly.', 'didit-verify')
      );
    }

    return sprintf(
      '<div class="didit-gate didit-gate-locked"><p>%s</p>%s</div>',
      esc_html($a['message']),
      $this->render_shortcode([])
    );
  }

  public function users_column($columns)
  {
    $columns['didit_verified'] = __('Didit', 'didit-verify');
    return $columns;
  }

  public function users_column_content($output, $column_name, $user_id)
  {
    if ('didit_verified' !== $column_name) {
      return $output;
    }
    $status = get_user_meta($user_id, '_didit_status', true);
    $date = get_user_meta($user_id, '_didit_verified_at', true);

    if ('Approved' === $status) {
      /* translators: %s: date when user was verified */
      $title = $date ? sprintf(__('Verified on %s', 'didit-verify'), $date) : __('Approved', 'didit-verify');
      return '<span style="color:#41D97F;font-size:1.2em;" title="' . esc_attr($title) . '">&#10004;</span>';
    }
    if ('Declined' === $status) {
      $title = $date ? sprintf(__('Declined on %s', 'didit-verify'), $date) : __('Declined', 'didit-verify');
      return '<span style="color:#FF4141;font-size:1.2em;" title="' . esc_attr($title) . '">&#10008;</span>';
    }
    if ($status) {
      $title = $date ? sprintf(__('In review since %s', 'didit-verify'), $date) : __('In Review', 'didit-verify');
      return '<span style="color:#F59E0B;font-size:1.2em;" title="' . esc_attr($title) . '">&#9202;</span>';
    }

    return '<span style="color:#9ca3af;" title="' . esc_attr__('Not verified', 'didit-verify') . '">&#8212;</span>';
  }

  public function wc_hooks()
  {
    add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'wc_show_order_meta']);
    add_action('didit_wc_verification_reminder', [$this, 'wc_send_verification_reminder']);

    add_action('woocommerce_product_options_advanced', [$this, 'wc_product_verification_field']);
    add_action('woocommerce_admin_process_product_object', [$this, 'wc_product_save_verification_field']);

    if ('checkout' === $this->wc_mode()) {
      $hooks = [
        'before_checkout' => 'woocommerce_before_checkout_form',
        'after_billing' => 'woocommerce_after_checkout_billing_form',
        'after_order_notes' => 'woocommerce_after_order_notes',
        'before_submit' => 'woocommerce_review_order_before_submit',
      ];
      $position = get_option('didit_wc_position', 'before_submit');
      $hook = $hooks[$position] ?? $hooks['before_submit'];

      add_action($hook, [$this, 'wc_checkout_field']);
      add_action('woocommerce_checkout_process', [$this, 'wc_validate_checkout']);
      add_action('woocommerce_checkout_update_order_meta', [$this, 'wc_save_order_meta']);

      add_filter('render_block_woocommerce/checkout-actions-block', [$this, 'wc_block_checkout_field']);
      add_filter('rest_authentication_errors', [$this, 'wc_block_validate_checkout']);
      add_action('woocommerce_store_api_checkout_order_processed', [$this, 'wc_block_save_order_meta']);
    }

    if ('after_purchase' === $this->wc_mode()) {
      add_action('woocommerce_thankyou', [$this, 'wc_post_purchase_box_echo'], 9);
      add_filter('render_block_woocommerce/order-confirmation-status', [$this, 'wc_block_confirmation_box'], 10, 1);
      add_action('woocommerce_view_order', [$this, 'wc_post_purchase_box_echo'], 5);

      add_action('woocommerce_checkout_order_processed', [$this, 'wc_mark_order_pending_verification'], 10, 1);
      add_action('woocommerce_store_api_checkout_order_processed', [$this, 'wc_mark_order_pending_verification'], 10, 1);
      add_action('woocommerce_order_status_changed', [$this, 'wc_maybe_hold_order'], 20, 4);
      add_action('woocommerce_email_order_details', [$this, 'wc_email_verification_link'], 5, 4);
    }
  }

  public function wc_product_verification_field()
  {
    echo '<div class="options_group">';
    woocommerce_wp_checkbox([
      'id' => '_didit_verify',
      'label' => __('Didit verification', 'didit-verify'),
      'description' => __('Select this product for the "Only selected products" / "All products except selected" scope in the Didit Verify settings.', 'didit-verify'),
    ]);
    echo '</div>';
  }

  public function wc_product_save_verification_field($product)
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-edit nonce before firing this hook.
    $product->update_meta_data('_didit_verify', isset($_POST['_didit_verify']) ? 'yes' : 'no');
  }

  public function wc_checkout_field()
  {
    if ('checkout' !== $this->wc_mode() || !$this->wc_cart_requires_verification()) {
      return;
    }
    ?>
    <?php
    $is_embedded = ('embedded' === get_option('didit_display_mode', 'modal'));
    $btn_text = $this->btn_text();
    $btn_success = $this->btn_success_text();
    ?>
    <div id="didit-wc-verify" class="didit-verify-wrap"
      style="margin: 1.5em 0; padding: 1em; border: 1px solid #ddd; border-radius: 6px;">
      <h3 style="margin-top:0;"><?php echo esc_html($this->wc_box_title()); ?></h3>
      <p style="color:#666; font-size:0.9em;">
        <?php echo esc_html($this->wc_checkout_text()); ?>
      </p>
      <button type="button" class="didit-verify-btn" data-text="<?php echo esc_attr($btn_text); ?>"
        data-success="<?php echo esc_attr($btn_success); ?>" data-wc="1" <?php if ($is_embedded)
             echo ' data-container="didit-wc-embed"'; ?>>
        <?php echo esc_html($btn_text); ?>
      </button>
      <input type="hidden" name="didit_session_id" id="didit_session_id" value="" />
      <?php if ($is_embedded): ?>
        <div class="didit-embed-container" id="didit-wc-embed"></div>
      <?php endif; ?>
    </div>
    <?php
  }

  public function wc_validate_checkout()
  {
    if ('checkout' !== $this->wc_mode() || !$this->wc_cart_requires_verification()) {
      return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $session_id = isset($_POST['didit_session_id'])
      ? sanitize_text_field(wp_unslash($_POST['didit_session_id']))
      : '';

    if (empty($session_id)) {
      wc_add_notice(
        __('Please complete identity verification before placing your order.', 'didit-verify'),
        'error'
      );
    }
  }

  public function wc_save_order_meta($order_id)
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (!isset($_POST['didit_session_id'])) {
      return;
    }
    $session_id = sanitize_text_field(wp_unslash($_POST['didit_session_id']));
    if (empty($session_id)) {
      return;
    }
    $order = wc_get_order($order_id);
    if ($order) {
      $order->update_meta_data('_didit_session_id', $session_id);
      $order->save();
    }
  }

  public function wc_show_order_meta($order)
  {
    $session_id = $order->get_meta('_didit_session_id');
    if ($session_id) {
      $status = (string) $order->get_meta('_didit_status');
      printf(
        '<p><strong>%s</strong> %s%s</p>',
        esc_html__('Didit Verification:', 'didit-verify'),
        esc_html($session_id),
        $status ? ' — ' . esc_html($status) : ''
      );
    }
  }


  public function wc_post_purchase_box_echo($order_id)
  {
    $order = wc_get_order($order_id);
    if ($order) {
      echo $this->wc_render_post_purchase_box($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
    }
  }

  public function wc_block_confirmation_box($block_content)
  {
    global $wp;
    $order = wc_get_order(absint($wp->query_vars['order-received'] ?? 0));
    if (!$order) {
      return $block_content;
    }
    return $block_content . $this->wc_render_post_purchase_box($order);
  }

  private function customer_can_access_order($order): bool
  {
    $url_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability check, not a state change.
    if ($url_key && hash_equals($order->get_order_key(), $url_key)) {
      return true;
    }
    $customer_id = $order->get_customer_id();
    return $customer_id && get_current_user_id() === $customer_id;
  }

  private function wc_render_post_purchase_box($order): string
  {
    static $rendered = false;
    if (
      $rendered
      || !$this->customer_can_access_order($order)
      || in_array($order->get_status(), ['cancelled', 'refunded', 'failed'], true)
      || !$this->wc_order_requires_verification($order)
    ) {
      return '';
    }
    $rendered = true;

    $status = (string) $order->get_meta('_didit_status');

    if ('Approved' === $status) {
      return sprintf(
        '<div id="didit-wc-verify" class="didit-verify-wrap" style="margin:1.5em 0; padding:1em; border:1px solid #41D97F; border-radius:6px;">
          <h3 style="margin-top:0;">%s</h3>
          <p style="color:#41D97F; font-weight:600; margin:0;">%s</p>
        </div>',
        esc_html($this->wc_box_title()),
        esc_html__('Your identity has been verified. Thank you!', 'didit-verify')
      );
    }

    if ('In Review' === $status) {
      return sprintf(
        '<div id="didit-wc-verify" class="didit-verify-wrap" style="margin:1.5em 0; padding:1em; border:1px solid #F59E0B; border-radius:6px;">
          <h3 style="margin-top:0;">%s</h3>
          <p style="color:#F59E0B; font-weight:600; margin:0;">%s</p>
        </div>',
        esc_html($this->wc_box_title()),
        esc_html__('Your verification is being reviewed. No further action is needed.', 'didit-verify')
      );
    }

    $intro = 'Declined' === $status
      ? __('Your previous verification attempt was declined. Please try again.', 'didit-verify')
      : $this->wc_post_purchase_text();

    $is_embedded = ('embedded' === get_option('didit_display_mode', 'modal'));
    $btn_text = $this->btn_text();
    $btn_success = $this->btn_success_text();

    return sprintf(
      '<div id="didit-wc-verify" class="didit-verify-wrap" style="margin:1.5em 0; padding:1em; border:1px solid #ddd; border-radius:6px;">
        <h3 style="margin-top:0;">%s</h3>
        <p style="color:#666; font-size:0.9em;">%s</p>
        <button type="button" class="didit-verify-btn" data-text="%s" data-success="%s" data-order-id="%d" data-order-key="%s"%s>%s</button>
        %s
      </div>',
      esc_html($this->wc_box_title()),
      esc_html($intro),
      esc_attr($btn_text),
      esc_attr($btn_success),
      absint($order->get_id()),
      esc_attr($order->get_order_key()),
      $is_embedded ? ' data-container="didit-wc-embed"' : '',
      esc_html($btn_text),
      $is_embedded ? '<div class="didit-embed-container" id="didit-wc-embed"></div>' : ''
    );
  }

  public function wc_mark_order_pending_verification($order)
  {
    $order = $order instanceof WC_Order ? $order : wc_get_order($order);
    if (
      !$order || 'after_purchase' !== $this->wc_mode() || $order->get_meta('_didit_requires_verification')
      || !$this->wc_order_requires_verification($order)
    ) {
      return;
    }
    $order->update_meta_data('_didit_requires_verification', 'yes');
    $order->save();
    $this->wc_schedule_reminder($order->get_id());
  }

  public function wc_maybe_hold_order($order_id, $from, $to, $order)
  {
    static $holding = false;
    if (
      $holding || 'processing' !== $to
      || !get_option('didit_wc_hold', false) || 'after_purchase' !== $this->wc_mode()
      || !$order->get_meta('_didit_requires_verification')
      || 'Approved' === $order->get_meta('_didit_status')
    ) {
      return;
    }
    $holding = true;
    $order->update_meta_data('_didit_held', 'yes');
    $order->save();
    $order->update_status('on-hold', __('Didit: order held until identity verification is approved.', 'didit-verify'));
    $holding = false;
  }

  public function wc_email_verification_link($order, $sent_to_admin, $plain_text, $email)
  {
    if (
      $sent_to_admin || 'after_purchase' !== $this->wc_mode()
      || !$order->get_meta('_didit_requires_verification')
      || in_array($order->get_meta('_didit_status'), ['Approved', 'In Review'], true)
    ) {
      return;
    }

    $url = $this->wc_order_verification_url($order);

    if ($plain_text) {
      printf(
        "\n%s\n%s\n\n",
        esc_html__('Please verify your identity to complete your order:', 'didit-verify'),
        esc_url($url)
      );
      return;
    }

    printf(
      '<p style="margin:16px 0;"><strong>%s</strong><br />%s <a href="%s">%s</a></p>',
      esc_html__('Identity verification required', 'didit-verify'),
      esc_html__('Please verify your identity to complete your order:', 'didit-verify'),
      esc_url($url),
      esc_html__('Verify your identity', 'didit-verify')
    );
  }

  private function wc_schedule_reminder($order_id)
  {
    if (!get_option('didit_wc_reminders', false)) {
      return;
    }
    $interval = max(1, (int) get_option('didit_wc_reminder_interval', 3));
    $timestamp = time() + $interval * DAY_IN_SECONDS;

    if (function_exists('as_schedule_single_action')) {
      as_schedule_single_action($timestamp, 'didit_wc_verification_reminder', [$order_id], 'didit-verify');
    } else {
      wp_schedule_single_event($timestamp, 'didit_wc_verification_reminder', [$order_id]);
    }
  }

  public function wc_send_verification_reminder($order_id)
  {
    $order = wc_get_order($order_id);
    if (
      !$order || 'after_purchase' !== $this->wc_mode() || !get_option('didit_wc_reminders', false)
      || !$order->get_meta('_didit_requires_verification')
      || in_array($order->get_meta('_didit_status'), ['Approved', 'Declined', 'In Review'], true)
      || in_array($order->get_status(), ['cancelled', 'refunded', 'failed'], true)
    ) {
      return;
    }

    $count = (int) $order->get_meta('_didit_reminder_count');
    $max = max(1, (int) get_option('didit_wc_reminder_max', 3));
    $to = $order->get_billing_email();
    if ($count >= $max || !$to) {
      return;
    }

    $subject = sprintf(
      /* translators: %s: order number. */
      __('Action required: verify your identity for order #%s', 'didit-verify'),
      $order->get_order_number()
    );
    $body = sprintf(
      '<p>%s</p><p><a href="%s">%s</a></p>',
      esc_html__('Your order is waiting for identity verification. It only takes a couple of minutes.', 'didit-verify'),
      esc_url($this->wc_order_verification_url($order)),
      esc_html__('Verify your identity now', 'didit-verify')
    );

    $mailer = WC()->mailer();
    $mailer->send($to, $subject, $mailer->wrap_message($subject, $body));

    $order->update_meta_data('_didit_reminder_count', $count + 1);
    $order->add_order_note(sprintf(
      /* translators: 1: reminder number, 2: max reminders, 3: recipient email. */
      __('Didit: verification reminder %1$d of %2$d sent to %3$s.', 'didit-verify'),
      $count + 1,
      $max,
      $to
    ));
    $order->save();

    if ($count + 1 < $max) {
      $this->wc_schedule_reminder($order_id);
    }
  }

  /* ── Block-based checkout (WooCommerce 8.3+) ── */

  public function wc_block_checkout_field($block_content)
  {
    if ('checkout' !== $this->wc_mode() || !$this->wc_cart_requires_verification()) {
      return $block_content;
    }

    $is_embedded = ('embedded' === get_option('didit_display_mode', 'modal'));
    $btn_text = $this->btn_text();
    $btn_success = $this->btn_success_text();

    ob_start();
    ?>
    <div id="didit-wc-verify" class="didit-verify-wrap"
      style="margin: 1.5em 0; padding: 1em; border: 1px solid #ddd; border-radius: 6px;">
      <h3 style="margin-top:0;"><?php echo esc_html($this->wc_box_title()); ?></h3>
      <p style="color:#666; font-size:0.9em;">
        <?php echo esc_html($this->wc_checkout_text()); ?>
      </p>
      <button type="button" class="didit-verify-btn" data-text="<?php echo esc_attr($btn_text); ?>"
        data-success="<?php echo esc_attr($btn_success); ?>" data-wc="1"<?php if ($is_embedded)
             echo ' data-container="didit-wc-embed"'; ?>>
        <?php echo esc_html($btn_text); ?>
      </button>
      <?php if ($is_embedded): ?>
        <div class="didit-embed-container" id="didit-wc-embed"></div>
      <?php endif; ?>
    </div>
    <?php
    $verification_html = ob_get_clean();

    return $verification_html . $block_content;
  }

  public function wc_block_validate_checkout($result)
  {
    if ('checkout' !== $this->wc_mode()) {
      return $result;
    }

    if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
      return $result;
    }

    $rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
    if (!preg_match('#/wc/store(?:/v\d+)?/checkout$#', $rest_route)) {
      return $result;
    }

    if (!$this->wc_cart_requires_verification()) {
      return $result;
    }

    $raw = WP_REST_Server::get_raw_data();
    $body = json_decode($raw, true);
    $session_id = $body['extensions']['didit-verify']['sessionId'] ?? '';

    if (empty($session_id)) {
      return new WP_Error(
        'didit_verification_required',
        __('Please complete identity verification before placing your order.', 'didit-verify'),
        ['status' => 403]
      );
    }

    $this->block_session_id = sanitize_text_field($session_id);

    return $result;
  }

  public function wc_block_save_order_meta($order)
  {
    if (empty($this->block_session_id)) {
      return;
    }
    $order->update_meta_data('_didit_session_id', $this->block_session_id);
    $order->save();
  }
}

Didit_Verify::init();
