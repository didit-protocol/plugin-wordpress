<?php
/**
 * Placements choose the workflow on the server, and an approval only counts for the
 * workflow that produced it.
 *
 *   php tests/test-placements.php
 */

require __DIR__ . '/wp-stubs-features.php';
require __DIR__ . '/../didit-verify.php';

$plugin = Didit_Verify::init();
$opts = &$GLOBALS['didit_test_options'];
$opts['didit_api_key'] = 'test-only-key';
$opts['didit_workflow_id'] = 'wf-default';
$opts['didit_placements'] = ['registration' => 'wf-id', 'product_gate' => 'wf-age', 'business' => 'wf-kyb'];

check('wf-id' === Didit_Verify_Workflows::workflow_for('registration'), 'a placement runs the workflow chosen for it');
check('wf-default' === Didit_Verify_Workflows::workflow_for('my_account'), 'a placement without a choice runs the default workflow');
check(['registration' => 'wf-id'] === Didit_Verify_Workflows::sanitize_map(['registration' => 'wf-id', 'evil' => 'x', 'checkout' => '<b>']),
  'only known placements and well-formed workflow IDs are saved');

$GLOBALS['didit_test_created'] = [];
$GLOBALS['didit_test_post_response'] = function ($url, $args) {
  $n = count($GLOBALS['didit_test_created']) + 1;
  $id = sprintf('%08x-2222-4222-8222-%012x', $n, $n);
  $GLOBALS['didit_test_created'][] = ['id' => $id, 'body' => json_decode($args['body'], true)];
  return ['response' => ['code' => 201], 'body' => json_encode(['session_id' => $id, 'url' => 'https://verify.didit.me/session/' . $id])];
};
$GLOBALS['didit_remote_status'] = [];
$GLOBALS['didit_test_api_response'] = function ($url) {
  preg_match('#/session/([^/]+)/decision/#', $url, $m);
  $id = rawurldecode($m[1] ?? '');
  return ['response' => ['code' => 200], 'body' => json_encode(['session_id' => $id, 'status' => $GLOBALS['didit_remote_status'][$id] ?? 'Not Started'])];
};
function start(array $input) {
  $GLOBALS['didit_test_transients'] = [];
  return Didit_Verify::init()->rest_create_session(new Didit_Test_Request(json_encode($input)));
}
function last_body() { $last = end($GLOBALS['didit_test_created']); return $last['body']; }

$_COOKIE['didit_verification_owner'] = str_repeat('a', 64);
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$GLOBALS['didit_test_current_user'] = 42;

$result = start(['placement' => 'default', 'workflow_id' => 'wf-cheap']);
check(!is_wp_error($result) && 'wf-default' === last_body()['workflow_id'], 'a workflow ID sent by the browser is ignored');
check(!array_key_exists('placement', last_body()), 'the placement is not forwarded to Didit');

$result = start(['placement' => 'evil']);
check(is_wp_error($result) && 400 === $result->get_error_data()['status'], 'an unknown placement is refused');
$result = start(['placement' => 'registration']);
check(is_wp_error($result) && 403 === $result->get_error_data()['status'], 'a switched-off placement is refused');

$opts['didit_gate_enabled'] = true;
$age = start(['placement' => 'product_gate']);
check(!is_wp_error($age) && 'wf-age' === last_body()['workflow_id'], 'the age gate starts its own workflow');
$age_record = $plugin->stored_session($age['sessionId']);
check('product_gate' === $age_record['placement'] && 'wf-age' === $age_record['workflow_id'], 'the session remembers its placement and workflow');

$count = count($GLOBALS['didit_test_created']);
$again = start(['placement' => 'product_gate']);
check($again['sessionId'] === $age['sessionId'] && count($GLOBALS['didit_test_created']) === $count, 'a retry reopens the running session of the same workflow');
$other = start(['placement' => 'default']);
check($other['sessionId'] !== $age['sessionId'], 'a session of another workflow is not reopened for this placement');

// Approval per workflow.
$GLOBALS['didit_remote_status'][$age['sessionId']] = 'Approved';
$plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $age['sessionId']])));
check($plugin->user_has_workflow_approval(42, 'wf-age'), 'an approval opens gates of its own workflow');
check(!$plugin->user_has_workflow_approval(42, 'wf-id'), 'an approval does not open gates of another workflow');
check(!$plugin->user_has_workflow_approval(7, 'wf-age'), 'an approval belongs to its own user');
check(Didit_Verify_Placements::visitor_is_approved(), 'the age gate opens for the approved shopper');

$record = $plugin->stored_session($age['sessionId']);
$plugin->apply_confirmed_status($age['sessionId'], $record, 'Declined', time() + 5);
check(!$plugin->user_has_workflow_approval(42, 'wf-age'), 'a later decline revokes the workflow approval');
check(!Didit_Verify_Placements::visitor_is_approved(), 'the age gate closes again after revocation');

