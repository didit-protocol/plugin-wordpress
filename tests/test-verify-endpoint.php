<?php
/**
 * Browser-callback (`POST /didit/v1/verify`) trust tests for the Didit Verify plugin.
 *
 * The endpoint receives the SDK's completion callback from the visitor's browser,
 * so every field in it is attacker-controlled. These tests pin down that the
 * verified state a site gates content and orders on is only ever derived from the
 * session decision Didit itself reports, for a session that belongs to the caller.
 *
 *   php tests/test-verify-endpoint.php
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-verify-endpoint.php
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../didit-verify.php';

$passed = 0;
$failed = 0;

function ok($condition, $label)
{
  global $passed, $failed;
  if ($condition) {
    $passed++;
    echo "  PASS  {$label}\n";
    return;
  }
  $failed++;
  echo "  FAIL  {$label}\n";
}

function reset_state($user_id)
{
  $GLOBALS['didit_test_user_meta'] = [];
  $GLOBALS['didit_test_actions'] = [];
  $GLOBALS['didit_test_http_responses'] = [];
  $GLOBALS['didit_test_http_requests'] = [];
  $GLOBALS['didit_test_current_user_id'] = $user_id;
  $GLOBALS['didit_test_users'] = [$user_id => ''];
  $GLOBALS['didit_test_options']['didit_api_key'] = 'test-api-key';
}

function queue_decision(array $decision, $code = 200)
{
  $GLOBALS['didit_test_http_responses'][] = [
    'response' => ['code' => $code],
    'body' => json_encode($decision),
  ];
}

function completion_request($session_id, $status)
{
  return new Didit_Test_Request(json_encode([
    'type' => 'completed',
    'sessionId' => $session_id,
    'status' => $status,
  ]));
}

function user_meta($user_id, $key)
{
  return $GLOBALS['didit_test_user_meta'][$user_id . '|' . $key] ?? null;
}

$plugin = Didit_Verify::init();
$USER = 7;

echo "Forged completion without any Didit session\n";
reset_state($USER);
// No decision is queued: an unknown session id gets no 200 from Didit.
$GLOBALS['didit_test_http_responses'][] = ['response' => ['code' => 404], 'body' => '{"detail":"Not found."}'];
$result = $plugin->rest_save_verification(completion_request('00000000-0000-4000-8000-000000000001', 'Approved'));
ok($result instanceof WP_Error, 'a completion Didit does not know about is rejected');
ok(null === user_meta($USER, '_didit_verified'), 'the caller is not marked verified');
ok('Approved' !== user_meta($USER, '_didit_status'), 'the caller-supplied "Approved" is not stored');
ok(false === strpos($plugin->render_gate_shortcode([], 'SECRET'), 'SECRET'), '[didit_gate] stays locked');
ok(empty($GLOBALS['didit_test_actions']), 'didit_verification_completed does not fire for a forged completion');

echo "\nOwn session, caller claims Approved, Didit says Declined\n";
reset_state($USER);
queue_decision([
  'session_id' => '00000000-0000-4000-8000-000000000002',
  'status' => 'Declined',
  'vendor_data' => 'wp-7',
  'metadata' => ['wp_user_id' => 7],
]);
$result = $plugin->rest_save_verification(completion_request('00000000-0000-4000-8000-000000000002', 'Approved'));
ok(!($result instanceof WP_Error), 'the completion is accepted');
ok('Declined' === user_meta($USER, '_didit_status'), 'the stored status is the one Didit reported');
ok(null === user_meta($USER, '_didit_verified'), 'the caller is not marked verified');
ok(false === strpos($plugin->render_gate_shortcode([], 'SECRET'), 'SECRET'), '[didit_gate] stays locked');
$request = $GLOBALS['didit_test_http_requests'][0] ?? null;
ok($request && 'https://verification.didit.me/v3/session/00000000-0000-4000-8000-000000000002/decision/' === $request['url'],
  'the decision is read from the v3 decision endpoint for the claimed session');
ok($request && 'test-api-key' === ($request['args']['headers']['x-api-key'] ?? null), 'the decision read is authenticated with the site API key');

echo "\nSomeone else's approved session\n";
reset_state($USER);
queue_decision([
  'session_id' => '00000000-0000-4000-8000-000000000003',
  'status' => 'Approved',
  'vendor_data' => 'wp-8',
  'metadata' => ['wp_user_id' => 8],
]);
$result = $plugin->rest_save_verification(completion_request('00000000-0000-4000-8000-000000000003', 'Approved'));
ok($result instanceof WP_Error && 403 === ($result->get_error_data()['status'] ?? 0), 'a session created for another user is refused with 403');
ok(null === user_meta($USER, '_didit_verified') && null === user_meta($USER, '_didit_status'), 'nothing is stored for the caller');

echo "\nDecision endpoint unavailable\n";
reset_state($USER);
$result = $plugin->rest_save_verification(completion_request('00000000-0000-4000-8000-000000000004', 'Approved'));
ok($result instanceof WP_Error && 502 === ($result->get_error_data()['status'] ?? 0), 'a failed decision read answers 502 instead of trusting the browser');
ok(null === user_meta($USER, '_didit_verified'), 'the caller is not marked verified');

echo "\nOwn session approved by Didit\n";
reset_state($USER);
queue_decision([
  'session_id' => '00000000-0000-4000-8000-000000000005',
  'status' => 'Approved',
  'vendor_data' => 'user@example.test',
  // Session creation sends metadata JSON-encoded; the decision may hand it back as a string.
  'metadata' => json_encode(['wp_user_id' => 7, 'wp_email' => 'user@example.test']),
]);
$result = $plugin->rest_save_verification(completion_request('00000000-0000-4000-8000-000000000005', 'Declined'));
ok(!($result instanceof WP_Error), 'the completion is accepted');
ok('Approved' === user_meta($USER, '_didit_status'), 'the stored status is Approved as Didit reported');
ok(1 === user_meta($USER, '_didit_verified'), 'the caller is marked verified');
ok('00000000-0000-4000-8000-000000000005' === user_meta($USER, '_didit_session_id'), 'the session mapping is stored');
ok(false !== strpos($plugin->render_gate_shortcode([], 'SECRET'), 'SECRET'), '[didit_gate] unlocks');
ok(1 === count($GLOBALS['didit_test_actions']) && 'didit_verification_completed' === $GLOBALS['didit_test_actions'][0][0]
  && 'Approved' === $GLOBALS['didit_test_actions'][0][3], 'didit_verification_completed fires with the Didit status');

echo "\nMalformed session id never reaches the API\n";
reset_state($USER);
queue_decision(['status' => 'Approved', 'metadata' => ['wp_user_id' => 7]]);
$result = $plugin->rest_save_verification(completion_request('../../v3/sessions/', 'Approved'));
ok($result instanceof WP_Error && 400 === ($result->get_error_data()['status'] ?? 0), 'a malformed session id is a 400');
ok(empty($GLOBALS['didit_test_http_requests']), 'no request is made for it');

echo "\nCancelled callback\n";
reset_state($USER);
$result = $plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'cancelled', 'sessionId' => 'x'])));
ok(!($result instanceof WP_Error), 'a cancellation is acknowledged');
ok(null === user_meta($USER, '_didit_status'), 'a cancellation stores nothing');
ok(empty($GLOBALS['didit_test_http_requests']), 'a cancellation reads no decision');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
