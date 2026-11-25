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
    self::screen_order($order->get_id());
  }

  public static function screen_order($order_id)
  {
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (!$order) {
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
    $summary = Didit_Verify_Report::aml(is_array($data['aml'] ?? null) ? $data['aml'] : []);
    $summary['screened_at'] = time();
    $order->update_meta_data('_didit_aml', $summary);
    $order->add_order_note(sprintf(
      /* translators: 1: AML status, 2: number of hits. */
      __('Didit: AML screening of the billing name: %1$s, %2$d hit(s).', 'didit-verify'),
      $summary['status'] ?? '',
      (int) ($summary['hits_total'] ?? 0)
    ));
    $order->save();
    if (get_option('didit_aml_hold', false) && in_array($summary['status'] ?? '', ['In Review', 'Declined'], true)
      && $order->has_status(['pending', 'processing'])) {
      $order->update_status('on-hold', __('Didit: order held until the AML screening is reviewed.', 'didit-verify'));
    }
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

  /** Only someone holding the order key, from this site's pages, can run the checks. */
  public static function contact_permission($request)
  {
    $nonce = $request->get_header('x_wp_nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
      return new WP_Error('rest_forbidden', __('Invalid security token.', 'didit-verify'), ['status' => 403]);
    }
    return self::request_order($request) ? true : new WP_Error('rest_forbidden', __('Order not found.', 'didit-verify'), ['status' => 403]);
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

  /** The order's own phone in international format, or its email. */
  public static function contact_value($order, string $channel): string
  {
    if ('email' === $channel) {
      return sanitize_email($order->get_billing_email());
    }
    $raw = trim((string) $order->get_billing_phone());
    $digits = preg_replace('/\D+/', '', $raw);
    if ('' === $digits) {
      return '';
    }
    if (0 === strpos($raw, '+')) {
      return '+' . $digits;
    }
    if (0 === strpos($digits, '00')) {
      return '+' . substr($digits, 2);
    }
    $prefix = '';
    if (function_exists('WC') && WC()->countries && is_callable([WC()->countries, 'get_country_calling_code'])) {
      $prefix = (string) WC()->countries->get_country_calling_code($order->get_billing_country());
    }
    return '' !== $prefix ? '+' . ltrim($prefix, '+') . ltrim($digits, '0') : '';
  }

  private static function over_limit($order, string $channel, string $step): bool
  {
    $key = 'didit_contact_' . $step . '_' . $channel . '_' . $order->get_id();
    $count = (int) get_transient($key);
    if ($count >= self::MAX_CONTACT_ATTEMPTS) {
      return true;
    }
    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    return false;
  }

  public static function rest_contact_send($request)
  {
    $order = self::request_order($request);
    $channel = self::request_channel($request);
    if (is_wp_error($channel)) {
      return $channel;
    }
    if ('Approved' === $order->get_meta('_didit_' . $channel . '_status')) {
      return rest_ensure_response(['status' => 'Approved']);
    }
    $value = self::contact_value($order, $channel);
    if ('' === $value) {
      return new WP_Error('didit_contact_missing', __('The order has no contact to verify.', 'didit-verify'), ['status' => 400]);
    }
    if (self::over_limit($order, $channel, 'send')) {
      return new WP_Error('rate_limit', __('Too many codes requested. Please try again later.', 'didit-verify'), ['status' => 429]);
    }
    $body = 'phone' === $channel
      ? ['phone_number' => $value, 'vendor_data' => 'order-' . $order->get_id()]
      : ['email' => $value, 'vendor_data' => 'order-' . $order->get_id()];
    $data = Didit_Verify_Api::request('POST', '/v3/' . $channel . '/send/', $body, [], 20);
    if (is_wp_error($data)) {
      return $data;
    }
    $status = (string) ($data['status'] ?? '');
    if ('Success' !== $status) {
      return new WP_Error('didit_contact_not_sent', __('We could not send a code to this contact. Please contact the store.', 'didit-verify'), ['status' => 422]);
    }
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
    if (self::over_limit($order, $channel, 'check')) {
      return new WP_Error('rate_limit', __('Too many attempts. Please try again later.', 'didit-verify'), ['status' => 429]);
    }
    $value = self::contact_value($order, $channel);
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
    $order->add_order_note('phone' === $channel
      ? __('Didit: billing phone verified with a one-time code.', 'didit-verify')
      : __('Didit: billing email verified with a one-time code.', 'didit-verify'));
    $order->save();
    return rest_ensure_response(['status' => 'Approved']);
  }

  private static function customer_can_access($order): bool
  {
    $url_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability check, not a state change.
    if ($url_key && hash_equals($order->get_order_key(), $url_key)) {
      return true;
    }
    return $order->get_customer_id() && get_current_user_id() === (int) $order->get_customer_id();
  }

  public static function contact_box($order): string
  {
    static $rendered = false;
    $channels = self::enabled_channels();
    if ($rendered || !$channels || !$order || !self::customer_can_access($order)) {
      return '';
    }
    $rows = '';
    foreach ($channels as $channel) {
      $value = self::contact_value($order, $channel);
      if ('' === $value) {
        continue;
      }
      $label = 'phone' === $channel ? __('Phone', 'didit-verify') : __('Email', 'didit-verify');
      if ('Approved' === $order->get_meta('_didit_' . $channel . '_status')) {
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
    $rendered = true;
    wp_enqueue_script('didit-contact', DIDIT_VERIFY_URL . 'assets/js/didit-contact.js', [], DIDIT_VERIFY_VERSION, true);
    wp_localize_script('didit-contact', 'diditContact', [
      'restUrl' => esc_url_raw(rest_url('didit/v1/contact/')),
      'nonce' => wp_create_nonce('wp_rest'),
      'orderId' => (int) $order->get_id(),
      'orderKey' => $order->get_order_key(),
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
