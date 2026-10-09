<?php
/** Browser claims, ownership, decision verification and legacy-state regressions. */
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../didit-verify.php';
$passed = 0;
function check($condition, $label) {
  global $passed;
  if (!$condition) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
  $passed++;
  echo "PASS $label\n";
}
function internal($name, ...$args) {
  $method = new ReflectionMethod(Didit_Verify::class, $name);
  if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
  return $method->invoke(Didit_Verify::init(), ...$args);
}
function claim($id, $status = 'Approved') {
  return Didit_Verify::init()->rest_save_verification(new Didit_Test_Request(json_encode([
    'type' => 'completed', 'sessionId' => $id, 'status' => $status,
  ])));
}
$id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$_COOKIE['didit_verification_owner'] = str_repeat('a', 64);
$GLOBALS['didit_test_options']['didit_api_key'] = 'test-only-key';
$GLOBALS['didit_test_api_calls'] = [];
check(claim($id) instanceof WP_Error, 'invented session cannot approve a user');
check(!$GLOBALS['didit_test_api_calls'], 'unbound sessions never query Didit');
$GLOBALS['didit_test_user_meta']['42|_didit_session_id'] = $id;
$GLOBALS['didit_test_user_meta']['42|_didit_status'] = 'Approved';
check('' === internal('confirmed_user_status', 42), 'legacy browser-written Approved does not unlock content');
$record = ['owner' => hash('sha256', $_COOKIE['didit_verification_owner']), 'user_id' => 42,
  'order_id' => 0, 'event_timestamp' => 0, 'consumed_order_id' => 0, 'status' => 'Not Started'];
internal('store_session', $id, $record);
$GLOBALS['didit_test_user_meta']['42|_didit_pending_session_id'] = $id;
$GLOBALS['didit_test_api_response'] = ['response' => ['code' => 200], 'body' => json_encode(['session_id' => $id, 'status' => 'Declined'])];
$result = claim($id);
check('Declined' === $result['status'], 'Didit decision overrides browser Approved');
check('Declined' === internal('confirmed_user_status', 42), 'server decision updates visible status');
check(!internal('checkout_session_is_approved', $id), 'declined session cannot pass checkout');
$GLOBALS['didit_test_api_response']['body'] = json_encode(['session_id' => $id, 'status' => 'Approved']);
$result = claim($id, 'Declined');
check('Approved' === $result['status'], 'legitimate server approval succeeds');
check(internal('checkout_session_is_approved', $id), 'bound approved session passes checkout');
$GLOBALS['didit_test_current_user'] = 7;
check(claim($id) instanceof WP_Error, 'another user cannot claim approved session');
$GLOBALS['didit_test_current_user'] = 42;
$_COOKIE['didit_verification_owner'] = str_repeat('b', 64);
check(claim($id) instanceof WP_Error, 'another browser cannot claim session');
$_COOKIE['didit_verification_owner'] = str_repeat('a', 64);
$GLOBALS['didit_test_api_response'] = new WP_Error('offline');
check(!internal('checkout_session_is_approved', $id), 'API outage fails checkout closed');
$GLOBALS['didit_test_api_response'] = ['response' => ['code' => 200], 'body' => json_encode(['session_id' => 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee', 'status' => 'Approved'])];
check(claim($id) instanceof WP_Error, 'mismatched decision session is rejected');
$GLOBALS['didit_test_api_response']['body'] = json_encode(['session_id' => $id, 'status' => 'In Review']);
check(!internal('checkout_session_is_approved', $id), 'In Review never passes checkout');
$record['consumed_order_id'] = 99;
internal('store_session', $id, $record);
check(!internal('checkout_session_is_approved', $id), 'used checkout session cannot create another order');
check(claim(['bad']) instanceof WP_Error, 'malformed array input fails safely');
$record['event_timestamp'] = time();
internal('store_session', $id, $record);
internal('apply_confirmed_status', $id, $record, 'Approved', time() - 60);
check('Not Started' === internal('stored_session', $id)['status'], 'older signed delivery cannot overwrite newer state');

// A second attempt must not prevent revocation of the session granting access.
$pending_id = 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee';
$secret = 'test-only-webhook-secret';
$GLOBALS['didit_test_options']['didit_webhook_secret'] = $secret;
function signed_status($id, $status, $timestamp) {
  $body = json_encode(['session_id' => $id, 'status' => $status,
    'timestamp' => $timestamp, 'webhook_type' => 'status.updated']);
  return Didit_Verify::init()->rest_webhook(new Didit_Test_Request($body, [
    'X-Signature' => hash_hmac('sha256', $body, $GLOBALS['didit_test_options']['didit_webhook_secret']),
  ]));
}
foreach (['Declined', 'Expired', 'KYC Expired', 'In Review'] as $status) {
  $record['event_timestamp'] = time() - 2;
  $record['status'] = 'Approved';
  internal('store_session', $id, $record);
  $GLOBALS['didit_test_user_meta']['42|_didit_session_id'] = $id;
  $GLOBALS['didit_test_user_meta']['42|_didit_confirmed_session_id'] = $id;
  $GLOBALS['didit_test_user_meta']['42|_didit_status'] = 'Approved';
  $GLOBALS['didit_test_user_meta']['42|_didit_verified'] = 1;
  $GLOBALS['didit_test_user_meta']['42|_didit_pending_session_id'] = $pending_id;
  signed_status($id, $status, time());
  check(!Didit_Verify::init()->is_user_verified(42), "$status revokes the current approval while another session is pending");
  check($status === internal('confirmed_user_status', 42), "$status is displayed on the user after revocation");
  check('' === get_user_meta(42, '_didit_verified', true), "$status clears the verified flag");
}
$record['event_timestamp'] = 0;
$record['status'] = 'Not Started';
internal('store_session', $pending_id, $record);
signed_status($pending_id, 'Approved', time());
check(Didit_Verify::init()->is_user_verified(42), 'the new pending session can approve the user');
signed_status($id, 'Declined', time() + 1);
check(Didit_Verify::init()->is_user_verified(42), 'a superseded session cannot revoke the new approval');
check($pending_id === get_user_meta(42, '_didit_confirmed_session_id', true), 'a superseded session cannot replace the active session');
signed_status($pending_id, 'Declined', time() - 1);
check(Didit_Verify::init()->is_user_verified(42), 'an older revocation cannot overwrite a newer approval');
echo "$passed passed\n";
