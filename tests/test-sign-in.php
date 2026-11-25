<?php
/**
 * Sign in with Didit: PKCE, single-use state bound to the browser, nonce, audience and
 * issuer checks, and account linking that never takes over an administrator.
 *
 *   php tests/test-sign-in.php
 */

require __DIR__ . '/wp-stubs-features.php';
require __DIR__ . '/../didit-verify.php';

$issuer = 'https://shop.sandbox.auth.didit.me';
$key = 'pk_test_' . Didit_Verify_Sign_In::b64url($issuer);
check($issuer === Didit_Verify_Sign_In::issuer_from_key($key), 'the publishable key names the issuer');
check('' === Didit_Verify_Sign_In::issuer_from_key('pk_test_' . Didit_Verify_Sign_In::b64url('http://shop.auth.didit.me')), 'a non-HTTPS issuer is refused');
check('' === Didit_Verify_Sign_In::issuer_from_key('sk_live_abc'), 'a secret key is not accepted as a publishable key');
// Expected value from: printf %s VERIFIER | openssl dgst -sha256 -binary | base64url.
check('RNI8OSexewxR3AiWhkFGHZD3zs4-4kxt4QFVCmMczxM' === Didit_Verify_Sign_In::pkce_challenge('dBjftJeZ4CVP-mJ92K9qPGHy13u5IgO3cy3JxbD7-1A'), 'the PKCE challenge is S256');

$opts = &$GLOBALS['didit_test_options'];
$opts['didit_signin_enabled'] = true;
$opts['didit_signin_publishable_key'] = $key;
$opts['didit_signin_application_id'] = 'app-123';
check(Didit_Verify_Sign_In::enabled(), 'sign-in is available once configured');

function jwt(array $claims) { return 'e30.' . Didit_Verify_Sign_In::b64url(json_encode($claims)) . '.sig'; }
function begin_flow() {
  $url = Didit_Verify_Sign_In::begin(['redirect_to' => '']);
  parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
  return [$url, $query];
}
function idp($claims, $session) {
  $GLOBALS['didit_test_post_response'] = function ($url, $args) use ($claims) {
    $GLOBALS['token_request'] = json_decode($args['body'], true);
    return ['response' => ['code' => 200], 'body' => json_encode(['access_token' => 'at', 'id_token' => jwt($claims)])];
  };
  $GLOBALS['didit_test_api_response'] = function ($url, $args) use ($session) {
    $GLOBALS['session_request'] = [$url, $args['headers']['Authorization'] ?? ''];
    return ['response' => ['code' => 200], 'body' => json_encode($session)];
  };
}

[$url, $query] = begin_flow();
check(0 === strpos($url, $issuer . '/sign-in?'), 'sign-in opens the hosted Didit page');
check('S256' === $query['code_challenge_method'] && 43 === strlen($query['code_challenge']) && 'app-123' === $query['client_id'], 'the request uses PKCE for this application');
check('https://example.test/wp-login.php?action=didit_callback' === $query['redirect_uri'], 'the redirect URL is the login callback');

$claims = ['iss' => $issuer, 'aud' => 'app-123', 'sub' => 'sub-1', 'nonce' => $query['nonce']];
$session = ['sub' => 'sub-1', 'application_id' => 'app-123', 'email' => 'jane@example.test', 'email_verified' => true];
idp($claims, $session);
$person = Didit_Verify_Sign_In::complete($query['state'], 'code-1');
check(is_array($person) && 'sub-1' === $person['sub'] && $person['email_verified'], 'a valid callback returns the Didit person');
check($GLOBALS['token_request']['code_verifier'] && 'authorization_code' === $GLOBALS['token_request']['grant_type'] && !isset($GLOBALS['token_request']['client_secret']),
  'the code is exchanged with the PKCE verifier and no secret');
check([$issuer . '/session/', 'Bearer at'] === $GLOBALS['session_request'], 'the person is read from the live session');
$replay = Didit_Verify_Sign_In::complete($query['state'], 'code-1');
check(is_wp_error($replay) && 'expired' === $replay->get_error_code(), 'a callback cannot be replayed');

