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
$GLOBALS['didit_test_options']['didit_workflow_id'] = 'wf-default';
$record = ['owner' => hash('sha256', $_COOKIE['didit_verification_owner']), 'user_id' => 42,
  'order_id' => 0, 'event_timestamp' => 0, 'consumed_order_id' => 0, 'status' => 'Not Started', 'workflow_id' => 'wf-default'];
internal('store_session', $id, $record);
$GLOBALS['didit_test_user_meta']['42|_didit_pending_session_id'] = $id;
$GLOBALS['didit_test_api_response'] = ['response' => ['code' => 200], 'body' => json_encode(['session_id' => $id, 'session_kind' => 'user', 'status' => 'Declined'])];
$result = claim($id);
check('Declined' === $result['status'], 'Didit decision overrides browser Approved');
check('Declined' === internal('confirmed_user_status', 42), 'server decision updates visible status');
check(!internal('checkout_session_is_approved', $id), 'declined session cannot pass checkout');
$GLOBALS['didit_test_api_response']['body'] = json_encode(['session_id' => $id, 'session_kind' => 'user', 'status' => 'Approved']);
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
$GLOBALS['didit_test_api_response']['body'] = json_encode(['session_id' => $id, 'session_kind' => 'user', 'status' => 'In Review']);
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
function signed_status($id, $status, $timestamp, $decided = null) {
  // Didit's decision says the same as the delivery unless a test says otherwise.
  $GLOBALS['didit_test_api_response'] = ['response' => ['code' => 200], 'body' => json_encode(['session_id' => $id, 'session_kind' => 'user', 'status' => $decided ?: $status])];
  $body = json_encode(['session_id' => $id, 'session_kind' => 'user', 'status' => $status,
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
// Version 0.3.3 could already have recorded a revocation without updating user meta.
$revoked_record = internal('stored_session', $pending_id);
$revoked_record['status'] = 'Declined';
internal('store_session', $pending_id, $revoked_record);
check(!Didit_Verify::init()->is_user_verified(42), 'an existing recorded revocation overrides stale approval metadata after upgrade');
$revoked_record['status'] = 'Approved';
$revoked_record['user_id'] = 7;
internal('store_session', $pending_id, $revoked_record);
check(!Didit_Verify::init()->is_user_verified(42), 'another user\'s session cannot supply the current approval');

// The fetched decision wins over the delivery: an approval delivered after a reviewer
// declined the session stays declined, and the report matches the recorded status.
$late_id = 'cccccccc-bbbb-cccc-dddd-eeeeeeeeeeee';
$GLOBALS['didit_test_user_meta']['42|_didit_pending_session_id'] = $late_id;
internal('store_session', $late_id, ['owner' => hash('sha256', $_COOKIE['didit_verification_owner']), 'user_id' => 42,
  'order_id' => 0, 'event_timestamp' => 0, 'consumed_order_id' => 0, 'status' => 'In Review']);
signed_status($late_id, 'Approved', time() + 5, 'Declined');
check('Declined' === internal('stored_session', $late_id)['status'], 'a late approval delivery records the decision Didit holds now');
check(!Didit_Verify::init()->is_user_verified(42), 'a late approval delivery does not approve a declined session');

// Without the decision, a revocation still applies and Didit is asked to retry; an approval waits.
$offline_id = 'dddddddd-bbbb-cccc-dddd-eeeeeeeeeeee';
$GLOBALS['didit_test_user_meta']['42|_didit_pending_session_id'] = $offline_id;
internal('store_session', $offline_id, ['owner' => hash('sha256', $_COOKIE['didit_verification_owner']), 'user_id' => 42,
  'order_id' => 0, 'event_timestamp' => 0, 'consumed_order_id' => 0, 'status' => 'In Review']);
$body = json_encode(['session_id' => $offline_id, 'session_kind' => 'user', 'status' => 'Approved', 'timestamp' => time() + 6, 'webhook_type' => 'status.updated']);
$GLOBALS['didit_test_api_response'] = new WP_Error('offline');
$response = Didit_Verify::init()->rest_webhook(new Didit_Test_Request($body, ['X-Signature' => hash_hmac('sha256', $body, $secret)]));
check(is_wp_error($response) && 503 === $response->get_error_data()['status'], 'an approval whose decision cannot be read is retried');
check('In Review' === internal('stored_session', $offline_id)['status'], 'an approval whose decision cannot be read grants nothing yet');
$body = json_encode(['session_id' => $offline_id, 'session_kind' => 'user', 'status' => 'Declined', 'timestamp' => time() + 7, 'webhook_type' => 'status.updated']);
$response = Didit_Verify::init()->rest_webhook(new Didit_Test_Request($body, ['X-Signature' => hash_hmac('sha256', $body, $secret)]));
check(is_wp_error($response) && 503 === $response->get_error_data()['status'], 'a revocation whose decision cannot be read is retried for its report');
check('Declined' === internal('stored_session', $offline_id)['status'], 'a revocation applies even when the decision cannot be read');

// A session from before 0.4.0 recorded no workflow. Its approval counts only for the
// workflow Didit's decision names, never for whatever the default is today.
$legacy_id = 'eeeeeeee-bbbb-cccc-dddd-eeeeeeeeeeee';
$legacy = ['owner' => hash('sha256', $_COOKIE['didit_verification_owner']), 'user_id' => 42,
  'order_id' => 0, 'event_timestamp' => 1, 'consumed_order_id' => 0, 'status' => 'Approved'];
internal('store_session', $legacy_id, $legacy);
$GLOBALS['didit_test_user_meta']['42|_didit_session_id'] = $legacy_id;
$GLOBALS['didit_test_user_meta']['42|_didit_confirmed_session_id'] = $legacy_id;
$GLOBALS['didit_test_user_meta']['42|_didit_approved_workflows'] = '';
$GLOBALS['didit_test_options']['didit_workflow_id'] = 'wf-strict';
$GLOBALS['didit_test_api_response'] = ['response' => ['code' => 200], 'body' => json_encode(['session_id' => $legacy_id, 'session_kind' => 'user', 'status' => 'Approved', 'workflow_id' => 'wf-light'])];
check(!Didit_Verify::init()->user_has_workflow_approval(42, 'wf-strict'), 'a pre-upgrade approval does not pass a stricter default chosen later');
check('wf-light' === internal('stored_session', $legacy_id)['workflow_id'], 'the workflow of a pre-upgrade session is recovered from Didit and kept');
check(Didit_Verify::init()->user_has_workflow_approval(42, 'wf-light'), 'a pre-upgrade approval counts for the workflow it ran');
$legacy['workflow_lookup_at'] = 0;
internal('store_session', $legacy_id, $legacy);
$GLOBALS['didit_test_api_response'] = new WP_Error('offline');
check(!Didit_Verify::init()->user_has_workflow_approval(42, 'wf-strict') && !Didit_Verify::init()->user_has_workflow_approval(42, ''),
  'when Didit cannot name the workflow, the pre-upgrade approval counts for none');
check(!internal('checkout_session_is_approved', $legacy_id), 'a pre-upgrade session of unknown workflow cannot pass checkout');

// A pre-upgrade approval Didit has since declined: the decision is applied before judging.
$declined_legacy = 'eeeeeeee-bbbb-cccc-dddd-000000000001';
internal('store_session', $declined_legacy, ['owner' => hash('sha256', $_COOKIE['didit_verification_owner']), 'user_id' => 42,
  'order_id' => 0, 'event_timestamp' => 1, 'consumed_order_id' => 0, 'status' => 'Approved']);
$GLOBALS['didit_test_user_meta']['42|_didit_session_id'] = $declined_legacy;
$GLOBALS['didit_test_user_meta']['42|_didit_confirmed_session_id'] = $declined_legacy;
$GLOBALS['didit_test_user_meta']['42|_didit_status'] = 'Approved';
$GLOBALS['didit_test_api_response'] = ['response' => ['code' => 200], 'body' => json_encode(['session_id' => $declined_legacy, 'session_kind' => 'user', 'status' => 'Declined', 'workflow_id' => 'wf-light'])];
check(!Didit_Verify::init()->user_has_workflow_approval(42, 'wf-light'), 'a pre-upgrade approval that Didit now declines opens nothing');
check('Declined' === internal('stored_session', $declined_legacy)['status'] && 'Declined' === internal('confirmed_user_status', 42),
  'the declined decision is recorded on the migrated session and the user');
$GLOBALS['didit_test_api_calls'] = [];
check(!Didit_Verify::init()->user_has_workflow_approval(42, 'wf-light') && !$GLOBALS['didit_test_api_calls'], 'later reads use the recorded decision');

// A company decision on the personal record never marks the person verified.
$company_legacy = 'eeeeeeee-bbbb-cccc-dddd-000000000002';
internal('store_session', $company_legacy, ['owner' => hash('sha256', $_COOKIE['didit_verification_owner']), 'user_id' => 42,
  'order_id' => 0, 'event_timestamp' => 1, 'consumed_order_id' => 0, 'status' => 'Approved', 'workflow_id' => 'wf-light', 'session_kind' => 'business', 'placement' => 'default']);
$GLOBALS['didit_test_user_meta']['42|_didit_session_id'] = $company_legacy;
$GLOBALS['didit_test_user_meta']['42|_didit_confirmed_session_id'] = $company_legacy;
check(!Didit_Verify::init()->is_user_verified(42), 'is_user_verified() is false for a company decision');
echo "$passed passed\n";
