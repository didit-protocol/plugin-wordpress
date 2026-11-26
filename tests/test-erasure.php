<?php
/**
 * The personal-data eraser removes every Didit result for a person: superseded sessions,
 * results on their orders (guest orders included) and the plugin's notes on them.
 *
 *   php tests/test-erasure.php
 */

require __DIR__ . '/wp-stubs-features.php';
require __DIR__ . '/../didit-verify.php';

if (!function_exists('maybe_unserialize')) {
  function maybe_unserialize($value) { return $value; }
}
if (!function_exists('wc_get_orders')) {
  function wc_get_orders($args) {
    $GLOBALS['didit_test_order_queries'][] = $args;
    $customers = (array) $args['customer'];
    $found = array_values(array_filter($GLOBALS['didit_test_orders'], function ($order) use ($customers) {
      return in_array($order->get_billing_email(), $customers, true) || in_array($order->get_customer_id(), $customers, true);
    }));
    return array_slice($found, ($args['paged'] - 1) * $args['limit'], $args['limit']);
  }
}

/** Just enough of $wpdb for the eraser: options by prefix and usermeta keys by prefix. */
class Didit_Test_Wpdb {
  public $options = 'wp_options';
  public $usermeta = 'wp_usermeta';
  public function esc_like($text) { return $text; }
  public function prepare($sql, ...$args) { return ['sql' => $sql, 'args' => $args]; }
  public function get_results($query) {
    list($prefix, $after) = [rtrim($query['args'][0], '%'), $query['args'][1]];
    $rows = [];
    $names = array_keys($GLOBALS['didit_test_options']);
    sort($names, SORT_STRING);
    foreach ($names as $name) {
      if (0 === strpos($name, $prefix) && strcmp($name, $after) > 0) {
        $rows[] = (object) ['option_name' => $name, 'option_value' => $GLOBALS['didit_test_options'][$name]];
      }
    }
    return array_slice($rows, 0, 500);
  }
  public function get_col($query) {
    list($user_id, $prefix) = [$query['args'][0], rtrim($query['args'][1], '%')];
    $keys = [];
    foreach (array_keys($GLOBALS['didit_test_user_meta']) as $key) {
      list($id, $meta_key) = explode('|', $key, 2);
      if ((int) $id === $user_id && 0 === strpos($meta_key, $prefix)) {
        $keys[] = $meta_key;
      }
    }
    return $keys;
  }
}
$GLOBALS['wpdb'] = new Didit_Test_Wpdb();

$plugin = Didit_Verify::init();
$owner = str_repeat('b', 64);
$GLOBALS['didit_test_wp_users'] = [(object) ['ID' => 42, 'user_email' => 'jane@example.test', 'user_login' => 'jane']];

$current = '11111111-2222-4222-8222-000000000001';
$superseded = '11111111-2222-4222-8222-000000000002';
$guest_order_session = '11111111-2222-4222-8222-000000000003';
$someone_else = '11111111-2222-4222-8222-000000000004';
$plugin->store_session($current, ['owner' => $owner, 'user_id' => 42, 'order_id' => 0, 'status' => 'Approved']);
$plugin->store_session($superseded, ['owner' => $owner, 'user_id' => 42, 'order_id' => 0, 'status' => 'Declined', 'report' => ['checks' => []]]);
$plugin->store_session($guest_order_session, ['owner' => str_repeat('c', 64), 'user_id' => 0, 'order_id' => 301, 'status' => 'Approved']);
$plugin->store_session($someone_else, ['owner' => str_repeat('d', 64), 'user_id' => 7, 'order_id' => 0, 'status' => 'Approved']);
update_option('didit_approved_' . $owner, ['wf-1' => $current]);
update_option('didit_resume_' . $owner, $superseded);
foreach (['_didit_confirmed_session_id' => $current, '_didit_status' => 'Approved', '_didit_report' => ['checks' => []],
  '_didit_workflow_sessions' => ['wf-1' => $current], '_didit_auth_link_1' => 'hash', '_didit_auth_sub' => 'sub'] as $key => $value) {
  update_user_meta(42, $key, $value);
}
update_user_meta(7, '_didit_status', 'Approved');

$guest = new Didit_Test_Order(301, ['email' => 'jane@example.test', 'customer_id' => 0]);
$guest->meta = ['_didit_session_id' => $guest_order_session, '_didit_status' => 'Approved', '_didit_report' => ['checks' => []],
  '_didit_aml' => ['hits' => 1], '_didit_phone_status' => 'Approved', '_didit_phone_verified_hash' => 'hash', '_billing_note' => 'merchant'];
$other = new Didit_Test_Order(302, ['email' => 'other@example.test', 'customer_id' => 7]);
$other->meta = ['_didit_status' => 'Approved'];
$GLOBALS['didit_test_comment_meta'][901] = ['_didit_result_note' => 1];
$GLOBALS['didit_test_comment_post'][901] = 301;
// Unmarked notes of 0.3.x: two plugin notes and a merchant note mentioning Didit.
$GLOBALS['didit_test_comment_objects'] = [
  (object) ['comment_ID' => 902, 'comment_post_ID' => 301, 'comment_author' => 'WooCommerce', 'comment_content' => 'Didit: verification session created (11111111-2222-4222-8222-000000000003).'],
  (object) ['comment_ID' => 903, 'comment_post_ID' => 301, 'comment_author' => 'WooCommerce', 'comment_content' => 'Didit: verification reminder 1 of 3 sent to jane@example.test.'],
  (object) ['comment_ID' => 904, 'comment_post_ID' => 301, 'comment_author' => 'Shop Manager', 'comment_content' => 'Didit: called the customer, all fine.'],
  (object) ['comment_ID' => 905, 'comment_post_ID' => 302, 'comment_author' => 'WooCommerce', 'comment_content' => 'Didit: verification status "Approved" (via webhook).'],
];

