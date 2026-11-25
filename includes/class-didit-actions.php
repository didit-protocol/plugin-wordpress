<?php
/**
 * Checks that run without a verification session: AML screening of the billing name
 * when an order reaches a threshold, and phone or email codes for an order's contact.
 *
 * Every check is off until the admin switches it on. Contact details are always read
 * from the order, never from the browser, so the routes cannot message other numbers.
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Didit_Verify_Actions
{
  const AML_HOOK = 'didit_aml_screen_order';
  const MAX_CONTACT_ATTEMPTS = 5;

  public static function init($plugin)
  {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
    add_action(self::AML_HOOK, [__CLASS__, 'screen_order'], 10, 1);
    add_action('woocommerce_loaded', [__CLASS__, 'wc_hooks']);
  }

  public static function wc_hooks()
  {
    add_action('woocommerce_checkout_order_processed', [__CLASS__, 'maybe_schedule_aml'], 20, 1);
    add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'maybe_schedule_aml'], 20, 1);
    add_filter('woocommerce_order_actions', [__CLASS__, 'order_actions'], 10, 1);
    add_action('woocommerce_order_action_didit_aml_screen', [__CLASS__, 'order_action_screen']);
    add_action('woocommerce_order_status_changed', [__CLASS__, 'enforce_holds'], 21, 4);

    add_action('woocommerce_thankyou', [__CLASS__, 'contact_box_echo'], 12);
    add_action('woocommerce_view_order', [__CLASS__, 'contact_box_echo'], 6);
    add_filter('render_block_woocommerce/order-confirmation-status', [__CLASS__, 'contact_box_block'], 20, 1);
  }

  public static function sanitize_amount($value): float
  {
    return max(0, round((float) $value, 2));
  }

  /* ── Settings ── */

  public static function field_aml()
  {
    printf(
      '<label><input type="checkbox" name="didit_aml_enabled" value="1" %s /> %s</label>
      <p style="margin-top:.5rem">%s <input type="number" step="0.01" min="0" name="didit_aml_threshold" value="%s" style="width:8em" /></p>
      <p><label><input type="checkbox" name="didit_aml_monitoring" value="1" %s /> %s</label></p>
      <p><label><input type="checkbox" name="didit_aml_hold" value="1" %s /> %s</label></p>
      <p class="description">%s</p>',
      checked((bool) get_option('didit_aml_enabled', false), true, false),
      esc_html__('Screen the billing name against sanctions and PEP lists', 'didit-verify'),
      esc_html__('When the order total is at least', 'didit-verify'),
      esc_attr((string) get_option('didit_aml_threshold', 0)),
      checked((bool) get_option('didit_aml_monitoring', false), true, false),
      esc_html__('Keep screened customers under ongoing monitoring', 'didit-verify'),
      checked((bool) get_option('didit_aml_hold', false), true, false),
      esc_html__('Put the order on hold when the screening needs review or is declined', 'didit-verify'),
      esc_html__('Runs in the background after the order is placed, in the store currency. The result appears on the order. Admins can run it again from the order actions.', 'didit-verify')
    );
  }

  public static function field_contact()
  {
    printf(
      '<label><input type="checkbox" name="didit_contact_phone" value="1" %s /> %s</label><br />
      <label><input type="checkbox" name="didit_contact_email" value="1" %s /> %s</label>
      <p class="description">%s</p>',
      checked((bool) get_option('didit_contact_phone', false), true, false),
      esc_html__('Ask customers to confirm the billing phone with a one-time code', 'didit-verify'),
      checked((bool) get_option('didit_contact_email', false), true, false),
      esc_html__('Ask customers to confirm the billing email with a one-time code', 'didit-verify'),
      esc_html__('Shown on the order confirmation page and in My Account → Orders, for classic and block checkout. Codes go only to the contact on the order.', 'didit-verify')
    );
  }

  /* ── AML screening ── */

  public static function maybe_schedule_aml($order)
  {
    $order = is_object($order) ? $order : wc_get_order($order);
    if (!$order || !get_option('didit_aml_enabled', false) || !get_option('didit_api_key', '')
      || $order->get_meta('_didit_aml_requested')
      || (float) $order->get_total() < (float) get_option('didit_aml_threshold', 0)
      || '' === self::billing_name($order)) {
      return;
    }
    $order->update_meta_data('_didit_aml_requested', 'yes');
    $order->save();
    if (function_exists('as_enqueue_async_action')) {
      as_enqueue_async_action(self::AML_HOOK, [$order->get_id()], 'didit-verify');
    } else {
      wp_schedule_single_event(time(), self::AML_HOOK, [$order->get_id()]);
    }
  }

  private static function billing_name($order): string
  {
    $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    return sanitize_text_field('' !== $name ? $name : (string) $order->get_billing_company());
  }

  /** The same order and name always reuse one screening, so a retried job is never billed twice. */
  public static function idempotency_key(int $order_id, string $name, int $run = 0): string
  {
    return substr(hash('sha256', home_url('/') . '|order|' . $order_id . '|' . $name . '|' . $run), 0, 64);
  }

  public static function order_actions($actions)
  {
    if (get_option('didit_api_key', '')) {
      $actions['didit_aml_screen'] = __('Run Didit AML screening', 'didit-verify');
    }
    return $actions;
  }

  public static function order_action_screen($order)
  {
    $order->update_meta_data('_didit_aml_runs', (int) $order->get_meta('_didit_aml_runs') + 1);
    $order->save();
    self::screen_order($order->get_id(), true);
  }

  /**
   * Runs one screening. The background job is the automatic path, so it checks again,
   * when it actually runs, that the admin still wants automatic screening for this order:
   * a backlog queued before the setting was turned off must not send billing data.
   * An explicit admin rerun from the order actions ($manual) is always allowed.
   */
  public static function screen_order($order_id, $manual = false)
  {
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (!$order) {
      return;
    }
    if (!$manual && (!get_option('didit_aml_enabled', false)
      || (float) $order->get_total() < (float) get_option('didit_aml_threshold', 0))) {
      $order->add_order_note(__('Didit: automatic AML screening skipped because it is no longer enabled for this order.', 'didit-verify'));
      return;
    }
    $name = self::billing_name($order);
    if ('' === $name) {
      return;
    }
    $is_person = '' !== trim($order->get_billing_first_name() . $order->get_billing_last_name());
    $body = [
      'full_name' => $name,
      'entity_type' => $is_person ? 'person' : 'company',
      'include_ongoing_monitoring' => (bool) get_option('didit_aml_monitoring', false),
      'save_api_request' => true,
      'vendor_data' => $order->get_customer_id() ? 'wp-' . $order->get_customer_id() : 'order-' . $order->get_id(),
    ];
    $data = Didit_Verify_Api::request('POST', '/v3/aml/', $body, [
      'Idempotency-Key' => self::idempotency_key($order->get_id(), $name, (int) $order->get_meta('_didit_aml_runs')),
    ], 60);
    if (is_wp_error($data)) {
      $order->add_order_note(sprintf(
        /* translators: %s: error message. */
        __('Didit: AML screening could not run (%s). Run it again from the order actions.', 'didit-verify'),
        $data->get_error_message()
      ));
      return;
    }
    $aml = is_array($data['aml'] ?? null) ? $data['aml'] : [];
    // The screening response does not repeat the monitoring choice, so record what was requested.
    if (!isset($aml['is_ongoing_monitoring_enabled'])) {
      $aml['is_ongoing_monitoring_enabled'] = $body['include_ongoing_monitoring'];
    }
    $summary = Didit_Verify_Report::aml($aml);
    $summary['screened_at'] = time();
    $order->update_meta_data('_didit_aml', $summary);
    self::add_result_note($order, sprintf(
      /* translators: 1: AML status, 2: number of hits. */
      __('Didit: AML screening of the billing name: %1$s, %2$d hit(s).', 'didit-verify'),
      $summary['status'] ?? '',
      (int) ($summary['hits_total'] ?? 0)
    ));
    $flagged = in_array($summary['status'] ?? '', ['In Review', 'Declined'], true);
    if ($flagged && get_option('didit_aml_hold', false)) {
      // The reason is kept whatever the current status, so an identity approval cannot
      // release the order while this result is outstanding. Unpaid orders keep their status
      // and are held by enforce_holds() when payment moves them to processing or completed.
      self::add_hold_reason($order, 'aml');
      $order->save();
      if ($order->has_status(['processing', 'completed'])) {
        self::hold($order, $order->has_status('completed') ? 'completed' : 'processing');
      }
      return;
    }
    if (!$flagged && in_array('aml', self::hold_reasons($order), true)) {
      // A clean rerun resolves the AML reason; the order is released only if nothing else is outstanding.
      self::remove_hold_reason($order, 'aml');
      $order->save();
      self::release_hold($order, __('Didit: AML screening cleared - order released.', 'didit-verify'));
      return;
    }
    $order->save();
  }

  /* ── Order holds ── */

  const HOLD_REASONS = '_didit_hold_reasons';

  /**
   * Add a Didit result note to the order and mark it, so uninstall and the personal-data
   * eraser can remove exactly these notes without touching the merchant's own notes.
   *
   * @return int The note (comment) ID, or 0.
   */
  public static function add_result_note($order, string $text): int
  {
    $note_id = (int) $order->add_order_note($text);
    if ($note_id > 0 && function_exists('add_comment_meta')) {
      add_comment_meta($note_id, '_didit_result_note', 1, true);
    }
    return $note_id;
  }

  /** Delete the Didit result notes of one order. Returns the number of notes deleted. */
  public static function delete_result_notes(int $order_id): int
  {
    if ($order_id <= 0 || !function_exists('get_comments')) {
      return 0;
    }
    // WooCommerce hides order notes from comment queries; lift that filter as wc_get_order_notes() does.
    $filter = ['WC_Comments', 'exclude_order_comments'];
    $removed = remove_filter('comments_clauses', $filter, 10);
    $ids = get_comments([
      'post_id' => $order_id,
      'type' => 'order_note',
      'status' => 'any',
      'meta_key' => '_didit_result_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- erasure of a few notes.
      'fields' => 'ids',
      'number' => 0,
    ]);
    if ($removed) {
      add_filter('comments_clauses', $filter, 10, 1);
    }
    $deleted = 0;
    foreach ((array) $ids as $id) {
      $deleted += wp_delete_comment((int) $id, true) ? 1 : 0;
    }
    return $deleted;
  }

  /** Outstanding reasons ('identity', 'aml') for which Didit keeps the order from being fulfilled. */
  public static function hold_reasons($order): array
  {
    $reasons = $order->get_meta(self::HOLD_REASONS);
    return is_array($reasons) ? array_values(array_intersect(['identity', 'aml'], $reasons)) : [];
  }

  /** Callers save the order. */
  public static function add_hold_reason($order, string $reason)
  {
    $reasons = self::hold_reasons($order);
    if (!in_array($reason, $reasons, true)) {
      $reasons[] = $reason;
      $order->update_meta_data(self::HOLD_REASONS, $reasons);
    }
  }

  /** Callers save the order. */
  public static function remove_hold_reason($order, string $reason)
  {
    $reasons = self::hold_reasons($order);
    if (in_array($reason, $reasons, true)) {
      $order->update_meta_data(self::HOLD_REASONS, array_values(array_diff($reasons, [$reason])));
    }
  }

  /** Put the order on hold for its outstanding reasons, remembering the status to return to. */
  private static function hold($order, string $from)
  {
    $order->update_meta_data('_didit_held', 'yes');
    $order->update_meta_data('_didit_held_from', in_array($from, ['processing', 'completed'], true) ? $from : 'processing');
    $order->save();
    $order->update_status('on-hold');
    self::add_result_note($order, sprintf(
      /* translators: %s: list of outstanding checks. */
      __('Didit: order held until these checks are resolved: %s.', 'didit-verify'),
      implode(', ', self::reason_labels(self::hold_reasons($order)))
    ));
  }

  private static function reason_labels(array $reasons): array
  {
    $labels = ['identity' => __('identity verification', 'didit-verify'), 'aml' => __('AML screening', 'didit-verify')];
    return array_map(function ($reason) use ($labels) {
      return $labels[$reason] ?? $reason;
    }, $reasons);
  }

  /**
   * Release an order Didit put on hold, but only once no reason is outstanding.
   * Returns true when the order was released.
   */
  public static function release_hold($order, string $note): bool
  {
    if ('yes' !== $order->get_meta('_didit_held') || !$order->has_status('on-hold') || self::hold_reasons($order)) {
      return false;
    }
    $to = (string) $order->get_meta('_didit_held_from') ?: 'processing';
    // Cleared before the status change so enforce_holds() does not take it for a manual release.
    $order->delete_meta_data('_didit_held');
    $order->delete_meta_data('_didit_held_from');
    $order->save();
    $order->update_status($to);
    self::add_result_note($order, $note);
    return true;
  }

  /**
   * woocommerce_order_status_changed: gate fulfillment on every outstanding Didit reason.
   *
   * Manual merchant edits win: when an order Didit held leaves on-hold through anything
   * other than release_hold(), the merchant released it. The outstanding reasons are then
   * cleared and noted, and the order is not held again for them (the core identity check
   * honours `_didit_hold_released`). Only a new flagged AML result can hold it again.
   */
  public static function enforce_holds($order_id, $from, $to, $order = null)
  {
    static $busy = false;
    $order = is_object($order) ? $order : (function_exists('wc_get_order') ? wc_get_order($order_id) : null);
    if ($busy || !$order) {
      return;
    }
    if ('on-hold' === $from && 'on-hold' !== $to && 'yes' === $order->get_meta('_didit_held')) {
      $reasons = self::hold_reasons($order);
      $order->delete_meta_data('_didit_held');
      $order->delete_meta_data('_didit_held_from');
      $order->update_meta_data(self::HOLD_REASONS, []);
      $order->update_meta_data('_didit_hold_released', 'yes');
      $order->save();
      if ($reasons) {
        $order->add_order_note(sprintf(
          /* translators: %s: list of outstanding checks. */
          __('Didit: hold released manually while these checks were outstanding: %s. Didit will not hold this order again for them.', 'didit-verify'),
          implode(', ', self::reason_labels($reasons))
        ));
      }
      return;
    }
    if (!in_array($to, ['processing', 'completed'], true) || !$order->has_status($to) || !self::hold_reasons($order)) {
      return;
    }
    $busy = true;
    self::hold($order, (string) $to);
    $busy = false;
  }

  public static function render_order_results($order)
  {
    $aml = $order->get_meta('_didit_aml');
    $rows = [];
    if (is_array($aml) && $aml) {
      $rows[] = $aml;
    }
    foreach (['phone', 'email'] as $channel) {
      $status = (string) $order->get_meta('_didit_' . $channel . '_status');
      if ('Approved' === $status && !self::contact_approved($order, $channel)) {
        $status = __('Contact changed since it was verified', 'didit-verify');
      }
      if ($status) {
        $rows[] = ['type' => $channel, 'status' => $status];
      }
    }
    if ($rows) {
      echo '<div class="didit-admin-order"><strong>' . esc_html__('Didit checks on this order:', 'didit-verify') . '</strong>';
      echo Didit_Verify_Report::render(['checks' => $rows]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by helper.
      echo '</div>';
    }
  }

  /* ── Phone and email codes ── */

  public static function enabled_channels(): array
  {
    return array_values(array_filter([
      get_option('didit_contact_phone', false) ? 'phone' : '',
      get_option('didit_contact_email', false) ? 'email' : '',
    ]));
  }

  public static function register_routes()
  {
    foreach (['send', 'check'] as $step) {
      register_rest_route('didit/v1', '/contact/' . $step, [
        'methods' => 'POST',
        'callback' => [__CLASS__, 'rest_contact_' . $step],
        'permission_callback' => [__CLASS__, 'contact_permission'],
      ]);
    }
  }

  /**
   * The order key alone is not enough: the request must also carry the access token that
   * contact_box() only hands out after WooCommerce's own order-detail checks passed
   * (see customer_can_access()), and an order with an account needs its logged-in owner.
   */
  public static function contact_permission($request)
  {
    $nonce = $request->get_header('x_wp_nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
      return new WP_Error('rest_forbidden', __('Invalid security token.', 'didit-verify'), ['status' => 403]);
    }
    $order = self::request_order($request);
    $input = $request->get_json_params();
    $access = is_array($input) ? sanitize_text_field((string) ($input['access'] ?? '')) : '';
    if (!$order || '' === $access || !wp_verify_nonce($access, self::access_action($order))
      || ($order->get_customer_id() && get_current_user_id() !== (int) $order->get_customer_id())) {
      return new WP_Error('rest_forbidden', __('Order not found.', 'didit-verify'), ['status' => 403]);
    }
    return true;
  }

  /** Bound to the order and its key, and (through the nonce) to the visitor's login session. */
  private static function access_action($order): string
  {
    return 'didit_contact_' . (int) $order->get_id() . '|' . $order->get_order_key();
  }

  private static function request_order($request)
  {
    $input = $request->get_json_params();
    if (!is_array($input) || !function_exists('wc_get_order')) {
      return null;
    }
    $order = wc_get_order(absint($input['order_id'] ?? 0));
    $key = sanitize_text_field((string) ($input['order_key'] ?? ''));
    return $order && '' !== $key && hash_equals($order->get_order_key(), $key) ? $order : null;
  }

  private static function request_channel($request)
  {
    $input = $request->get_json_params();
    $channel = is_array($input) ? (string) ($input['channel'] ?? '') : '';
    return in_array($channel, self::enabled_channels(), true)
      ? $channel
      : new WP_Error('didit_channel_disabled', __('This check is not available.', 'didit-verify'), ['status' => 400]);
  }

  /** The order's own phone in international format, or its email. Empty when it cannot be determined safely. */
  public static function contact_value($order, string $channel): string
  {
    if ('email' === $channel) {
      return sanitize_email($order->get_billing_email());
    }
    $prefix = '';
    if (function_exists('WC') && WC()->countries && is_callable([WC()->countries, 'get_country_calling_code'])) {
      $prefix = WC()->countries->get_country_calling_code($order->get_billing_country());
      $prefix = is_array($prefix) ? (string) reset($prefix) : (string) $prefix;
    }
    return self::phone_e164((string) $order->get_billing_phone(), (string) $order->get_billing_country(), $prefix);
  }

  /** Countries whose national numbers start with a trunk 0 that is dropped after the country code. */
  const TRUNK_ZERO = ['AT', 'AU', 'BA', 'BD', 'BE', 'BG', 'CH', 'CN', 'DE', 'EG', 'FI', 'FR', 'GB', 'GG', 'HR', 'ID',
    'IE', 'IL', 'IM', 'IN', 'JE', 'JP', 'KE', 'KR', 'LK', 'ME', 'MK', 'MY', 'NG', 'NL', 'NZ', 'PH', 'PK', 'RO', 'RS',
    'SE', 'SI', 'SK', 'TH', 'TR', 'TW', 'UA', 'VN', 'ZA'];
  /** Countries without a trunk prefix: the national number follows the country code unchanged. */
  const NO_TRUNK = ['CY', 'CZ', 'DK', 'EE', 'ES', 'GR', 'HK', 'IS', 'LU', 'LV', 'MO', 'MT', 'NO', 'PL', 'PT', 'SG'];
  /** Countries whose leading 0 is part of the number and is kept after the country code. */
  const KEEP_ZERO = ['IT', 'SM', 'VA'];
  /** North American Numbering Plan members with WooCommerce calling code +1. */
  const NANP = ['US', 'CA', 'PR', 'VI', 'GU', 'AS', 'MP'];

  /**
   * Convert a billing phone to E.164 without guessing. Numbers written with + or 00 are
   * taken as international. National numbers are converted only for countries whose
   * trunk-prefix rule is known (a trunk 0 is dropped, Italy's leading 0 is kept); anything
   * else returns '' so no code is sent to a possibly different number.
   */
  public static function phone_e164(string $raw, string $country, string $calling_code): string
  {
    $raw = trim($raw);
    $digits = (string) preg_replace('/\D+/', '', $raw);
    $code = (string) preg_replace('/\D+/', '', $calling_code);
    $country = strtoupper($country);
    if ('VA' === $country) {
      $code = '39';
    }
    $number = '';
    if ('' === $digits) {
      return '';
    } elseif (0 === strpos($raw, '+')) {
      $number = $digits;
    } elseif (0 === strpos($digits, '00')) {
      $number = substr($digits, 2);
    } elseif ('' === $code) {
      return '';
    } elseif (in_array($country, self::KEEP_ZERO, true)) {
      $number = $code . $digits;
    } elseif (in_array($country, self::NANP, true) && '1' === $code) {
      if (10 === strlen($digits) && '1' !== $digits[0]) {
        $number = '1' . $digits;
      } elseif (11 === strlen($digits) && '1' === $digits[0]) {
        $number = $digits;
      }
    } elseif (in_array($country, self::TRUNK_ZERO, true)) {
      $number = $code . ('0' === $digits[0] ? substr($digits, 1) : $digits);
    } elseif (in_array($country, self::NO_TRUNK, true) && '0' !== $digits[0]) {
      $number = $code . $digits;
    }
    $length = strlen($number);
    return $length >= 8 && $length <= 15 && '0' !== $number[0] ? '+' . $number : '';
  }

  /** A billing phone is present but cannot be turned into an international number safely. */
  private static function phone_needs_international($order): bool
  {
    return '' !== trim((string) $order->get_billing_phone()) && '' === self::contact_value($order, 'phone');
  }

  /** Keyed hash of a normalized contact, used to bind approvals and to count requests per recipient. */
  public static function contact_hash(string $channel, string $value): string
  {
    $secret = function_exists('wp_salt') ? wp_salt('auth') : home_url('/');
    return hash_hmac('sha256', $channel . '|' . strtolower($value), $secret);
  }

  /** Approved, and for the contact that is on the order now (not one a merchant has since replaced). */
  public static function contact_approved($order, string $channel): bool
  {
    $value = self::contact_value($order, $channel);
    $bound = (string) $order->get_meta('_didit_' . $channel . '_verified_hash');
    return 'Approved' === $order->get_meta('_didit_' . $channel . '_status') && '' !== $value && '' !== $bound
      && hash_equals($bound, self::contact_hash($channel, $value));
  }

  const MAX_RECIPIENT_SENDS = 5;
  const MAX_REQUESTER_SENDS = 10;
  const MAX_REQUESTER_CHECKS = 20;

  private static function requester(): string
  {
    if (is_user_logged_in()) {
      return 'u' . get_current_user_id();
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    return 'ip' . $ip;
  }

  /**
   * Budgets checked before every paid call: per order, channel and step; per recipient
   * (across orders) for sends; and per requester (user or IP). All buckets are counted
   * under one lock, and nothing is counted unless every bucket has room.
   */
  private static function over_limit($order, string $channel, string $step, string $value = ''): bool
  {
    $requester = substr(hash('sha256', self::requester()), 0, 32);
    $buckets = ['didit_contact_' . $step . '_' . $channel . '_' . $order->get_id() => self::MAX_CONTACT_ATTEMPTS];
    if ('send' === $step) {
      $buckets['didit_contact_to_' . substr(self::contact_hash($channel, $value), 0, 32)] = self::MAX_RECIPIENT_SENDS;
      $buckets['didit_contact_by_send_' . $requester] = self::MAX_REQUESTER_SENDS;
    } else {
      $buckets['didit_contact_by_check_' . $requester] = self::MAX_REQUESTER_CHECKS;
    }
    return !self::consume($buckets);
  }

  private static function consume(array $buckets): bool
  {
    if (!self::lock()) {
      return false;
    }
    $now = time();
    $ok = true;
    foreach ($buckets as $key => $max) {
      $bucket = get_transient($key);
      if (is_array($bucket) && $bucket[1] > $now && $bucket[0] >= $max) {
        $ok = false;
      }
    }
    if ($ok) {
      foreach (array_keys($buckets) as $key) {
        $bucket = get_transient($key);
        $bucket = is_array($bucket) && $bucket[1] > $now ? [$bucket[0] + 1, $bucket[1]] : [1, $now + HOUR_IN_SECONDS];
        set_transient($key, $bucket, max(1, $bucket[1] - $now));
      }
    }
    self::unlock();
    return $ok;
  }

  const LOCK = 'didit_contact_lock';

  /** An options row inserted with INSERT IGNORE is an atomic mutex; a lock older than 30 s is stale. */
  private static function lock(): bool
  {
    global $wpdb;
    for ($i = 0; $i < 20; $i++) {
      if (is_object($wpdb) && isset($wpdb->options)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic insert is the point; the row is never cached.
        $got = 1 === (int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) time()));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $since = $got ? 0 : (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK));
      } else {
        $got = add_option(self::LOCK, (string) time(), '', 'no');
        $since = $got ? 0 : (int) get_option(self::LOCK, 0);
      }
      if ($got) {
        return true;
      }
      if ($since && $since < time() - 30) {
        self::unlock();
        continue;
      }
      usleep(50000);
    }
    return false;
  }

  private static function unlock()
  {
    global $wpdb;
    if (is_object($wpdb) && isset($wpdb->options)) {
      $wpdb->delete($wpdb->options, ['option_name' => self::LOCK]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    } else {
      delete_option(self::LOCK);
    }
  }

  private static function international_error(): WP_Error
  {
    return new WP_Error('didit_phone_not_international', __('We cannot tell the country code of the phone on this order. Please contact the store to update it in international format (for example +44 20 7946 0000).', 'didit-verify'), ['status' => 400]);
  }

  public static function rest_contact_send($request)
  {
    $order = self::request_order($request);
    $channel = self::request_channel($request);
    if (is_wp_error($channel)) {
      return $channel;
    }
    if (self::contact_approved($order, $channel)) {
      return rest_ensure_response(['status' => 'Approved']);
    }
    $value = self::contact_value($order, $channel);
    if ('' === $value) {
      return 'phone' === $channel && self::phone_needs_international($order)
        ? self::international_error()
        : new WP_Error('didit_contact_missing', __('The order has no contact to verify.', 'didit-verify'), ['status' => 400]);
    }
    if (self::over_limit($order, $channel, 'send', $value)) {
      return new WP_Error('rate_limit', __('Too many codes requested. Please try again later.', 'didit-verify'), ['status' => 429]);
    }
    $body = 'phone' === $channel
      ? ['phone_number' => $value, 'vendor_data' => 'order-' . $order->get_id()]
      : ['email' => $value, 'vendor_data' => 'order-' . $order->get_id()];
    $data = Didit_Verify_Api::request('POST', '/v3/' . $channel . '/send/', $body, [], 20);
    if (is_wp_error($data)) {
      return $data;
    }
    // "Retry" means a code was sent again while an earlier one was still pending.
    $status = (string) ($data['status'] ?? '');
    if (!in_array($status, ['Success', 'Retry'], true)) {
      return new WP_Error('didit_contact_not_sent', __('We could not send a code to this contact. Please contact the store.', 'didit-verify'), ['status' => 422]);
    }
    $order->update_meta_data('_didit_' . $channel . '_sent_hash', self::contact_hash($channel, $value));
    $order->save();
    return rest_ensure_response(['status' => 'Sent']);
  }

  public static function rest_contact_check($request)
  {
    $order = self::request_order($request);
    $channel = self::request_channel($request);
    if (is_wp_error($channel)) {
      return $channel;
    }
    $input = $request->get_json_params();
    $code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['code'] ?? ''));
    if ('' === $code || strlen($code) > 12) {
      return new WP_Error('didit_code_invalid', __('Enter the code you received.', 'didit-verify'), ['status' => 400]);
    }
    $value = self::contact_value($order, $channel);
    $hash = '' !== $value ? self::contact_hash($channel, $value) : '';
    $sent = (string) $order->get_meta('_didit_' . $channel . '_sent_hash');
    if ('' === $hash || '' === $sent || !hash_equals($sent, $hash)) {
      return new WP_Error('didit_code_stale', __('The contact on this order has changed. Request a new code.', 'didit-verify'), ['status' => 409]);
    }
    if (self::over_limit($order, $channel, 'check')) {
      return new WP_Error('rate_limit', __('Too many attempts. Please try again later.', 'didit-verify'), ['status' => 429]);
    }
    $body = 'phone' === $channel ? ['phone_number' => $value, 'code' => $code] : ['email' => $value, 'code' => $code];
    $data = Didit_Verify_Api::request('POST', '/v3/' . $channel . '/check/', $body, [], 20);
    if (is_wp_error($data)) {
      return $data;
    }
    $result = (string) ($data['status'] ?? '');
    if ('Approved' !== $result) {
      return new WP_Error('didit_code_rejected', __('That code is not valid. Check it or request a new one.', 'didit-verify'), ['status' => 422]);
    }
    $order->update_meta_data('_didit_' . $channel . '_status', 'Approved');
    $order->update_meta_data('_didit_' . $channel . '_verified_hash', $hash);
    self::add_result_note($order, 'phone' === $channel
      ? __('Didit: billing phone verified with a one-time code.', 'didit-verify')
      : __('Didit: billing email verified with a one-time code.', 'didit-verify'));
    $order->save();
    return rest_ensure_response(['status' => 'Approved']);
  }

  /**
   * Show contact details only where WooCommerce itself shows the order details:
   *
   * - An order placed with an account: only its logged-in owner. WooCommerce asks anyone
   *   else to log in (filter woocommerce_order_received_verify_known_shoppers); we never
   *   show the contacts to them, even when that filter is turned off.
   * - A guest order: a valid order key in the URL AND no pending email check. WooCommerce
   *   asks for the billing email (classic and block confirmation) unless the order is in
   *   the visitor's session, was placed within the grace period, or the email was entered;
   *   we ask WooCommerce for that decision and, on versions without the helper, apply the
   *   same signals conservatively. When unsure, the box is hidden.
   */
  public static function customer_can_access($order): bool
  {
    if (!$order) {
      return false;
    }
    $customer_id = (int) $order->get_customer_id();
    if ($customer_id) {
      return get_current_user_id() === $customer_id;
    }
    $url_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability check, not a state change.
    if ('' === $url_key || !hash_equals($order->get_order_key(), $url_key)) {
      return false;
    }
    return !self::guest_email_check_required($order);
  }

  private static function guest_email_check_required($order): bool
  {
    if (is_callable(['WC_Shortcode_Checkout', 'guest_should_verify_email'])) {
      // Also honours the email posted to WooCommerce's own verification form.
      return (bool) WC_Shortcode_Checkout::guest_should_verify_email($order, 'order-received');
    }
    $users = 'Automattic\\WooCommerce\\Utilities\\Users';
    if (is_callable([$users, 'should_user_verify_order_email'])) {
      return (bool) call_user_func([$users, 'should_user_verify_order_email'], (int) $order->get_id(), null, 'order-received');
    }
    // Older WooCommerce: the same signals as the block confirmation page.
    $session = function_exists('WC') && is_object(WC()) && isset(WC()->session) ? WC()->session : null;
    if (is_object($session) && is_callable([$session, 'get'])) {
      if ((int) $order->get_id() === (int) $session->get('store_api_draft_order')) {
        return false;
      }
      $customer = $session->get('customer');
      $email = is_array($customer) ? (string) ($customer['email'] ?? '') : '';
      if ('' !== $email && '' !== (string) $order->get_billing_email() && 0 === strcasecmp($email, (string) $order->get_billing_email())) {
        return false;
      }
    }
    $created = is_callable([$order, 'get_date_created']) ? $order->get_date_created() : null;
    $grace = (int) apply_filters('woocommerce_order_email_verification_grace_period', 10 * MINUTE_IN_SECONDS, $order, 'order-received');
    if (is_object($created) && method_exists($created, 'getTimestamp') && time() - $created->getTimestamp() <= $grace) {
      return false;
    }
    return (bool) apply_filters('woocommerce_order_email_verification_required', true, $order, 'order-received');
  }

  public static function contact_box($order): string
  {
    static $rendered = [];
    $channels = self::enabled_channels();
    if (!$channels || !$order || isset($rendered[$order->get_id()]) || !self::customer_can_access($order)) {
      return '';
    }
    $rows = '';
    foreach ($channels as $channel) {
      $value = self::contact_value($order, $channel);
      $label = 'phone' === $channel ? __('Phone', 'didit-verify') : __('Email', 'didit-verify');
      if ('' === $value) {
        if ('phone' === $channel && self::phone_needs_international($order)) {
          $rows .= sprintf('<p class="didit-contact-note">%s: %s</p>', esc_html($label), esc_html(self::international_error()->get_error_message()));
        }
        continue;
      }
      if (self::contact_approved($order, $channel)) {
        $rows .= sprintf('<p class="didit-contact-done">%s: %s ✓</p>', esc_html($label), esc_html($value));
        continue;
      }
      $rows .= sprintf(
        '<div class="didit-contact" data-channel="%1$s">
          <p><strong>%2$s</strong> %3$s</p>
          <button type="button" class="button didit-contact-send">%4$s</button>
          <span class="didit-contact-code" hidden>
            <label class="screen-reader-text" for="didit-code-%1$s">%5$s</label>
            <input type="text" inputmode="numeric" autocomplete="one-time-code" id="didit-code-%1$s" maxlength="12" placeholder="%5$s" />
            <button type="button" class="button didit-contact-check">%6$s</button>
          </span>
          <p class="didit-feedback" role="status" aria-live="polite"></p>
        </div>',
        esc_attr($channel),
        esc_html($label),
        esc_html($value),
        esc_html__('Send code', 'didit-verify'),
        esc_attr__('Code', 'didit-verify'),
        esc_html__('Confirm', 'didit-verify')
      );
    }
    if ('' === $rows) {
      return '';
    }
    $rendered[$order->get_id()] = true;
    wp_enqueue_script('didit-contact', DIDIT_VERIFY_URL . 'assets/js/didit-contact.js', [], DIDIT_VERIFY_VERSION, true);
    wp_localize_script('didit-contact', 'diditContact', [
      'restUrl' => esc_url_raw(rest_url('didit/v1/contact/')),
      'nonce' => wp_create_nonce('wp_rest'),
      'orderId' => (int) $order->get_id(),
      'orderKey' => $order->get_order_key(),
      'access' => wp_create_nonce(self::access_action($order)),
      'i18n' => [
        'sent' => __('Code sent. Enter it below.', 'didit-verify'),
        'verified' => __('Verified. Thank you!', 'didit-verify'),
        'error' => __('Something went wrong. Please try again.', 'didit-verify'),
      ],
    ]);
    return sprintf(
      '<section class="didit-verify-wrap didit-contact-box"><h3>%s</h3><p>%s</p>%s</section>',
      esc_html__('Confirm your contact details', 'didit-verify'),
      esc_html__('We send a one-time code to the contact on this order.', 'didit-verify'),
      $rows
    );
  }

  public static function contact_box_echo($order_id)
  {
    echo self::contact_box(wc_get_order($order_id)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
  }

  public static function contact_box_block($content)
  {
    global $wp;
    $order = wc_get_order(absint($wp->query_vars['order-received'] ?? 0));
    return $order ? $content . self::contact_box($order) : $content;
  }
}
