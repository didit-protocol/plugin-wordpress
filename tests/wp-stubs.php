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
define('HOUR_IN_SECONDS', 3600);

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
// The logged-in user rest_save_verification() acts for (0 = anonymous).
$GLOBALS['didit_test_current_user_id'] = 0;
// Capabilities the current user holds, e.g. ['manage_options' => true].
$GLOBALS['didit_test_current_user_can'] = [];
// Queued wp_remote_get()/wp_remote_post() responses (FIFO) and the requests that consumed them.
$GLOBALS['didit_test_http_responses'] = [];
$GLOBALS['didit_test_http_requests'] = [];

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

function is_user_logged_in() { return $GLOBALS['didit_test_current_user_id'] > 0; }
function get_current_user_id() { return (int) $GLOBALS['didit_test_current_user_id']; }
function do_shortcode($content) { return (string) $content; }
function wp_json_encode($data) { return json_encode($data); }
function is_wp_error($thing) { return $thing instanceof WP_Error; }

function get_user_meta($user_id, $key, $single = false) {
  $stored = $GLOBALS['didit_test_user_meta'][$user_id . '|' . $key] ?? '';
  return $single ? $stored : [$stored];
}

function wp_remote_get($url, $args = []) {
  $GLOBALS['didit_test_http_requests'][] = ['url' => $url, 'args' => $args];
  $response = array_shift($GLOBALS['didit_test_http_responses']);
  return null === $response ? new WP_Error('http_request_failed', 'no queued response') : $response;
}

function wp_remote_post($url, $args = []) {
  return wp_remote_get($url, $args + ['method' => 'POST']);
}

function wp_unslash($value) { return $value; }
function get_transient($key) { return false; }
function set_transient($key, $value, $expiration = 0) { return true; }
function current_user_can($capability) { return !empty($GLOBALS['didit_test_current_user_can'][$capability]); }

function wp_get_current_user() {
  $id = get_current_user_id();
  return (object) ['ID' => $id, 'user_email' => $id ? "user{$id}@example.test" : ''];
}

function wp_remote_retrieve_response_code($response) {
  return is_array($response) ? ($response['response']['code'] ?? 0) : 0;
}

function wp_remote_retrieve_body($response) {
  return is_array($response) ? ($response['body'] ?? '') : '';
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

  public function get_json_params() { return json_decode($this->body, true); }

  public function get_header($name) {
    $name = strtolower(str_replace('-', '_', $name));
    return isset($this->headers[$name]) ? $this->headers[$name] : null;
  }
}

/**
 * WooCommerce presence marker and the order surface the verification endpoints use.
 * Orders live in $GLOBALS['didit_test_orders'] keyed by id.
 */
class WooCommerce {}

class Didit_Test_Order {
  public $id;
  public $key;
  public $meta = [];
  public $notes = [];
  public function __construct($id, $key, array $meta = []) {
    $this->id = $id;
    $this->key = $key;
    $this->meta = $meta;
  }
  public function get_id() { return $this->id; }
  public function get_order_key() { return $this->key; }
  public function get_meta($key) { return $this->meta[$key] ?? ''; }
  public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
  public function delete_meta_data($key) { unset($this->meta[$key]); }
  public function add_order_note($note) { $this->notes[] = $note; }
  public function has_status($status) { return false; }
  public function save() {}
}

function wc_get_order($order_id) { return $GLOBALS['didit_test_orders'][$order_id] ?? false; }

function wc_get_orders($args) {
  $session_id = $args['meta_query'][0]['value'] ?? null;
  return array_values(array_filter($GLOBALS['didit_test_orders'] ?? [], function ($order) use ($session_id) {
    return null !== $session_id && $order->get_meta('_didit_session_id') === $session_id;
  }));
}
