<?php
/**
 * Plugin Name: Didit Verify
 * Plugin URI:  https://didit.me
 * Description: Identity verification for WordPress & WooCommerce using the Didit SDK.
 * Version:     0.1.0
 * Author:      Didit
 * Author URI:  https://didit.me
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: didit-verify
 */

if (!defined('ABSPATH')) {
  exit;
}

define('DIDIT_VERIFY_VERSION', '0.1.0');
define('DIDIT_VERIFY_URL', plugin_dir_url(__FILE__));
define('DIDIT_API_URL', 'https://verification.didit.me/v3/session/');

final class Didit_Verify
{

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
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'admin_register_settings']);

    add_filter('manage_users_columns', [$this, 'users_column']);
    add_filter('manage_users_custom_column', [$this, 'users_column_content'], 10, 3);

    add_action('rest_api_init', [$this, 'register_routes']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    add_shortcode('didit_verify', [$this, 'render_shortcode']);
    add_shortcode('didit_status', [$this, 'render_status_shortcode']);
    add_shortcode('didit_gate', [$this, 'render_gate_shortcode']);

    add_action('woocommerce_loaded', [$this, 'wc_hooks']);
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
      'didit_wc_position' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'before_submit'],
      'didit_wc_send_billing' => ['sanitize_callback' => 'rest_sanitize_boolean', 'default' => true],
      'didit_btn_text' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'Verify your Identity'],
      'didit_btn_success_text' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'Identity Verified ✓'],
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

    add_settings_section('didit_woocommerce', __('WooCommerce', 'didit-verify'), '__return_false', 'didit-verify');
    add_settings_field('didit_wc_required', __('Checkout', 'didit-verify'), [$this, 'field_wc'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_position', __('Position', 'didit-verify'), [$this, 'field_wc_position'], 'didit-verify', 'didit_woocommerce');
    add_settings_field('didit_wc_send_billing', __('Send Billing Data', 'didit-verify'), [$this, 'field_wc_send_billing'], 'didit-verify', 'didit_woocommerce');
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
      esc_html__('Required for API mode. Found in Didit Console → Workflow settings.', 'didit-verify')
    );
  }

  public function field_api_key()
  {
    printf(
      '<input type="password" name="didit_api_key" value="%s" class="regular-text" autocomplete="off" />
			<p class="description">%s</p>',
      esc_attr(get_option('didit_api_key', '')),
      esc_html__('Stored server-side only — never sent to the browser.', 'didit-verify')
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
			</div>
			<script>
			document.getElementById("didit_vendor_data_mode").addEventListener("change",function(){
				document.getElementById("didit-vendor-prefix-row").style.display=this.value==="custom"?"":"none";
			});
			</script>',
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
    $text = get_option('didit_btn_text', 'Verify your Identity');
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
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var preview = document.getElementById('didit-btn-preview');
        if (!preview) return;

        function bind(name, prop, unit) {
          var el = document.querySelector('[name="' + name + '"]');
          if (!el) return;
          el.addEventListener('input', function () {
            if (prop === 'textContent') { preview.textContent = this.value; return; }
            if (prop === 'padding') {
              var pv = document.querySelector('[name="didit_btn_padding_v"]');
              var ph = document.querySelector('[name="didit_btn_padding_h"]');
              preview.style.padding = (pv ? pv.value : 12) + 'px ' + (ph ? ph.value : 24) + 'px';
              return;
            }
            preview.style[prop] = this.value + (unit || '');
          });
        }

        bind('didit_btn_text', 'textContent');
        bind('didit_btn_bg_color', 'background');
        bind('didit_btn_text_color', 'color');
        bind('didit_btn_border_radius', 'borderRadius', 'px');
        bind('didit_btn_padding_v', 'padding');
        bind('didit_btn_padding_h', 'padding');
        bind('didit_btn_font_size', 'fontSize', 'px');
      });
    </script>
    <?php
  }

  public function field_btn_text()
  {
    printf(
      '<input type="text" name="didit_btn_text" value="%s" class="regular-text" />
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_text', 'Verify your Identity')),
      esc_html__('Label shown on the button before verification.', 'didit-verify')
    );
  }

  public function field_btn_success_text()
  {
    printf(
      '<input type="text" name="didit_btn_success_text" value="%s" class="regular-text" />
      <p class="description">%s</p>',
      esc_attr(get_option('didit_btn_success_text', 'Identity Verified ✓')),
      esc_html__('Label shown after successful verification.', 'didit-verify')
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

  public function field_wc()
  {
    if (!class_exists('WooCommerce')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    printf(
      '<label><input type="checkbox" name="didit_wc_required" value="1" %s /> %s</label>',
      checked(get_option('didit_wc_required', false), true, false),
      esc_html__('Require identity verification at checkout', 'didit-verify')
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
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);
  }

  public function rest_check_permission($request)
  {
    $nonce = $request->get_header('x_wp_nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
      return new WP_Error('rest_forbidden', __('Invalid security token.', 'didit-verify'), ['status' => 403]);
    }

    if (get_option('didit_require_login', true) && !is_user_logged_in()) {
      return new WP_Error('rest_forbidden', __('You must be logged in to start verification.', 'didit-verify'), ['status' => 401]);
    }

    return true;
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

    if (!empty($input['contact_details']) && is_array($input['contact_details'])) {
      $body['contact_details'] = $this->sanitize_contact_details($input['contact_details']);
    }

    if (!empty($input['expected_details']) && is_array($input['expected_details'])) {
      $body['expected_details'] = $this->sanitize_expected_details($input['expected_details']);
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
      error_log('Didit API error (' . $status_code . '): ' . $raw);
      $msg = $data['detail'] ?? $data['error'] ?? __('Didit API error', 'didit-verify');
      return new WP_Error('api_error', $msg, ['status' => $status_code]);
    }

    $url = $data['url'] ?? (isset($data['session_token'])
      ? 'https://verify.didit.me/session/' . $data['session_token']
      : null);

    if (!$url) {
      return new WP_Error('api_error', __('No verification URL returned.', 'didit-verify'), ['status' => 500]);
    }

    do_action('didit_session_created', $url, get_current_user_id() ?: null, $vendor_data);

    return rest_ensure_response(['url' => $url]);
  }

  public function rest_save_verification($request)
  {
    $user_id = get_current_user_id();
    if (!$user_id) {
      return new WP_Error('not_logged_in', 'User not logged in.', ['status' => 401]);
    }

    $input = $request->get_json_params();
    $type = sanitize_text_field($input['type'] ?? '');
    $session_id = sanitize_text_field($input['sessionId'] ?? '');
    $status = sanitize_text_field($input['status'] ?? '');

    if ('completed' === $type) {
      update_user_meta($user_id, '_didit_verified', 1);
      update_user_meta($user_id, '_didit_session_id', $session_id);
      update_user_meta($user_id, '_didit_status', $status);
      update_user_meta($user_id, '_didit_verified_at', current_time('mysql'));

      do_action('didit_verification_completed', $user_id, $session_id, $status);
    } elseif ('cancelled' === $type) {
      do_action('didit_verification_cancelled', $user_id, $session_id);
    }

    return rest_ensure_response(['saved' => true]);
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

    $sdk_url = apply_filters('didit_sdk_url', 'https://unpkg.com/@didit-protocol/sdk-web@0.1.5/dist/didit-sdk.umd.min.js');

    wp_enqueue_style('didit-verify', DIDIT_VERIFY_URL . 'assets/css/didit-verify.css', [], DIDIT_VERIFY_VERSION);
    wp_enqueue_script('didit-sdk', $sdk_url, [], null, true);
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
      . ".didit-verify-btn.didit-verified{background:#10b981;}";

    wp_add_inline_style('didit-verify', $css);
  }

  private function page_needs_sdk()
  {
    if (function_exists('is_checkout') && is_checkout() && get_option('didit_wc_required', false)) {
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

    $btn_text = get_option('didit_btn_text', 'Verify your Identity');
    $btn_success = get_option('didit_btn_success_text', 'Identity Verified ✓');

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
      'login_text' => __('Please log in', 'didit-verify'),
    ], $atts, 'didit_status');

    if (!is_user_logged_in()) {
      return sprintf('<span class="didit-status didit-not-logged-in">%s</span>', esc_html($a['login_text']));
    }

    $verified = get_user_meta(get_current_user_id(), '_didit_verified', true);
    if ($verified) {
      return sprintf('<span class="didit-status didit-verified">%s</span>', esc_html($a['verified_text']));
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

    $verified = get_user_meta(get_current_user_id(), '_didit_verified', true);
    if ($verified) {
      return '<div class="didit-gate didit-gate-unlocked">' . do_shortcode($content) . '</div>';
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
    $verified = get_user_meta($user_id, '_didit_verified', true);
    if ($verified) {
      $date = get_user_meta($user_id, '_didit_verified_at', true);
      $title = $date ? sprintf(__('Verified on %s', 'didit-verify'), $date) : __('Verified', 'didit-verify');
      return '<span style="color:#10b981;font-size:1.2em;" title="' . esc_attr($title) . '">&#10004;</span>';
    }
    return '<span style="color:#9ca3af;" title="' . esc_attr__('Not verified', 'didit-verify') . '">&#8212;</span>';
  }

  public function wc_hooks()
  {
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
    add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'wc_show_order_meta']);
  }

  public function wc_checkout_field()
  {
    if (!get_option('didit_wc_required', false)) {
      return;
    }
    ?>
    <?php
    $is_embedded = ('embedded' === get_option('didit_display_mode', 'modal'));
    $btn_text = esc_attr(get_option('didit_btn_text', 'Verify your Identity'));
    $btn_success = esc_attr(get_option('didit_btn_success_text', 'Identity Verified ✓'));
    ?>
    <div id="didit-wc-verify" class="didit-verify-wrap"
      style="margin: 1.5em 0; padding: 1em; border: 1px solid #ddd; border-radius: 6px;">
      <h3 style="margin-top:0;"><?php esc_html_e('Identity Verification', 'didit-verify'); ?></h3>
      <p style="color:#666; font-size:0.9em;">
        <?php esc_html_e('Please verify your identity before placing your order.', 'didit-verify'); ?>
      </p>
      <button type="button" class="didit-verify-btn" data-text="<?php echo $btn_text; ?>"
        data-success="<?php echo $btn_success; ?>" data-wc="1" <?php if ($is_embedded)
             echo ' data-container="didit-wc-embed"'; ?>>
        <?php echo esc_html(get_option('didit_btn_text', 'Verify your Identity')); ?>
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
    if (!get_option('didit_wc_required', false)) {
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
      printf(
        '<p><strong>%s</strong> %s</p>',
        esc_html__('Didit Verification:', 'didit-verify'),
        esc_html($session_id)
      );
    }
  }
}

Didit_Verify::init();
