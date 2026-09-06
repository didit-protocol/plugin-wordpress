<?php
/**
 * The smallest slice of WordPress the webhook handler touches, so the signature
 * verification can be exercised without a WordPress install.
 *
 * Only functions reached by rest_webhook() (and by loading didit-verify.php) are
 * stubbed; anything else is deliberately left undefined so an accidental new
 * dependency in the handler surfaces as a fatal error instead of passing silently.
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

// Options the handler reads. Tests overwrite entries directly.
$GLOBALS['didit_test_options'] = [
  'didit_webhook_secret' => '',
  'didit_logging' => false,
];
// User meta written by the handler, keyed "<user_id>|<meta_key>".
$GLOBALS['didit_test_user_meta'] = [];
// Users that exist, and their stored session mapping.
$GLOBALS['didit_test_users'] = [];
// Actions fired by the handler.
$GLOBALS['didit_test_actions'] = [];
$GLOBALS['didit_test_enqueued_styles'] = [];
$GLOBALS['didit_test_enqueued_scripts'] = [];
$GLOBALS['didit_test_localized_scripts'] = [];
$GLOBALS['didit_test_inline_styles'] = [];

function add_action() {}
function add_filter() {}
function add_shortcode() {}
function plugin_dir_url() { return 'https://example.test/wp-content/plugins/didit-verify/'; }
function plugin_basename($file) { return basename($file); }
function load_plugin_textdomain() {}
function __($text) { return $text; }
function esc_html__($text) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($url) { return (string) $url; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function current_time() { return '2026-03-31 12:00:00'; }
function shortcode_atts($pairs, $atts) { return array_merge($pairs, (array) $atts); }
function apply_filters($hook, $value) { return $value; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
function wp_create_nonce($action) { return 'test-nonce-' . $action; }

function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false) {
  $GLOBALS['didit_test_enqueued_styles'][$handle] = compact('src', 'deps', 'ver');
}

function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {
  $GLOBALS['didit_test_enqueued_scripts'][$handle] = compact('src', 'deps', 'ver', 'in_footer');
}

function wp_localize_script($handle, $object_name, $l10n) {
  $GLOBALS['didit_test_localized_scripts'][$handle][$object_name] = $l10n;
}

function wp_add_inline_style($handle, $data) {
  $GLOBALS['didit_test_inline_styles'][$handle][] = $data;
}

function get_option($name, $default = false) {
  return array_key_exists($name, $GLOBALS['didit_test_options'])
    ? $GLOBALS['didit_test_options'][$name]
    : $default;
}

function get_users($args) {
  $matches = [];
  foreach ($GLOBALS['didit_test_users'] as $id => $session_id) {
    if (isset($args['meta_key'], $args['meta_value'])
      && '_didit_session_id' === $args['meta_key']
      && $session_id === $args['meta_value']) {
      $matches[] = $id;
    }
  }
  return array_slice($matches, 0, isset($args['number']) ? (int) $args['number'] : count($matches));
}

function get_userdata($user_id) {
  return isset($GLOBALS['didit_test_users'][$user_id]) ? (object) ['ID' => $user_id] : false;
}

function update_user_meta($user_id, $key, $value) {
  $GLOBALS['didit_test_user_meta'][$user_id . '|' . $key] = $value;
}

function delete_user_meta($user_id, $key) {
  unset($GLOBALS['didit_test_user_meta'][$user_id . '|' . $key]);
}

function do_action($hook) {
  $GLOBALS['didit_test_actions'][] = func_get_args();
}

function rest_ensure_response($data) { return $data; }

class WP_Error {
  public $code;
  public $message;
  public $data;
  public function __construct($code = '', $message = '', $data = []) {
    $this->code = $code;
    $this->message = $message;
    $this->data = $data;
  }
  public function get_error_code() { return $this->code; }
  public function get_error_data() { return $this->data; }
}

/**
 * Stand-in for WP_REST_Request with the same header canonicalization WordPress
 * applies (lowercase, dashes to underscores), so 'x_signature_v2' resolves the
 * X-Signature-V2 header exactly as it does in production.
 */
class Didit_Test_Request {
  private $body;
  private $headers = [];

  public function __construct($body, array $headers = []) {
    $this->body = $body;
    foreach ($headers as $name => $value) {
      $this->headers[strtolower(str_replace('-', '_', $name))] = $value;
    }
  }

  public function get_body() { return $this->body; }

  public function get_header($name) {
    $name = strtolower(str_replace('-', '_', $name));
    return isset($this->headers[$name]) ? $this->headers[$name] : null;
  }
}
