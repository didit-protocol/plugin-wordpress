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

// Automatic jobs recheck the setting when they run; an explicit admin rerun always runs.
$GLOBALS['didit_test_post_response'] = function () {
  return ['response' => ['code' => 200], 'body' => json_encode(['aml' => ['status' => 'Approved', 'total_hits' => 0]])];
};
$queued = new Didit_Test_Order(14, ['total' => 500]);
$opts['didit_aml_enabled'] = false;
$GLOBALS['didit_test_api_posts'] = [];
Didit_Verify_Actions::screen_order(14);
check(!$GLOBALS['didit_test_api_posts'] && '' === $queued->get_meta('_didit_aml'), 'a queued automatic screening does not run after automatic AML is turned off');
$opts['didit_aml_enabled'] = true;
$opts['didit_aml_threshold'] = 1000.0;
Didit_Verify_Actions::screen_order(14);
check(!$GLOBALS['didit_test_api_posts'], 'a queued automatic screening does not run when the threshold was raised above the order total');
$opts['didit_aml_enabled'] = false;
Didit_Verify_Actions::order_action_screen($queued);
check(1 === count($GLOBALS['didit_test_api_posts']) && 'Approved' === $queued->get_meta('_didit_aml')['status'], 'an admin rerun from the order actions still runs');
$opts['didit_aml_enabled'] = true;
$opts['didit_aml_threshold'] = 50.0;

/** Order whose notes get comment IDs and whose creation date can be set. */
class Didit_Test_Noted_Order extends Didit_Test_Order {
  public static $next_note = 500;
  public $note_ids = [];
  public $created = null;
  public function add_order_note($note) {
    $this->notes[] = $note;
    $id = ++self::$next_note;
    $this->note_ids[$id] = $note;
    $GLOBALS['didit_test_comment_post'][$id] = $this->id;
    return $id;
  }
  public function get_date_created() { return $this->created; }
}
function aml_result($status) {
  $GLOBALS['didit_test_post_response'] = function () use ($status) {
    return ['response' => ['code' => 200], 'body' => json_encode(['aml' => ['status' => $status, 'total_hits' => 'Approved' === $status ? 0 : 1]])];
  };
}

// AML holds are tracked independently of identity holds.
$plugin = Didit_Verify::init();
$opts['didit_wc_mode'] = 'after_purchase';
$opts['didit_wc_hold'] = true;
$both = new Didit_Test_Noted_Order(15, ['total' => 500]);
$both->meta['_didit_requires_verification'] = 'yes';
$both->meta['_didit_session_id'] = 'sess-15';
$plugin->wc_maybe_hold_order(15, 'pending', 'processing', $both);
check('on-hold' === $both->status && ['identity'] === Didit_Verify_Actions::hold_reasons($both), 'an unverified paid order is held for identity');
aml_result('Declined');
Didit_Verify_Actions::screen_order(15);
check('on-hold' === $both->status && ['identity', 'aml'] === Didit_Verify_Actions::hold_reasons($both), 'an AML decline on an order already on hold is still recorded as a hold reason');
didit_test_internal('wc_apply_verification_to_order', $both, 'Approved', 'verified-server');
check('on-hold' === $both->status && ['aml'] === Didit_Verify_Actions::hold_reasons($both), 'identity approval does not release an order with an outstanding AML hold');
aml_result('Approved');
Didit_Verify_Actions::order_action_screen($both);
check('processing' === $both->status && !Didit_Verify_Actions::hold_reasons($both) && '' === $both->get_meta('_didit_held'),
  'once the AML screening clears too, the order is released');

$virtual = new Didit_Test_Noted_Order(16, ['total' => 500]);
$virtual->status = 'completed';
aml_result('In Review');
Didit_Verify_Actions::screen_order(16);
check('on-hold' === $virtual->status && 'completed' === $virtual->get_meta('_didit_held_from'), 'an AML result after an order auto-completed still holds it');
aml_result('Approved');
Didit_Verify_Actions::order_action_screen($virtual);
check('completed' === $virtual->status, 'releasing it restores the status it was held from');

