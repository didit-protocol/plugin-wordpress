<?php
/**
 * Guest checkout retries after an interrupted verification attempt.
 *
 * On Android the liveness step fails inside the embedded flow (DID-10021).
 * The shopper's only recovery path is to start again from the checkout
 * button, which calls rest_create_session(). This test drives that handler
 * with the browser cookie a guest arrives with, before WooCommerce has an
 * order to hang the session on.
 *
 *   php tests/test-checkout-retry.php
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

function stored($session_id)
{
  $method = new ReflectionMethod(Didit_Verify::class, 'stored_session');
  if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
  }
  return $method->invoke(Didit_Verify::init(), $session_id);
}

function store($session_id, array $record)
{
  $method = new ReflectionMethod(Didit_Verify::class, 'store_session');
  if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
  }
  $method->invoke(Didit_Verify::init(), $session_id, $record);
}

function start_attempt()
{
  return Didit_Verify::init()->rest_create_session(new Didit_Test_Request('{}'));
}

function reset_attempts($owner_seed, $ip)
{
  $GLOBALS['didit_test_transients'] = [];
  $GLOBALS['didit_test_created'] = [];
  $_COOKIE['didit_verification_owner'] = str_repeat($owner_seed, 64);
  $_SERVER['REMOTE_ADDR'] = $ip;
}

$plugin = Didit_Verify::init();
$GLOBALS['didit_test_options']['didit_api_key'] = 'test-only-key';
$GLOBALS['didit_test_options']['didit_workflow_id'] = 'test-workflow';
$GLOBALS['didit_test_options']['didit_wc_mode'] = 'checkout';

// A guest at checkout: no WordPress user, no WooCommerce order yet.
$GLOBALS['didit_test_current_user'] = 0;
reset_attempts('c', '198.51.100.23');

$GLOBALS['didit_test_post_response'] = function () {
  $n = count($GLOBALS['didit_test_created']) + 1;
  $id = sprintf('%08x-1111-4111-8111-%012x', $n, $n);
  $GLOBALS['didit_test_created'][] = $id;
  return ['response' => ['code' => 201], 'body' => json_encode([
    'session_id' => $id,
    'status' => 'Not Started',
    'url' => 'https://verify.didit.me/session/' . $id,
  ])];
};

// First attempt: the shopper clicks Verify and the flow opens.
$first = start_attempt();
ok(is_array($first) && !empty($first['url']), 'the checkout button opens a verification session');
$session_id = $first['sessionId'] ?? '';
ok(1 === count($GLOBALS['didit_test_created']), 'the first attempt creates one Didit session');

// The liveness step fails; the shopper clicks Verify again.
$second = start_attempt();
ok(is_array($second) && ($second['sessionId'] ?? '') === $session_id,
  'a retry reopens the session that is already running for this browser');
ok(1 === count($GLOBALS['didit_test_created']), 'a retry does not create a duplicate Didit session');

// Android shoppers retry more than once; Try Again inside the frame does not help.
$third = start_attempt();
$fourth = start_attempt();
ok(!is_wp_error($fourth), 'guest retries are not refused after a few failed attempts');
ok(($third['sessionId'] ?? '') === $session_id && !is_wp_error($fourth) && ($fourth['sessionId'] ?? '') === $session_id,
  'every retry reopens the same running session');

// A finished session must not be reopened for a new verification.
reset_attempts('d', '198.51.100.25');
$open = start_attempt();
$open_id = $open['sessionId'] ?? '';
$record = stored($open_id);
$record['status'] = 'Approved';
store($open_id, $record);
$renewed = start_attempt();
ok(is_array($renewed) && ($renewed['sessionId'] ?? '') !== $open_id,
  'an approved session is not reused for a new verification');

// Creation stays limited: a client that keeps ending up in a closed session still hits the cap.
reset_attempts('e', '198.51.100.24');
$GLOBALS['didit_test_post_response'] = function () {
  $n = count($GLOBALS['didit_test_created']) + 1;
  $id = sprintf('%08x-2222-4222-8222-%012x', $n, $n);
  $GLOBALS['didit_test_created'][] = $id;
  return ['response' => ['code' => 201], 'body' => json_encode([
    'session_id' => $id,
    'status' => 'Not Started',
    'url' => 'https://verify.didit.me/session/' . $id,
  ])];
};

$last = null;
for ($i = 1; $i <= 4; $i++) {
  $last = start_attempt();
  if (is_array($last) && !empty($last['sessionId'])) {
    // Every attempt ends in a session that cannot be resumed.
    $closed = stored($last['sessionId']);
    $closed['status'] = 'Declined';
    store($last['sessionId'], $closed);
  }
}
ok(is_wp_error($last) && 'rate_limit' === $last->get_error_code(), 'the guest creation limit still stops a runaway loop');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
