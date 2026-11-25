<?php
/**
 * Extra WordPress and WooCommerce stand-ins for the 0.4.0 features (placements,
 * reports, direct checks and sign-in). Loaded after wp-stubs.php.
 */

require_once __DIR__ . '/wp-stubs.php';

$GLOBALS['didit_test_post_meta'] = [];
$GLOBALS['didit_test_terms'] = [];
$GLOBALS['didit_test_orders'] = [];
$GLOBALS['didit_test_notices'] = [];
$GLOBALS['didit_test_scheduled'] = [];
$GLOBALS['didit_test_wp_users'] = [];
$GLOBALS['didit_test_caps'] = [];
$GLOBALS['didit_test_cookies_set'] = [];

function _n($single, $plural, $number) { return 1 === (int) $number ? $single : $plural; }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
function esc_attr__($text) { return $text; }
function sanitize_email($email) { $email = trim((string) $email); return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''; }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_user($user) { return preg_replace('/[^A-Za-z0-9_.\-@]/', '', (string) $user); }
function delete_transient($key) { unset($GLOBALS['didit_test_transients'][$key]); }
function delete_option($key) { unset($GLOBALS['didit_test_options'][$key]); }
function wp_verify_nonce($nonce, $action) { return 'test-nonce-' . $action === $nonce; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['didit_test_post_meta'][$id][$key] ?? ''; }
function wp_get_post_parent_id($id) { return 0; }
function has_term($terms, $taxonomy, $id) { return (bool) array_intersect((array) $terms, $GLOBALS['didit_test_terms'][$id] ?? []); }
function wc_add_notice($message, $type = 'success') { $GLOBALS['didit_test_notices'][] = [$type, $message]; }
function wc_get_order($id) { return is_object($id) ? $id : ($GLOBALS['didit_test_orders'][(int) $id] ?? false); }
function as_enqueue_async_action($hook, $args = [], $group = '') { $GLOBALS['didit_test_scheduled'][] = [$hook, $args]; }
function site_url($path = '', $scheme = null) { return 'https://example.test/' . ltrim($path, '/'); }
function user_can($user, $cap) { return in_array($cap, $GLOBALS['didit_test_caps'][is_object($user) ? $user->ID : $user] ?? [], true); }
function is_super_admin($id = 0) { return false; }
function get_user_by($field, $value) {
  foreach ($GLOBALS['didit_test_wp_users'] as $user) {
    if ('email' === $field && strtolower($user->user_email) === strtolower($value)) { return $user; }
  }
  return false;
}
function username_exists($login) {
  foreach ($GLOBALS['didit_test_wp_users'] as $user) { if ($user->user_login === $login) { return $user->ID; } }
  return false;
}
function wp_generate_password() { return 'generated-test-password'; }
function wp_insert_user($data) {
  $id = 1000 + count($GLOBALS['didit_test_wp_users']);
  $GLOBALS['didit_test_wp_users'][$id] = (object) ['ID' => $id, 'user_login' => $data['user_login'], 'user_email' => $data['user_email']];
  $GLOBALS['didit_test_users'][$id] = '';
  return $id;
}

/** In-memory WooCommerce order with the getters the plugin reads. */
class Didit_Test_Order {
  public $id;
  public $meta = [];
  public $notes = [];
  public $status = 'processing';
  public $data = [];
  public function __construct($id, array $data = []) {
    $this->id = $id;
    $this->data = array_merge([
      'first_name' => 'Jane', 'last_name' => 'Doe', 'company' => '', 'email' => 'jane@example.test',
      'phone' => '+34 600 000 000', 'country' => 'ES', 'total' => 100, 'customer_id' => 0, 'key' => 'wc_order_testkey',
    ], $data);
    $GLOBALS['didit_test_orders'][$id] = $this;
  }
  public function get_id() { return $this->id; }
  public function get_meta($key) { return $this->meta[$key] ?? ''; }
  public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
  public function delete_meta_data($key) { unset($this->meta[$key]); }
  public function save() {}
  public function add_order_note($note) { $this->notes[] = $note; }
  public function get_billing_first_name() { return $this->data['first_name']; }
  public function get_billing_last_name() { return $this->data['last_name']; }
  public function get_billing_company() { return $this->data['company']; }
  public function get_billing_email() { return $this->data['email']; }
  public function get_billing_phone() { return $this->data['phone']; }
  public function get_billing_country() { return $this->data['country']; }
  public function get_total() { return $this->data['total']; }
  public function get_customer_id() { return $this->data['customer_id']; }
  public function get_order_key() { return $this->data['key']; }
  public function has_status($statuses) { return in_array($this->status, (array) $statuses, true); }
  public function update_status($status, $note = '') { $this->status = $status; $this->notes[] = $note; }
}

function didit_test_internal($name, ...$args) {
  $method = new ReflectionMethod(Didit_Verify::class, $name);
  if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
  return $method->invoke(Didit_Verify::init(), ...$args);
}

$GLOBALS['didit_test_passed'] = 0;
function check($condition, $label) {
  if (!$condition) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
  $GLOBALS['didit_test_passed']++;
  echo "PASS $label\n";
}