$unpaid = new Didit_Test_Noted_Order(17, ['total' => 500]);
$unpaid->status = 'pending';
aml_result('Declined');
Didit_Verify_Actions::screen_order(17);
check('pending' === $unpaid->status && ['aml'] === Didit_Verify_Actions::hold_reasons($unpaid), 'an unpaid order keeps its status and records the AML reason');
$unpaid->status = 'processing';
Didit_Verify_Actions::enforce_holds(17, 'pending', 'processing', $unpaid);
check('on-hold' === $unpaid->status, 'payment then moves the order to on-hold instead of processing');
$unpaid->status = 'processing'; // The merchant releases the hold by hand.
Didit_Verify_Actions::enforce_holds(17, 'on-hold', 'processing', $unpaid);
$plugin->wc_maybe_hold_order(17, 'on-hold', 'processing', $unpaid);
check('processing' === $unpaid->status && !Didit_Verify_Actions::hold_reasons($unpaid) && 'yes' === $unpaid->get_meta('_didit_hold_released'),
  'a manual release by the merchant is respected and not fought');
$unpaid->meta['_didit_requires_verification'] = 'yes';
$unpaid->status = 'completed';
$plugin->wc_maybe_hold_order(17, 'processing', 'completed', $unpaid);
check('completed' === $unpaid->status, 'a manually released order is not held again for identity');

$gateway = new Didit_Test_Noted_Order(18, ['total' => 500]);
$gateway->status = 'on-hold'; // Awaiting a bank transfer, not held by Didit.
aml_result('Declined');
Didit_Verify_Actions::screen_order(18);
$gateway->status = 'processing';
Didit_Verify_Actions::enforce_holds(18, 'on-hold', 'processing', $gateway);
check('on-hold' === $gateway->status, 'a payment confirmation out of a gateway hold is still gated on the AML reason');
$opts['didit_wc_mode'] = '';
$opts['didit_wc_hold'] = false;

// Result notes are marked so they can be erased without touching merchant notes.
$merchant_note = $both->add_order_note('Merchant: called the customer.');
$marked = array_keys($GLOBALS['didit_test_comment_meta']);
check(in_array(max(array_keys($both->note_ids)) - 1, $marked, true) && !isset($GLOBALS['didit_test_comment_meta'][$merchant_note]),
  'Didit result notes are marked and merchant notes are not');
$before = count(array_filter($marked, function ($id) { return 15 === $GLOBALS['didit_test_comment_post'][$id]; }));
check($before > 0 && $before === Didit_Verify_Actions::delete_result_notes(15) && !in_array($merchant_note, $GLOBALS['didit_test_deleted_comments'], true)
  && 'order_note' === $GLOBALS['didit_test_comments_query']['type'], 'erasing an order removes exactly its marked result notes');
check(isset($GLOBALS['didit_test_comment_meta'][array_keys($virtual->note_ids)[0]]), 'result notes of other orders are kept');

// Phone and email codes.
check('+34600000000' === Didit_Verify_Actions::contact_value(new Didit_Test_Order(20), 'phone'), 'an international number is kept');
check('+34600000000' === Didit_Verify_Actions::contact_value(new Didit_Test_Order(21, ['phone' => '0034 600 000 000']), 'phone'), 'a 00 prefix becomes +');
check('' === Didit_Verify_Actions::contact_value(new Didit_Test_Order(22, ['phone' => '600 000 000']), 'phone'), 'a national number without a known calling code is not guessed');
check('+390612345678' === Didit_Verify_Actions::phone_e164('06 1234 5678', 'IT', '+39'), 'an Italian landline keeps its leading 0');
check('+447911123456' === Didit_Verify_Actions::phone_e164('07911 123456', 'GB', '+44'), 'a UK trunk 0 is dropped');
check('+34600000000' === Didit_Verify_Actions::phone_e164('600 000 000', 'ES', '+34'), 'a Spanish national number gets its country code');
check('+14155552671' === Didit_Verify_Actions::phone_e164('(415) 555-2671', 'US', '+1'), 'a US number gets +1');
check('' === Didit_Verify_Actions::phone_e164('0600 000 000', 'ES', '+34'), 'a leading 0 where none is expected is refused');
check('' === Didit_Verify_Actions::phone_e164('0612 345 678', 'BR', '+55'), 'a national number from a country without a known rule is refused');

function contact($step, array $input, $nonce = 'test-nonce-wp_rest') {
  $request = new Didit_Test_Request(json_encode($input), ['X-WP-Nonce' => $nonce]);
  $allowed = Didit_Verify_Actions::contact_permission($request);
  if (true !== $allowed) {
    return $allowed;
  }
  return call_user_func(['Didit_Verify_Actions', 'rest_contact_' . $step], $request);
}
function access_for($order) {
  return 'test-nonce-didit_contact_' . $order->get_id() . '|' . $order->get_order_key();
}