// Gated products: per product and per category.
$GLOBALS['didit_test_post_meta'][501] = ['_didit_age_gate' => 'yes'];
$GLOBALS['didit_test_terms'][502] = [9];
$opts['didit_gate_categories'] = [9];
check(Didit_Verify_Placements::product_is_gated(501) && Didit_Verify_Placements::product_is_gated(502) && !Didit_Verify_Placements::product_is_gated(503),
  'products marked age-restricted or in a restricted category are gated');
check(false === Didit_Verify_Placements::add_to_cart_validation(true, 502) && $GLOBALS['didit_test_notices'],
  'a gated product cannot be added to the cart without approval');
check(true === Didit_Verify_Placements::add_to_cart_validation(true, 503), 'other products are not affected');

// Business checks never stand in for the person's identity.
$opts['didit_business_enabled'] = true;
$kyb = start(['placement' => 'business']);
check('wf-kyb' === last_body()['workflow_id'] && $kyb['sessionId'] === get_user_meta(42, '_didit_kyb_pending_session_id'),
  'a business check runs the KYB workflow and is tracked apart');
$GLOBALS['didit_remote_status'][$kyb['sessionId']] = 'Approved';
$plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $kyb['sessionId']])));
check('Approved' === get_user_meta(42, '_didit_kyb_status'), 'the business approval is recorded as KYB status');
check(!$plugin->is_user_verified(42), 'a business approval does not mark the person as identity verified');
$GLOBALS['didit_test_current_user'] = 0;
$result = start(['placement' => 'business']);
check(is_wp_error($result) && 401 === $result->get_error_data()['status'], 'visitors without an account cannot start a business check');

// Checkout only accepts an approval from the checkout workflow.
$GLOBALS['didit_test_current_user'] = 0;
$_COOKIE['didit_verification_owner'] = str_repeat('c', 64);
$opts['didit_require_login'] = false;
$gate = start(['placement' => 'product_gate']);
$GLOBALS['didit_remote_status'][$gate['sessionId']] = 'Approved';
check(!didit_test_internal('checkout_session_is_approved', $gate['sessionId']), 'an approval from the age workflow cannot pass checkout');
$plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $gate['sessionId']])));
check(Didit_Verify_Placements::visitor_is_approved(), 'a visitor without an account passes the age gate after approval');
$checkout = start(['placement' => 'checkout']);
check(Didit_Verify_Placements::visitor_is_approved(), 'starting the checkout check keeps the visitor\'s age approval');
$GLOBALS['didit_remote_status'][$checkout['sessionId']] = 'Approved';
check(didit_test_internal('checkout_session_is_approved', $checkout['sessionId']), 'an approval from the checkout workflow passes checkout');
$_COOKIE['didit_verification_owner'] = str_repeat('9', 64);
check(!Didit_Verify_Placements::visitor_is_approved(), 'another browser does not inherit the visitor\'s age approval');
$_COOKIE['didit_verification_owner'] = str_repeat('c', 64);

// Registration: a visitor verifies first, the new account receives the approval once.
$opts['didit_registration_enabled'] = true;
$opts['users_can_register'] = true;
check(Didit_Verify_Placements::guest_may_start(['placement' => 'registration']), 'visitors may verify before registering when registration is open');
$_COOKIE['didit_verification_owner'] = str_repeat('d', 64);
$reg = start(['placement' => 'registration']);
$errors = new class { public $codes = []; public function add($code, $message) { $this->codes[] = $code; } };
$_POST['didit_session_id'] = $reg['sessionId'];
Didit_Verify_Placements::registration_errors($errors);
check(['didit_verification_required'] === $errors->codes, 'registration is refused before Didit approves');
$GLOBALS['didit_remote_status'][$reg['sessionId']] = 'Approved';
$errors->codes = [];
Didit_Verify_Placements::registration_errors($errors);
check([] === $errors->codes, 'registration passes once Didit approves');
Didit_Verify_Placements::bind_registration_session(77);
check($plugin->is_user_verified(77) && $plugin->user_has_workflow_approval(77, 'wf-id'), 'the new account receives the approval');
Didit_Verify_Placements::bind_registration_session(78);
check(!$plugin->is_user_verified(78), 'the same approval cannot be given to a second account');
$_COOKIE['didit_verification_owner'] = str_repeat('e', 64);
$errors->codes = [];
Didit_Verify_Placements::registration_errors($errors);
check(['didit_verification_required'] === $errors->codes, 'another browser cannot reuse the session to register');

echo $GLOBALS['didit_test_passed'] . " passed\n";
