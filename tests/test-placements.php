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
  $decision = ['session_id' => $id, 'status' => $GLOBALS['didit_remote_status'][$id] ?? 'Not Started'];
  if (isset($GLOBALS['didit_remote_kind'][$id])) {
    $decision['session_kind'] = $GLOBALS['didit_remote_kind'][$id];
  }
  return ['response' => ['code' => 200], 'body' => json_encode($decision)];
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

// Checkout account creation: the shopper verifies for registration on the checkout page,
// which posts the checkout session in didit_session_id. The browser's own registration
// session still satisfies the registration check.
$_COOKIE['didit_verification_owner'] = str_repeat('f', 64);
$reg2 = start(['placement' => 'registration']);
$GLOBALS['didit_remote_status'][$reg2['sessionId']] = 'Approved';
$plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $reg2['sessionId']])));
$checkout2 = start(['placement' => 'checkout']);
$_POST = ['didit_session_id' => $checkout2['sessionId']];
$errors->codes = [];
Didit_Verify_Placements::registration_errors($errors);
check([] === $errors->codes, 'an account created at checkout accepts this browser\'s registration check');
$_POST = [];
Didit_Verify_Placements::bind_registration_session(79);
check($plugin->user_has_workflow_approval(79, 'wf-id'), 'the account created at checkout receives the registration approval');

// Guests confirm a session by its stored placement; the request no longer names one.
$opts['didit_require_login'] = true;
$_COOKIE['didit_verification_owner'] = str_repeat('1', 64);
$reg3 = start(['placement' => 'registration']);
$verify = function ($session_id) use ($plugin) {
  return $plugin->rest_check_verify_permission(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $session_id]), ['X-WP-Nonce' => 'test-nonce-wp_rest']));
};
check(true === $verify($reg3['sessionId']), 'a guest can confirm the registration session this browser started');
$_COOKIE['didit_verification_owner'] = str_repeat('2', 64);
check(is_wp_error($verify($reg3['sessionId'])), 'another browser cannot confirm it');
$_COOKIE['didit_verification_owner'] = str_repeat('1', 64);
$opts['didit_require_login'] = false;
$plain = start(['placement' => 'default']);
$opts['didit_require_login'] = true;
check(is_wp_error($verify($plain['sessionId'])), 'a guest cannot confirm a session of a placement that requires login');

// A resumed session must belong to the requested placement, not only to its workflow.
$GLOBALS['didit_test_current_user'] = 42;
$_COOKIE['didit_verification_owner'] = str_repeat('3', 64);
$opts['didit_my_account_enabled'] = true;
$plain42 = start(['placement' => 'default']);
$account = start(['placement' => 'my_account']);
check($plain42['sessionId'] !== $account['sessionId'], 'a session of another placement with the same workflow is not reopened');
check($account['sessionId'] === start(['placement' => 'my_account'])['sessionId'], 'the placement\'s own open session is reopened');

// My Account: a session that needs the customer's input can be continued.
$record = $plugin->stored_session($account['sessionId']);
$plugin->apply_confirmed_status($account['sessionId'], $record, 'Resubmitted', time() + 20);
ob_start();
Didit_Verify_Placements::account_tab();
$html = ob_get_clean();
check(false !== strpos($html, 'Your action is needed') && false !== strpos($html, 'Continue verification'),
  'a resubmission request offers to continue the verification');

// Content gate and status read only their own workflow.
if (!function_exists('do_shortcode')) { function do_shortcode($content) { return $content; } }
$GLOBALS['didit_test_user_meta']['42|_didit_approved_workflows'] = '';
$GLOBALS['didit_test_user_meta']['42|_didit_workflow_sessions'] = '';
$_COOKIE['didit_verification_owner'] = str_repeat('4', 64);
$age42 = start(['placement' => 'product_gate']);
$GLOBALS['didit_remote_status'][$age42['sessionId']] = 'Approved';
$plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $age42['sessionId']])));
check($plugin->user_has_workflow_approval(42, 'wf-age'), 'the age approval is recorded');
check(false === strpos($plugin->render_gate_shortcode([], 'SECRET'), 'SECRET'), 'an age approval does not unlock content gated by the identity workflow');
check(false === strpos($plugin->render_status_shortcode([]), 'Identity Verified'), 'an age approval is not shown as identity verified');
$id42 = start(['placement' => 'default']);
$GLOBALS['didit_remote_status'][$id42['sessionId']] = 'Approved';
$plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $id42['sessionId']])));
check(false !== strpos($plugin->render_gate_shortcode([], 'SECRET'), 'SECRET'), 'an approval of the gate\'s workflow unlocks it');
check(false !== strpos($plugin->render_status_shortcode([]), 'Identity Verified'), 'the status shortcode shows the gate\'s workflow');

// The kind of subject must match the placement, whatever workflow was picked.
$GLOBALS['didit_test_current_user'] = 0;
$opts['didit_require_login'] = false;
$_COOKIE['didit_verification_owner'] = str_repeat('5', 64);
$company = start(['placement' => 'checkout']);
$GLOBALS['didit_remote_status'][$company['sessionId']] = 'Approved';
$GLOBALS['didit_remote_kind'][$company['sessionId']] = 'business';
check(!didit_test_internal('checkout_session_is_approved', $company['sessionId']), 'a company decision cannot pass the personal checkout check');
$GLOBALS['didit_test_current_user'] = 42;
$_COOKIE['didit_verification_owner'] = str_repeat('6', 64);
$GLOBALS['didit_test_user_meta']['42|_didit_kyb_status'] = '';
$person = start(['placement' => 'business']);
$GLOBALS['didit_remote_status'][$person['sessionId']] = 'Approved';
$GLOBALS['didit_remote_kind'][$person['sessionId']] = 'user';
$plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $person['sessionId']])));
check('Approved' !== get_user_meta(42, '_didit_kyb_status'), 'a personal decision does not mark the company verified');
$GLOBALS['didit_test_transients'][Didit_Verify_Workflows::CACHE_KEY] = [['id' => 'wf-kyb', 'type' => 'kyb'], ['id' => 'wf-id', 'type' => 'kyc']];
check(['business' => 'wf-kyb', 'registration' => 'wf-id'] == Didit_Verify_Workflows::sanitize_map(['business' => 'wf-kyb', 'registration' => 'wf-id', 'checkout' => 'wf-kyb'])
  && [] === Didit_Verify_Workflows::sanitize_map(['business' => 'wf-id']), 'a workflow of the wrong type is not saved for a placement');

// WooCommerce's own registration settings open the registration placement to guests.
$opts['users_can_register'] = false;
check(!Didit_Verify_Placements::guest_may_start(['placement' => 'registration']), 'without any open registration, guests cannot start it');
if (!class_exists('WooCommerce')) { class WooCommerce {} }
$opts['woocommerce_enable_myaccount_registration'] = 'yes';
check(Didit_Verify_Placements::guest_may_start(['placement' => 'registration']), 'WooCommerce My Account registration opens the registration check');
$opts['woocommerce_enable_myaccount_registration'] = 'no';
$opts['woocommerce_enable_signup_and_login_from_checkout'] = 'yes';
check(Didit_Verify_Placements::guest_may_start(['placement' => 'registration']), 'checkout account creation opens the registration check');
check(!Didit_Verify_Placements::guest_may_start(['placement' => 'my_account']), 'other placements stay closed to guests');

$source = file_get_contents(__DIR__ . '/../includes/class-didit-placements.php');
check(false !== strpos($source, "'add_option_' . \$option"), 'the first save of the account tab switches also refreshes the endpoints');

echo $GLOBALS['didit_test_passed'] . " passed\n";