// Contact details only where WooCommerce shows the order details.
$opts['didit_contact_phone'] = true;
$GLOBALS['didit_test_current_user'] = 0;
$_GET['key'] = 'wc_order_testkey';
$old = new Didit_Test_Noted_Order(25);
$old->created = new DateTime('-2 hours');
check('' === Didit_Verify_Actions::contact_box($old), 'a guest with the order key but without WooCommerce email verification sees no contacts');
$account = new Didit_Test_Noted_Order(26, ['customer_id' => 7]);
$account->created = new DateTime();
check('' === Didit_Verify_Actions::contact_box($account), 'an order placed with an account is hidden from anyone but its logged-in owner');
$GLOBALS['didit_test_current_user'] = 7;
unset($_GET['key']);
check('' !== Didit_Verify_Actions::contact_box($account), 'its logged-in owner sees the contacts');
$GLOBALS['didit_test_current_user'] = 0;
$_GET['key'] = 'wrong';
$order = new Didit_Test_Noted_Order(30);
$order->created = new DateTime();
check('' === Didit_Verify_Actions::contact_box($order), 'a wrong key in the URL shows nothing');
$_GET['key'] = 'wc_order_testkey';
$box = Didit_Verify_Actions::contact_box($order);
$cfg = $GLOBALS['didit_test_localized_scripts']['didit-contact']['diditContact'];
check('' !== $box && access_for($order) === $cfg['access'], 'a guest within WooCommerce\'s grace period sees the box and gets an access token');
unset($_GET['key']);

$valid = ['order_id' => 30, 'order_key' => 'wc_order_testkey', 'channel' => 'phone', 'access' => $cfg['access']];
check(is_wp_error(contact('send', ['order_key' => 'wrong'] + $valid)), 'a wrong order key is refused');
check(is_wp_error(contact('send', $valid, 'bad-nonce')), 'a request without the page nonce is refused');
check(is_wp_error(contact('send', ['access' => ''] + $valid)), 'the order key alone, without the access token from an authorized page, is refused');
check(is_wp_error(contact('send', ['access' => access_for($old)] + $valid)), 'an access token for another order is refused');
check(is_wp_error(contact('send', ['order_id' => 26, 'access' => access_for($account)] + $valid)), 'an order with an account needs its logged-in owner');
$opts['didit_contact_phone'] = false;
$result = contact('send', $valid);
check(is_wp_error($result) && 'didit_channel_disabled' === $result->get_error_code(), 'phone codes are off until the admin turns them on');

$opts['didit_contact_phone'] = true;
$GLOBALS['didit_test_transients'] = [];
$GLOBALS['didit_test_api_posts'] = [];
$GLOBALS['send_result'] = 'Success';
$GLOBALS['didit_test_post_response'] = function ($url) {
  return ['response' => ['code' => 200], 'body' => json_encode(false !== strpos($url, '/send/') ? ['status' => $GLOBALS['send_result']] : ['status' => $GLOBALS['code_result']])];
};
$result = contact('send', $valid + ['phone_number' => '+15550000000']);
$body = json_decode($GLOBALS['didit_test_api_posts'][0]['args']['body'], true);
check(['status' => 'Sent'] === $result && '+34600000000' === $body['phone_number'], 'the code goes to the phone on the order, never a number from the browser');
$GLOBALS['send_result'] = 'Retry';
check(['status' => 'Sent'] === contact('send', $valid), 'a resend that Didit answers with Retry is reported as sent');
$GLOBALS['send_result'] = 'Blocked';
check(is_wp_error(contact('send', $valid)), 'other send results are reported as not sent');
$GLOBALS['code_result'] = 'Declined';
check(is_wp_error(contact('check', $valid + ['code' => '000000'])) && '' === $order->get_meta('_didit_phone_status'), 'a wrong code does not verify the phone');
$GLOBALS['code_result'] = 'Approved';
check(['status' => 'Approved'] === contact('check', $valid + ['code' => '123456']) && Didit_Verify_Actions::contact_approved($order, 'phone'),
  'the right code verifies the phone');
check(isset($GLOBALS['didit_test_comment_meta'][max(array_keys($order->note_ids))]), 'the contact result note is marked as a Didit result');

