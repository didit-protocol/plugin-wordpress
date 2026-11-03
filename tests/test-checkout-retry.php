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

function start_attempt(array $input = [])
{
  return Didit_Verify::init()->rest_create_session(new Didit_Test_Request($input ? json_encode($input) : '{}'));
}

// What Didit currently says about each session, as its decision endpoint reports it.
$GLOBALS['didit_remote_status'] = [];
$GLOBALS['didit_test_api_response'] = function ($url) {
  if (!empty($GLOBALS['didit_decision_unavailable'])) {
    return ['response' => ['code' => 503], 'body' => '{}'];
  }
  preg_match('#/session/([^/]+)/decision/#', $url, $m);
  $id = rawurldecode($m[1] ?? '');
  return ['response' => ['code' => 200], 'body' => json_encode([
    'session_id' => $id,
    'status' => $GLOBALS['didit_remote_status'][$id] ?? 'Not Started',
  ])];
};

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

// Didit ended the session but the webhook never reached the shop (this shop's webhooks returned 401).
foreach (['Abandoned', 'Expired'] as $i => $final) {
  reset_attempts((string) ($i + 1), '198.51.100.3' . $i);
  $dead = start_attempt();
  $dead_id = $dead['sessionId'] ?? '';
  $GLOBALS['didit_remote_status'][$dead_id] = $final;
  $after = start_attempt();
  ok(is_array($after) && !empty($after['sessionId']) && $after['sessionId'] !== $dead_id,
    "a session Didit reports as {$final} is not reopened, even without its webhook");
  $dead_record = stored($dead_id);
  ok($final === ($dead_record['status'] ?? ''), "the {$final} status Didit reported is recorded");
  $again = start_attempt();
  ok(($again['sessionId'] ?? '') === ($after['sessionId'] ?? 'x'), "the replacement for an {$final} session is the one reopened next");
}

// A webhook already recorded the session as abandoned.
reset_attempts('5', '198.51.100.40');
$abandoned = start_attempt();
$abandoned_id = $abandoned['sessionId'] ?? '';
$record = stored($abandoned_id);
$record['status'] = 'Abandoned';
store($abandoned_id, $record);
$calls = count($GLOBALS['didit_test_api_calls'] ?? []);
$fresh = start_attempt();
ok(is_array($fresh) && ($fresh['sessionId'] ?? '') !== $abandoned_id, 'a session recorded as Abandoned is not reopened');
ok(count($GLOBALS['didit_test_api_calls'] ?? []) === $calls, 'a session already recorded as finished is not looked up again');

// When Didit cannot say whether the session is still open, start a new one rather than guess.
reset_attempts('6', '198.51.100.41');
$unknown = start_attempt();
$GLOBALS['didit_decision_unavailable'] = true;
$retry = start_attempt();
$GLOBALS['didit_decision_unavailable'] = false;
ok(is_array($retry) && ($retry['sessionId'] ?? '') !== ($unknown['sessionId'] ?? ''),
  'a session whose status cannot be confirmed is not reopened');

// A guest session carries no account; after logging in the shopper needs one tied to their user.
reset_attempts('7', '198.51.100.42');
$guest = start_attempt();
$GLOBALS['didit_test_current_user'] = 42;
$member = start_attempt();
ok(is_array($member) && ($member['sessionId'] ?? '') !== ($guest['sessionId'] ?? ''),
  'a guest session is not reopened once the shopper has logged in');
ok(42 === (int) (stored($member['sessionId'] ?? '')['user_id'] ?? 0), 'the new session belongs to the logged-in shopper');
$GLOBALS['didit_test_current_user'] = 0;

// Redirect returns the shopper to the page they left, never to another site.
reset_attempts('8', '198.51.100.43');
$GLOBALS['didit_test_api_posts'] = [];
start_attempt(['return_url' => 'https://example.test/checkout/?step=2']);
$sent = json_decode($GLOBALS['didit_test_api_posts'][0]['args']['body'] ?? '{}', true);
ok('https://example.test/checkout/?step=2' === ($sent['callback'] ?? ''), 'a redirected session returns to the page it started from');
reset_attempts('9', '198.51.100.44');
$GLOBALS['didit_test_options']['didit_callback_url'] = 'https://example.test/verification-done';
$GLOBALS['didit_test_api_posts'] = [];
start_attempt(['return_url' => 'https://attacker.example/phish']);
$sent = json_decode($GLOBALS['didit_test_api_posts'][0]['args']['body'] ?? '{}', true);
ok('https://example.test/verification-done' === ($sent['callback'] ?? ''), 'a return address on another site is ignored');
unset($GLOBALS['didit_test_options']['didit_callback_url']);

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