$result = $plugin->erase_personal_data('jane@example.test', 1);
check(true === $result['items_removed'] && true === $result['done'], 'the eraser reports what it removed and finishes');
check(false === $plugin->stored_session($current) && false === $plugin->stored_session($superseded), 'current and superseded sessions of the account are deleted');
check(false === $plugin->stored_session($guest_order_session), 'the session of a guest order placed with the email is deleted');
check(is_array($plugin->stored_session($someone_else)), 'another person\'s session is kept');
check(false === get_option('didit_approved_' . $owner, false) && false === get_option('didit_resume_' . $owner, false), 'the browser pointers to erased sessions are removed');
foreach (['_didit_confirmed_session_id', '_didit_status', '_didit_report', '_didit_workflow_sessions', '_didit_auth_link_1', '_didit_auth_sub'] as $key) {
  check('' === get_user_meta(42, $key), "user meta $key is deleted");
}
check('Approved' === get_user_meta(7, '_didit_status'), 'another user\'s status is kept');
check(['_billing_note' => 'merchant'] === $guest->meta, 'every Didit result on the guest order is deleted, merchant data is kept');
check(['_didit_status' => 'Approved'] === $other->meta, 'orders of other people are untouched');
check(in_array(901, $GLOBALS['didit_test_deleted_comments'] ?? [], true), 'the plugin\'s result notes on the order are deleted');
check(in_array(902, $GLOBALS['didit_test_deleted_comments'], true) && in_array(903, $GLOBALS['didit_test_deleted_comments'], true),
  'unmarked notes of earlier versions (session IDs, reminder recipients) are deleted');
check(!in_array(904, $GLOBALS['didit_test_deleted_comments'], true) && !in_array(905, $GLOBALS['didit_test_deleted_comments'], true),
  'a merchant\'s own note and notes of other people\'s orders are kept');
check(['jane@example.test', 42] === $GLOBALS['didit_test_order_queries'][0]['customer'], 'orders are found by email and by account');

// Multisite: the link of another site was not part of this site's request.
update_user_meta(42, '_didit_auth_link_1', 'hash');
update_user_meta(42, '_didit_auth_link_2', 'hash-of-site-2');
$plugin->erase_personal_data('jane@example.test', 1);
check('' === get_user_meta(42, '_didit_auth_link_1') && 'hash-of-site-2' === get_user_meta(42, '_didit_auth_link_2'),
  'erasure on one site deletes only that site\'s sign-in link');

// Shared browser: a later guest's pointers survive erasing an earlier customer's session.
$shared = str_repeat('e', 64);
$janes = '11111111-2222-4222-8222-000000000005';
$later_guest = '11111111-2222-4222-8222-000000000006';
$plugin->store_session($janes, ['owner' => $shared, 'user_id' => 42, 'order_id' => 0, 'status' => 'Approved']);
$plugin->store_session($later_guest, ['owner' => $shared, 'user_id' => 0, 'order_id' => 0, 'status' => 'Approved', 'placement' => 'registration']);
update_option('didit_resume_' . $shared, $later_guest);
update_option('didit_registration_' . $shared, $later_guest);
update_option('didit_approved_' . $shared, ['wf-1' => $janes, 'wf-2' => $later_guest]);
$plugin->erase_personal_data('jane@example.test', 1);
check(false === $plugin->stored_session($janes) && is_array($plugin->stored_session($later_guest)), 'only the erased customer\'s session is deleted');
check($later_guest === get_option('didit_resume_' . $shared) && $later_guest === get_option('didit_registration_' . $shared),
  'the browser\'s pointers to another customer\'s session are kept');
check(['wf-2' => $later_guest] === get_option('didit_approved_' . $shared), 'only the approval of the erased session is removed from the browser');

// Large stores: each eraser page scans one bounded batch and resumes where it stopped.
for ($n = 0; $n < 600; $n++) {
  $plugin->store_session(sprintf('22222222-2222-4222-8222-%012d', $n), ['owner' => str_repeat('f', 64), 'user_id' => 9, 'order_id' => 0, 'status' => 'Approved']);
}
$last_mine = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
$plugin->store_session($last_mine, ['owner' => $owner, 'user_id' => 42, 'order_id' => 0, 'status' => 'Approved']);
$names = array_filter(array_keys($GLOBALS['didit_test_options']), function ($n) { return 0 === strpos($n, 'didit_bound_'); });
sort($names, SORT_STRING);
$position = array_search($plugin->session_key($last_mine), $names, true);
$pages = 0;
do {
  $pages++;
  $result = $plugin->erase_personal_data('jane@example.test', $pages);
} while (!$result['done'] && $pages < 10);
check($pages === intdiv($position, 500) + 1 + (count($names) > 500 * (intdiv($position, 500) + 1) ? 1 : 0) || ($pages >= 2 && $result['done']),
  'a store with more sessions than one batch is erased over several pages');
check(false === $plugin->stored_session($last_mine) && is_array($plugin->stored_session('22222222-2222-4222-8222-000000000000')),
  'the session found in a later batch is deleted, others are kept');

$GLOBALS['didit_test_wp_users'] = [];
$result = $plugin->erase_personal_data('nobody@example.test', 1);
check(false === $result['items_removed'] && true === $result['done'], 'an email with nothing stored finishes without removing anything');

echo $GLOBALS['didit_test_passed'] . " passed\n";
