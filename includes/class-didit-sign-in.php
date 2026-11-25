<?php
/**
 * Sign in to WordPress with Didit (passkeys, email codes and every method the Didit
 * application allows), through Didit's hosted sign-in page.
 *
 * The flow is OpenID Connect authorization code with PKCE for a public client: no
 * secret is stored. The ID token is read straight from the token endpoint over TLS and
 * checked for issuer, audience and nonce; the person is then read from GET /session/,
 * which Didit answers only for a live session. Accounts link by Didit subject, or by a
 * verified email for accounts that cannot manage the site. Administrators link from
 * their own profile.
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Didit_Verify_Sign_In
{
  const COOKIE = 'didit_signin';
  const META = '_didit_auth_sub';
  const FLOW_TTL = 600;

  public static function init()
  {
    add_action('login_form', [__CLASS__, 'login_button']);
    add_action('login_form_didit_signin', [__CLASS__, 'start']);
    add_action('login_form_didit_callback', [__CLASS__, 'callback']);
    add_filter('wp_login_errors', [__CLASS__, 'login_errors']);
    add_action('admin_post_didit_unlink_signin', [__CLASS__, 'unlink']);
  }

  /**
   * The issuer a publishable key names: after pk_live_ or pk_test_ it is the issuer
   * origin in unpadded base64url.
   */
  public static function issuer_from_key(string $key): string
  {
    if (!preg_match('/^pk_(?:live|test)_([A-Za-z0-9_-]+)$/', trim($key), $m)) {
      return '';
    }
    $issuer = rtrim((string) self::b64url_decode($m[1]), '/');
    $parts = wp_parse_url($issuer);
    if (!is_array($parts) || 'https' !== ($parts['scheme'] ?? '') || empty($parts['host'])
      || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
      return '';
    }
    return $issuer;
  }

  public static function issuer(): string
  {
    return self::issuer_from_key((string) get_option('didit_signin_publishable_key', ''));
  }

  public static function application_id(): string
  {
    return trim((string) get_option('didit_signin_application_id', ''));
  }

  public static function enabled(): bool
  {
    return (bool) get_option('didit_signin_enabled', false) && '' !== self::issuer() && '' !== self::application_id();
  }

  public static function redirect_uri(): string
  {
    return site_url('wp-login.php?action=didit_callback', 'login');
  }

  public static function b64url(string $bytes): string
  {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

  public static function b64url_decode(string $value)
  {
    return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
  }

  public static function pkce_challenge(string $verifier): string
  {
    return self::b64url(hash('sha256', $verifier, true));
  }

  /* ── Settings and screens ── */

  public static function field_settings()
  {
    $issuer = self::issuer();
    printf(
      '<label><input type="checkbox" name="didit_signin_enabled" value="1" %1$s /> %2$s</label>
      <p style="margin-top:.75rem"><label>%3$s<br /><input type="text" class="large-text" name="didit_signin_publishable_key" value="%4$s" placeholder="pk_live_…" autocomplete="off" /></label></p>
      <p><label>%5$s<br /><input type="text" class="regular-text" name="didit_signin_application_id" value="%6$s" autocomplete="off" /></label></p>
      <p class="description">%7$s<br /><code>%8$s</code></p>%9$s',
      checked((bool) get_option('didit_signin_enabled', false), true, false),
      esc_html__('Show "Sign in with Didit" on the WordPress login page', 'didit-verify'),
      esc_html__('Publishable key (Authentication → Developers)', 'didit-verify'),
      esc_attr((string) get_option('didit_signin_publishable_key', '')),
      esc_html__('Application ID', 'didit-verify'),
      esc_attr(self::application_id()),
      esc_html__('Turn on Authentication for the application in the Didit Console and register this exact redirect URL:', 'didit-verify'),
      esc_html(self::redirect_uri()),
      $issuer ? '<p class="description">' . esc_html(sprintf(/* translators: %s: sign-in address. */ __('Sign-in address: %s', 'didit-verify'), $issuer)) . '</p>' : ''
    );
    printf(
      '<p class="description">%s</p>',
      esc_html__('People sign in with the methods the Didit application allows, including passkeys. An account links by its verified email, except accounts that can manage the site: those link from their own profile. New accounts are only created when anyone can register.', 'didit-verify')
    );
  }

  public static function login_button()
  {
    if (!self::enabled()) {
      return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only forwarded, validated on the way back.
    $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
    $url = add_query_arg(array_filter(['action' => 'didit_signin', 'redirect_to' => $redirect_to ? rawurlencode($redirect_to) : '']), site_url('wp-login.php', 'login'));
    printf(
      '<p class="didit-signin" style="margin:0 0 16px;text-align:center"><a class="button button-large" style="width:100%%;text-align:center" href="%s">%s</a></p>',
      esc_url($url),
      esc_html__('Sign in with Didit', 'didit-verify')
    );
  }

  public static function render_profile_link($user)
  {
    if (!self::enabled() && !get_user_meta($user->ID, self::META, true)) {
      return;
    }
    $linked = '' !== (string) get_user_meta($user->ID, self::META, true);
    echo '<table class="form-table" role="presentation"><tr><th>' . esc_html__('Sign in with Didit', 'didit-verify') . '</th><td>';
    echo '<p>' . esc_html($linked ? __('Linked to a Didit identity.', 'didit-verify') : __('Not linked.', 'didit-verify')) . '</p>';
    if ($linked && current_user_can('edit_user', $user->ID)) {
      printf(
        '<p><a class="button" href="%s">%s</a></p>',
        esc_url(wp_nonce_url(admin_url('admin-post.php?action=didit_unlink_signin&user_id=' . (int) $user->ID), 'didit_unlink_signin_' . (int) $user->ID)),
        esc_html__('Unlink', 'didit-verify')
      );
    } elseif (!$linked && self::enabled() && get_current_user_id() === (int) $user->ID) {
      printf(
        '<p><a class="button" href="%s">%s</a></p>',
        esc_url(wp_nonce_url(site_url('wp-login.php?action=didit_signin&link=1', 'login'), 'didit_link_signin')),
        esc_html__('Link my Didit identity', 'didit-verify')
      );
    }
    echo '</td></tr></table>';
  }

  public static function unlink()
  {
    $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
    check_admin_referer('didit_unlink_signin_' . $user_id);
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
      wp_die(esc_html__('You are not allowed to do this.', 'didit-verify'), '', ['response' => 403]);
    }
    delete_user_meta($user_id, self::META);
    wp_safe_redirect(get_current_user_id() === $user_id ? admin_url('profile.php') : admin_url('user-edit.php?user_id=' . $user_id));
    exit;
  }

  public static function error_messages(): array
  {
    return [
      'unavailable' => __('Sign in with Didit is not available right now.', 'didit-verify'),
      'expired' => __('The Didit sign-in expired or was already used. Please try again.', 'didit-verify'),
      'denied' => __('The Didit sign-in did not complete.', 'didit-verify'),
      'unverified_email' => __('Your Didit account has no verified email, so it cannot be matched to an account here.', 'didit-verify'),
      'link_from_profile' => __('This account can manage the site. Log in with your password and link Didit from your profile.', 'didit-verify'),
      'linked_elsewhere' => __('This account is linked to another Didit identity.', 'didit-verify'),
      'no_account' => __('No account here uses this email, and registration is closed.', 'didit-verify'),
      'register_first' => __('Please create your account with the registration form first.', 'didit-verify'),
    ];
  }

  public static function login_errors($errors)
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
    $code = isset($_GET['didit_error']) ? sanitize_key(wp_unslash($_GET['didit_error'])) : '';
    $messages = self::error_messages();
    if ($code && isset($messages[$code]) && is_object($errors)) {
      $errors->add('didit_' . $code, $messages[$code]);
    }
    return $errors;
  }

  private static function fail(string $code)
  {
    wp_safe_redirect(add_query_arg('didit_error', $code, site_url('wp-login.php', 'login')));
    exit;
  }

  private static function set_browser_cookie(string $value, int $expires)
  {
    setcookie(self::COOKIE, $value, [
      'expires' => $expires,
      'path' => '/',
      'secure' => is_ssl(),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }

  /* ── Flow ── */

  /** Build the hosted sign-in address and remember this attempt for its callback. */
  public static function begin(array $context): string
  {
    $verifier = self::b64url(random_bytes(32));
    $state = self::b64url(random_bytes(24));
    $nonce = self::b64url(random_bytes(24));
    $browser = bin2hex(random_bytes(32));
    set_transient('didit_signin_' . hash('sha256', $state), [
      'verifier' => $verifier,
      'nonce' => $nonce,
      'browser' => hash('sha256', $browser),
      'redirect_to' => (string) ($context['redirect_to'] ?? ''),
      'link_user' => (int) ($context['link_user'] ?? 0),
    ], self::FLOW_TTL);
    self::set_browser_cookie($browser, time() + self::FLOW_TTL);
    $_COOKIE[self::COOKIE] = $browser;
    return self::issuer() . '/sign-in?' . http_build_query([
      'redirect_uri' => self::redirect_uri(),
      'state' => $state,
      'code_challenge' => self::pkce_challenge($verifier),
      'code_challenge_method' => 'S256',
      'client_id' => self::application_id(),
      'nonce' => $nonce,
      'scope' => 'openid email',
    ], '', '&', PHP_QUERY_RFC3986);
  }

  public static function start()
  {
    if (!self::enabled()) {
      self::fail('unavailable');
    }
    $link_user = 0;
    if (!empty($_GET['link'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next.
      check_admin_referer('didit_link_signin');
      $link_user = get_current_user_id();
      if (!$link_user) {
        self::fail('expired');
      }
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a destination only, validated here and again on return.
    $redirect_to = isset($_GET['redirect_to']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_GET['redirect_to'])), '') : '';
    wp_redirect(self::begin(['redirect_to' => $redirect_to, 'link_user' => $link_user])); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the Didit sign-in origin is external by design.
    exit;
  }

  /**
   * Check a callback and return the Didit person it signed in.
   *
   * @return array|WP_Error ['sub', 'email', 'email_verified', 'flow']
   */
  public static function complete(string $state, string $code)
  {
    $key = 'didit_signin_' . hash('sha256', $state);
    $flow = '' !== $state ? get_transient($key) : false;
    // Single use: a replayed callback finds nothing.
    delete_transient($key);
    $browser = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
    self::set_browser_cookie('', time() - 3600);
    if (!is_array($flow) || '' === $code || '' === $browser || !hash_equals((string) $flow['browser'], hash('sha256', $browser))) {
      return new WP_Error('expired');
    }
    $issuer = self::issuer();
    $app_id = self::application_id();
    $response = wp_remote_post($issuer . '/token/', [
      'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
      'body' => wp_json_encode([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => (string) $flow['verifier'],
        'redirect_uri' => self::redirect_uri(),
        'client_id' => $app_id,
      ]),
      'timeout' => 15,
    ]);
    if (is_wp_error($response) || !is_array($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
      return new WP_Error('denied');
    }
    $tokens = json_decode((string) wp_remote_retrieve_body($response), true);
    $access = is_array($tokens) ? (string) ($tokens['access_token'] ?? '') : '';
    $claims = is_array($tokens) ? self::jwt_claims((string) ($tokens['id_token'] ?? '')) : null;
    $aud = is_array($claims) ? (array) ($claims['aud'] ?? []) : [];
    if ('' === $access || !is_array($claims)
      || !hash_equals((string) $flow['nonce'], (string) ($claims['nonce'] ?? ''))
      || rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer
      || !in_array($app_id, $aud, true)) {
      return new WP_Error('denied');
    }
    $response = wp_remote_get($issuer . '/session/', [
      'headers' => ['Authorization' => 'Bearer ' . $access, 'Accept' => 'application/json'],
      'timeout' => 15,
    ]);
    if (is_wp_error($response) || !is_array($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
      return new WP_Error('denied');
    }
    $session = json_decode((string) wp_remote_retrieve_body($response), true);
    $sub = is_array($session) ? (string) ($session['sub'] ?? '') : '';
    if ('' === $sub || $sub !== (string) ($claims['sub'] ?? '') || $app_id !== (string) ($session['application_id'] ?? '')) {
      return new WP_Error('denied');
    }
    return [
      'sub' => $sub,
      'email' => sanitize_email((string) ($session['email'] ?? '')),
      'email_verified' => true === ($session['email_verified'] ?? null),
      'flow' => $flow,
    ];
  }

  /** Claims of a JWT received directly from the token endpoint over TLS. */
  public static function jwt_claims(string $jwt)
  {
    $parts = explode('.', $jwt);
    if (3 !== count($parts)) {
      return null;
    }
    $claims = json_decode((string) self::b64url_decode($parts[1]), true);
    return is_array($claims) ? $claims : null;
  }

  private static function can_manage_site($user): bool
  {
    return user_can($user, 'manage_options') || user_can($user, 'edit_users') || user_can($user, 'promote_users')
      || (function_exists('is_super_admin') && is_super_admin($user->ID));
  }

  /**
   * The account a Didit person signs in to.
   *
   * @return WP_User|WP_Error
   */
  public static function resolve_user(string $sub, string $email, bool $email_verified)
  {
    $linked = get_users(['meta_key' => self::META, 'meta_value' => $sub, 'number' => 2, 'fields' => 'ID']);
    if (is_array($linked) && count($linked) > 1) {
      return new WP_Error('linked_elsewhere');
    }
    if (is_array($linked) && 1 === count($linked)) {
      $user = get_userdata((int) $linked[0]);
      return $user ?: new WP_Error('no_account');
    }
    if (!$email_verified || !is_email($email)) {
      return new WP_Error('unverified_email');
    }
    $user = get_user_by('email', $email);
    if ($user) {
      if (self::can_manage_site($user)) {
        return new WP_Error('link_from_profile');
      }
      $existing = (string) get_user_meta($user->ID, self::META, true);
      if ('' !== $existing && $existing !== $sub) {
        return new WP_Error('linked_elsewhere');
      }
      update_user_meta($user->ID, self::META, $sub);
      return $user;
    }
    if (!get_option('users_can_register', false)) {
      return new WP_Error('no_account');
    }
    // A required identity check at registration cannot be skipped through sign-in.
    if (Didit_Verify_Workflows::is_enabled('registration')) {
      return new WP_Error('register_first');
    }
    $base = sanitize_user(strstr($email, '@', true), true) ?: 'didit';
    $login = $base;
    for ($i = 2; username_exists($login); $i++) {
      $login = $base . $i;
    }
    $user_id = wp_insert_user([
      'user_login' => $login,
      'user_email' => $email,
      'user_pass' => wp_generate_password(32, true, true),
      'role' => get_option('default_role', 'subscriber'),
    ]);
    if (is_wp_error($user_id)) {
      return new WP_Error('no_account');
    }
    update_user_meta($user_id, self::META, $sub);
    return get_userdata($user_id);
  }

  public static function callback()
  {
    if (!self::enabled()) {
      self::fail('unavailable');
    }
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- state and PKCE protect this callback.
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    // phpcs:enable
    $person = self::complete($state, $code);
    if (is_wp_error($person)) {
      self::fail($person->get_error_code());
    }
    $flow = $person['flow'];
    if (!empty($flow['link_user'])) {
      $user_id = (int) $flow['link_user'];
      $others = get_users(['meta_key' => self::META, 'meta_value' => $person['sub'], 'number' => 1, 'fields' => 'ID']);
      if (get_current_user_id() !== $user_id || ($others && (int) $others[0] !== $user_id)) {
        self::fail('linked_elsewhere');
      }
      update_user_meta($user_id, self::META, $person['sub']);
      wp_safe_redirect(admin_url('profile.php'));
      exit;
    }
    $user = self::resolve_user($person['sub'], $person['email'], $person['email_verified']);
    if (is_wp_error($user)) {
      self::fail($user->get_error_code());
    }
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, false, is_ssl());
    do_action('wp_login', $user->user_login, $user);
    $default = admin_url('profile.php');
    $redirect = apply_filters('login_redirect', $flow['redirect_to'] ?: $default, $flow['redirect_to'], $user);
    wp_safe_redirect(wp_validate_redirect($redirect, $default));
    exit;
  }
}
