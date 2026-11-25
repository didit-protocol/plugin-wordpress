<?php
/**
 * Sign in with Didit: PKCE, single-use state bound to the browser, nonce, audience and
 * issuer checks, account linking that never takes over an administrator and is bound
 * to the issuer and site, and WordPress's account restrictions before the login cookie.
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
$link_key = '_didit_auth_link_1';
check($link_key === Didit_Verify_Sign_In::link_meta_key(), 'links are stored per site');
$user = Didit_Verify_Sign_In::resolve_user('sub-1', 'jane@example.test', true);
check(6 === $user->ID && hash('sha256', $issuer . '|sub-1') === get_user_meta(6, $link_key), 'a verified email links a customer account, bound to the issuer');
$user = Didit_Verify_Sign_In::resolve_user('sub-1', 'unrelated@example.test', true);
check(is_object($user) && 6 === $user->ID, 'a linked identity signs in without an email match');
$result = Didit_Verify_Sign_In::resolve_user('sub-2', 'jane@example.test', true);
check(is_wp_error($result) && 'linked_elsewhere' === $result->get_error_code(), 'an account linked to another Didit identity is not taken over');
$GLOBALS['didit_test_user_meta']['5|_didit_auth_sub'] = 'sub-admin';
$result = Didit_Verify_Sign_In::resolve_user('sub-admin', 'other@example.test', true);
check(is_wp_error($result) && 'no_account' === $result->get_error_code(), 'a legacy subject-only link no longer signs in');
$GLOBALS['didit_test_user_meta']['5|' . $link_key] = Didit_Verify_Sign_In::link_value('sub-admin');
$user = Didit_Verify_Sign_In::resolve_user('sub-admin', 'other@example.test', true);
check(5 === $user->ID, 'an administrator who linked from the profile signs in');

// The link names the issuer: after the sign-in key changes, the same subject is someone else.
$other_issuer = 'https://other.sandbox.auth.didit.me';
$opts['didit_signin_publishable_key'] = 'pk_test_' . Didit_Verify_Sign_In::b64url($other_issuer);
$result = Didit_Verify_Sign_In::resolve_user('sub-admin', 'other@example.test', true);
check(is_wp_error($result) && 'no_account' === $result->get_error_code(), 'an old link does not match a subject from another issuer');
$result = Didit_Verify_Sign_In::resolve_user('sub-1', 'jane@example.test', true);
check(is_wp_error($result) && 'linked_elsewhere' === $result->get_error_code(), 'an account linked under the old issuer is not silently relinked');
$opts['didit_signin_publishable_key'] = $key;

// Multisite: the link and the email match belong to one site.
$GLOBALS['didit_test_multisite'] = true;
$GLOBALS['didit_test_blog_id'] = 2;
$GLOBALS['didit_test_blog_members'] = [1 => [5, 6], 2 => [6]];
$result = Didit_Verify_Sign_In::resolve_user('sub-admin', 'other@example.test', true);
check(is_wp_error($result) && 'no_account' === $result->get_error_code(), "a link made on another site does not sign in here");
$GLOBALS['didit_test_wp_users'][7] = (object) ['ID' => 7, 'user_login' => 'sam', 'user_email' => 'sam@example.test'];
$GLOBALS['didit_test_users'][7] = '';
$result = Didit_Verify_Sign_In::resolve_user('sub-7', 'sam@example.test', true);
check(is_wp_error($result) && 'not_allowed' === $result->get_error_code() && '' === get_user_meta(7, '_didit_auth_link_2'), 'an email is not linked to an account that is not a member of this site');
$GLOBALS['didit_test_blog_members'][2][] = 7;
$user = Didit_Verify_Sign_In::resolve_user('sub-7', 'sam@example.test', true);
check(7 === $user->ID && '' !== get_user_meta(7, '_didit_auth_link_2') && '' === get_user_meta(7, $link_key), "a member of this site links on this site only");

// Account restrictions before the login cookie.
$GLOBALS['didit_test_spam_users'] = [7];
$result = Didit_Verify_Sign_In::log_in($GLOBALS['didit_test_wp_users'][7]);
check(is_wp_error($result) && 'not_allowed' === $result->get_error_code() && [] === $GLOBALS['didit_test_auth_cookies'], 'a spam account is refused without a login cookie');
$GLOBALS['didit_test_spam_users'] = [];
$result = Didit_Verify_Sign_In::log_in($GLOBALS['didit_test_wp_users'][5]);
check(is_wp_error($result) && [] === $GLOBALS['didit_test_auth_cookies'], 'a user who is not a member of this site is refused');
$GLOBALS['didit_test_filters']['wp_authenticate_user'] = function ($user, $password) {
  return 7 === $user->ID ? new WP_Error('pending_approval') : $user;
};
$result = Didit_Verify_Sign_In::log_in($GLOBALS['didit_test_wp_users'][7]);
check(is_wp_error($result) && 'not_allowed' === $result->get_error_code() && [] === $GLOBALS['didit_test_auth_cookies'], 'a login denied by wp_authenticate_user sets no cookie');
unset($GLOBALS['didit_test_filters']['wp_authenticate_user']);
$user = Didit_Verify_Sign_In::log_in($GLOBALS['didit_test_wp_users'][7]);
check(is_object($user) && [7] === $GLOBALS['didit_test_auth_cookies'] && 7 === get_current_user_id(), 'an allowed account receives the login cookie');
$GLOBALS['didit_test_auth_cookies'] = [];
$GLOBALS['didit_test_multisite'] = false;
$GLOBALS['didit_test_blog_id'] = 1;
$GLOBALS['didit_test_blog_members'] = null;
$result = Didit_Verify_Sign_In::resolve_user('sub-new', 'new@example.test', true);
check(is_wp_error($result) && 'no_account' === $result->get_error_code(), 'no account is created while registration is closed');
$opts['users_can_register'] = true;
$opts['didit_registration_enabled'] = true;
$result = Didit_Verify_Sign_In::resolve_user('sub-new', 'new@example.test', true);
check(is_wp_error($result) && 'register_first' === $result->get_error_code(), 'sign-in cannot skip the identity check at registration');
$opts['didit_registration_enabled'] = false;
$user = Didit_Verify_Sign_In::resolve_user('sub-new', 'new@example.test', true);
check(is_object($user) && 'new' === $GLOBALS['didit_test_wp_users'][$user->ID]->user_login && Didit_Verify_Sign_In::link_value('sub-new') === get_user_meta($user->ID, $link_key), 'a new account is created and linked when anyone can register');

// A subscriber sees the self-service controls on their own profile, not the admin report.
if (!function_exists('current_user_can')) {
  function current_user_can($cap, ...$args) {
    return 'edit_user' === $cap ? get_current_user_id() === (int) ($args[0] ?? 0) : user_can(get_current_user_id(), $cap);
  }
}
if (!function_exists('admin_url')) { function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); } }
if (!function_exists('wp_nonce_url')) { function wp_nonce_url($url, $action) { return $url . '&_wpnonce=test-nonce-' . $action; } }
$GLOBALS['didit_test_current_user'] = 6;
ob_start();
Didit_Verify::init()->user_profile_report($GLOBALS['didit_test_wp_users'][6]);
$html = ob_get_clean();
check(false !== strpos($html, 'didit_unlink_signin') && false === strpos($html, 'Didit verification'), 'a linked subscriber can unlink from their own profile without seeing the report');
ob_start();
Didit_Verify::init()->user_profile_report($GLOBALS['didit_test_wp_users'][5]);
check('' === ob_get_clean(), "nothing is shown on a profile the viewer cannot edit");

echo $GLOBALS['didit_test_passed'] . " passed\n";
