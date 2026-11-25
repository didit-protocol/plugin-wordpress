<?php
/**
 * Direct checks: AML screening of the billing name above a threshold, and phone and
 * email codes that only reach the contact stored on the order.
 *
 *   php tests/test-actions.php
 */

require __DIR__ . '/wp-stubs-features.php';
require __DIR__ . '/../didit-verify.php';

$opts = &$GLOBALS['didit_test_options'];
$opts['didit_api_key'] = 'test-only-key';

// AML threshold and scheduling.
$small = new Didit_Test_Order(10, ['total' => 49.99]);
$large = new Didit_Test_Order(11, ['total' => 50]);
Didit_Verify_Actions::maybe_schedule_aml($large);
check(!$GLOBALS['didit_test_scheduled'], 'AML screening is off until the admin turns it on');
$opts['didit_aml_enabled'] = true;
$opts['didit_aml_threshold'] = Didit_Verify_Actions::sanitize_amount('50');
Didit_Verify_Actions::maybe_schedule_aml($small);
check(!$GLOBALS['didit_test_scheduled'], 'orders below the threshold are not screened');
Didit_Verify_Actions::maybe_schedule_aml($large);
Didit_Verify_Actions::maybe_schedule_aml($large);
check([['didit_aml_screen_order', [11]]] === $GLOBALS['didit_test_scheduled'], 'an order at the threshold is screened once, in the background');
check(0.0 === Didit_Verify_Actions::sanitize_amount('-5'), 'a negative threshold is stored as zero');

$k1 = Didit_Verify_Actions::idempotency_key(11, 'Jane Doe');
check($k1 === Didit_Verify_Actions::idempotency_key(11, 'Jane Doe') && 64 === strlen($k1), 'a retried screening reuses its idempotency key');
check($k1 !== Didit_Verify_Actions::idempotency_key(12, 'Jane Doe') && $k1 !== Didit_Verify_Actions::idempotency_key(11, 'Jane Doe', 1),
  'another order or an explicit re-run gets a new key');

$GLOBALS['didit_test_post_response'] = function ($url, $args) {
  return ['response' => ['code' => 200], 'body' => json_encode(['request_id' => 'r1', 'aml' => [
    'status' => 'In Review', 'total_hits' => 1, 'score' => 80,
    'hits' => [['caption' => 'Jane Doe', 'match_score' => 92, 'sanction_matches' => [['x' => 1]]]],
  ]])];
};
$opts['didit_aml_monitoring'] = true;
$opts['didit_aml_hold'] = true;
$GLOBALS['didit_test_api_posts'] = [];
Didit_Verify_Actions::screen_order(11);
$sent = $GLOBALS['didit_test_api_posts'][0];
$body = json_decode($sent['args']['body'], true);
check('https://verification.didit.me/v3/aml/' === $sent['url'] && 'Jane Doe' === $body['full_name'] && true === $body['include_ongoing_monitoring'],
  'the billing name is screened with ongoing monitoring when chosen');
check($k1 === $sent['args']['headers']['Idempotency-Key'] && $sent['args']['timeout'] >= 60, 'the screening sends its idempotency key and waits long enough');
check('In Review' === $large->get_meta('_didit_aml')['status'] && $large->get_meta('_didit_aml')['hits'][0]['sanction'], 'the result is stored on the order');
check('on-hold' === $large->status, 'an order whose screening needs review is put on hold when chosen');
check(false === strpos(json_encode($large->meta), 'test-only-key'), 'the API key is never stored on the order');

$GLOBALS['didit_test_post_response'] = function () { return new WP_Error('offline'); };
$failing = new Didit_Test_Order(13, ['total' => 500]);
Didit_Verify_Actions::screen_order(13);
check('' === $failing->get_meta('_didit_aml') && false !== strpos(end($failing->notes), 'could not run'), 'a network failure leaves a note and no result');

$opts['didit_api_key'] = 'wrong-key';
$GLOBALS['didit_test_post_response'] = function () { return ['response' => ['code' => 403], 'body' => '{"detail":"bad key"}']; };
Didit_Verify_Actions::screen_order(13);
check(false !== strpos(end($failing->notes), 'rejected the API key') && false === strpos(end($failing->notes), 'wrong-key'),
  'a wrong API key is reported without revealing it');
$opts['didit_api_key'] = 'test-only-key';

// Phone and email codes.
check('+34600000000' === Didit_Verify_Actions::contact_value(new Didit_Test_Order(20), 'phone'), 'an international number is kept');
check('+34600000000' === Didit_Verify_Actions::contact_value(new Didit_Test_Order(21, ['phone' => '0034 600 000 000']), 'phone'), 'a 00 prefix becomes +');
check('' === Didit_Verify_Actions::contact_value(new Didit_Test_Order(22, ['phone' => '600 000 000']), 'phone'), 'a national number without a known calling code is not guessed');

$order = new Didit_Test_Order(30);
function contact($step, array $input, $nonce = 'test-nonce-wp_rest') {
  $request = new Didit_Test_Request(json_encode($input), ['X-WP-Nonce' => $nonce]);
  $allowed = Didit_Verify_Actions::contact_permission($request);
  if (true !== $allowed) {
    return $allowed;
  }
  return call_user_func(['Didit_Verify_Actions', 'rest_contact_' . $step], $request);
}
$valid = ['order_id' => 30, 'order_key' => 'wc_order_testkey', 'channel' => 'phone'];
check(is_wp_error(contact('send', ['order_key' => 'wrong'] + $valid)), 'a wrong order key is refused');
check(is_wp_error(contact('send', $valid, 'bad-nonce')), 'a request without the page nonce is refused');
$result = contact('send', $valid);
check(is_wp_error($result) && 'didit_channel_disabled' === $result->get_error_code(), 'phone codes are off until the admin turns them on');

$opts['didit_contact_phone'] = true;
$GLOBALS['didit_test_api_posts'] = [];
$GLOBALS['didit_test_post_response'] = function ($url) {
  return ['response' => ['code' => 200], 'body' => json_encode(false !== strpos($url, '/send/') ? ['status' => 'Success'] : ['status' => $GLOBALS['code_result']])];
};
$result = contact('send', $valid + ['phone_number' => '+15550000000']);
$body = json_decode($GLOBALS['didit_test_api_posts'][0]['args']['body'], true);
check(['status' => 'Sent'] === $result && '+34600000000' === $body['phone_number'], 'the code goes to the phone on the order, never a number from the browser');
$GLOBALS['code_result'] = 'Declined';
check(is_wp_error(contact('check', $valid + ['code' => '000000'])) && '' === $order->get_meta('_didit_phone_status'), 'a wrong code does not verify the phone');
$GLOBALS['code_result'] = 'Approved';
check(['status' => 'Approved'] === contact('check', $valid + ['code' => '123456']) && 'Approved' === $order->get_meta('_didit_phone_status'),
  'the right code verifies the phone');
new Didit_Test_Order(31);
$GLOBALS['didit_test_transients'] = [];
for ($i = 0; $i < 5; $i++) {
  contact('send', ['order_id' => 31] + $valid);
}
$result = contact('send', ['order_id' => 31] + $valid);
check(is_wp_error($result) && 429 === $result->get_error_data()['status'], 'codes per order are rate limited');

echo $GLOBALS['didit_test_passed'] . " passed\n";