[, $query] = begin_flow();
$_COOKIE['didit_signin'] = str_repeat('f', 64);
$result = Didit_Verify_Sign_In::complete($query['state'], 'code-2');
check(is_wp_error($result) && 'expired' === $result->get_error_code(), 'a callback from another browser is refused');

foreach ([
  'a wrong nonce' => [['nonce' => 'other'] + $claims, $session],
  'another audience' => [['aud' => 'app-999'] + $claims, $session],
  'another issuer' => [['iss' => 'https://evil.example'] + $claims, $session],
  'a session of another application' => [null, ['application_id' => 'app-999'] + $session],
  'a session of another person' => [null, ['sub' => 'sub-2'] + $session],
] as $label => $case) {
  [, $query] = begin_flow();
  $c = $case[0] ?: $claims;
  if (!$case[0]) { $c['nonce'] = $query['nonce']; }
  elseif (!isset($case[0]['nonce']) || 'other' !== $case[0]['nonce']) { $c['nonce'] = $query['nonce']; }
  idp($c, $case[1]);
  $result = Didit_Verify_Sign_In::complete($query['state'], 'code-3');
  check(is_wp_error($result) && 'denied' === $result->get_error_code(), "sign-in with $label is refused");
}

// Linking accounts.
$GLOBALS['didit_test_wp_users'] = [
  5 => (object) ['ID' => 5, 'user_login' => 'admin', 'user_email' => 'admin@example.test'],
  6 => (object) ['ID' => 6, 'user_login' => 'jane', 'user_email' => 'jane@example.test'],
];
$GLOBALS['didit_test_users'] = [5 => '', 6 => ''];
$GLOBALS['didit_test_caps'][5] = ['manage_options'];
$result = Didit_Verify_Sign_In::resolve_user('sub-admin', 'admin@example.test', true);
check(is_wp_error($result) && 'link_from_profile' === $result->get_error_code(), 'an administrator is never linked by email');
$result = Didit_Verify_Sign_In::resolve_user('sub-1', 'jane@example.test', false);
check(is_wp_error($result) && 'unverified_email' === $result->get_error_code(), 'an unverified email links nothing');
$user = Didit_Verify_Sign_In::resolve_user('sub-1', 'jane@example.test', true);
check(6 === $user->ID && 'sub-1' === get_user_meta(6, '_didit_auth_sub'), 'a verified email links a customer account');
$result = Didit_Verify_Sign_In::resolve_user('sub-2', 'jane@example.test', true);
check(is_wp_error($result) && 'linked_elsewhere' === $result->get_error_code(), 'an account linked to another Didit identity is not taken over');
$GLOBALS['didit_test_user_meta']['5|_didit_auth_sub'] = 'sub-admin';
$user = Didit_Verify_Sign_In::resolve_user('sub-admin', 'other@example.test', true);
check(5 === $user->ID, 'an administrator who linked from the profile signs in');
$result = Didit_Verify_Sign_In::resolve_user('sub-new', 'new@example.test', true);
check(is_wp_error($result) && 'no_account' === $result->get_error_code(), 'no account is created while registration is closed');
$opts['users_can_register'] = true;
$opts['didit_registration_enabled'] = true;
$result = Didit_Verify_Sign_In::resolve_user('sub-new', 'new@example.test', true);
check(is_wp_error($result) && 'register_first' === $result->get_error_code(), 'sign-in cannot skip the identity check at registration');
$opts['didit_registration_enabled'] = false;
$user = Didit_Verify_Sign_In::resolve_user('sub-new', 'new@example.test', true);
check(is_object($user) && 'new' === $GLOBALS['didit_test_wp_users'][$user->ID]->user_login && 'sub-new' === get_user_meta($user->ID, '_didit_auth_sub'), 'a new account is created and linked when anyone can register');

echo $GLOBALS['didit_test_passed'] . " passed\n";