// The approval belongs to the number that received the code.
$order->data['phone'] = '+34 611 111 111';
check(!Didit_Verify_Actions::contact_approved($order, 'phone'), 'after the merchant changes the phone the approval no longer applies');
$_GET['key'] = 'wc_order_testkey';
$order2 = new Didit_Test_Noted_Order(32, ['phone' => '+34 622 222 222']);
$order2->created = new DateTime();
$order2->meta['_didit_phone_status'] = 'Approved';
$order2->meta['_didit_phone_verified_hash'] = Didit_Verify_Actions::contact_hash('phone', '+34600000000');
$box = Didit_Verify_Actions::contact_box($order2);
check(false === strpos($box, '✓') && false !== strpos($box, 'didit-contact-send'), 'a changed contact is not shown as verified and can get a new code');
unset($_GET['key']);
$result = contact('check', $valid + ['code' => '123456']);
check(is_wp_error($result) && 'didit_code_stale' === $result->get_error_code(), 'a code sent to the old number cannot verify the new one');
$GLOBALS['send_result'] = 'Success';
$GLOBALS['didit_test_api_posts'] = [];
check(['status' => 'Sent'] === contact('send', $valid) && '+34611111111' === json_decode($GLOBALS['didit_test_api_posts'][0]['args']['body'], true)['phone_number'],
  'the new number gets a fresh code');

// A phone that cannot be converted safely is not messaged.
$national = new Didit_Test_Noted_Order(33, ['phone' => '612 345 678', 'country' => 'XX']);
$GLOBALS['didit_test_api_posts'] = [];
$result = contact('send', ['order_id' => 33, 'access' => access_for($national)] + $valid);
check(is_wp_error($result) && 'didit_phone_not_international' === $result->get_error_code() && !$GLOBALS['didit_test_api_posts'],
  'an ambiguous national number asks for an international number and sends nothing');

// Limits per order, per recipient across orders and per requester.
$GLOBALS['didit_test_transients'] = [];
new Didit_Test_Order(31);
for ($i = 0; $i < 5; $i++) {
  contact('send', ['order_id' => 31, 'access' => 'test-nonce-didit_contact_31|wc_order_testkey'] + $valid);
}
$result = contact('send', ['order_id' => 31, 'access' => 'test-nonce-didit_contact_31|wc_order_testkey'] + $valid);
check(is_wp_error($result) && 429 === $result->get_error_data()['status'], 'codes per order are rate limited');
$GLOBALS['didit_test_transients'] = [];
$GLOBALS['didit_test_api_posts'] = [];
for ($id = 40; $id < 46; $id++) {
  new Didit_Test_Order($id);
  $result = contact('send', ['order_id' => $id, 'access' => 'test-nonce-didit_contact_' . $id . '|wc_order_testkey'] + $valid);
}
check(is_wp_error($result) && 429 === $result->get_error_data()['status'] && 5 === count($GLOBALS['didit_test_api_posts']),
  'new orders for the same recipient share one budget and no extra paid message is sent');
$GLOBALS['didit_test_transients'] = [];
$GLOBALS['didit_test_api_posts'] = [];
for ($id = 50; $id < 61; $id++) {
  new Didit_Test_Order($id, ['phone' => '+34 6000000' . $id]);
  $result = contact('send', ['order_id' => $id, 'access' => 'test-nonce-didit_contact_' . $id . '|wc_order_testkey'] + $valid);
}
check(is_wp_error($result) && 429 === $result->get_error_data()['status'] && 10 === count($GLOBALS['didit_test_api_posts']),
  'one requester cannot message many recipients through many orders');
check(!array_key_exists('didit_contact_lock', $opts), 'the budget lock is released after each request');
$GLOBALS['didit_test_transients'] = [];
$GLOBALS['didit_test_api_posts'] = [];
$opts['didit_contact_lock'] = (string) time();
$result = contact('send', ['order_id' => 31, 'access' => 'test-nonce-didit_contact_31|wc_order_testkey'] + $valid);
check(is_wp_error($result) && 429 === $result->get_error_data()['status'] && !$GLOBALS['didit_test_api_posts'], 'a request that cannot take the budget lock is refused');
$opts['didit_contact_lock'] = (string) (time() - 60);
check(['status' => 'Sent'] === contact('send', ['order_id' => 31, 'access' => 'test-nonce-didit_contact_31|wc_order_testkey'] + $valid), 'a stale lock left by a crashed request is taken over');
unset($opts['didit_contact_lock']);

echo $GLOBALS['didit_test_passed'] . " passed\n";
