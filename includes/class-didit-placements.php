<?php
/**
 * Where verification appears on the site besides checkout: registration, age-restricted
 * products and categories, and the My Account "Verification" tab (people and businesses).
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Didit_Verify_Placements
{
  const ENDPOINT = 'didit-verification';

  /** @var Didit_Verify */
  private static $plugin;

  public static function init($plugin)
  {
    self::$plugin = $plugin;

    add_action('register_form', [__CLASS__, 'registration_box']);
    add_filter('registration_errors', [__CLASS__, 'registration_errors'], 10, 3);
    add_action('user_register', [__CLASS__, 'bind_registration_session'], 10, 1);
    // The first save adds the option instead of updating it, which fires add_option_*.
    foreach (['didit_my_account_enabled', 'didit_business_enabled'] as $option) {
      add_action('add_option_' . $option, [__CLASS__, 'flush_endpoints']);
      add_action('update_option_' . $option, [__CLASS__, 'flush_endpoints']);
    }

    add_action('woocommerce_loaded', [__CLASS__, 'wc_hooks']);
  }

  public static function wc_hooks()
  {
    add_action('woocommerce_register_form', [__CLASS__, 'registration_box']);
    // wc_create_new_customer() also applies this filter to accounts created at checkout.
    add_filter('woocommerce_registration_errors', [__CLASS__, 'registration_errors'], 10, 3);
    add_action('woocommerce_after_checkout_registration_form', [__CLASS__, 'checkout_registration_box']);
    add_filter('render_block_woocommerce/checkout', [__CLASS__, 'block_checkout_registration_box'], 10, 1);

    add_action('woocommerce_product_options_advanced', [__CLASS__, 'product_field']);
    add_action('woocommerce_admin_process_product_object', [__CLASS__, 'product_save']);
    add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'add_to_cart_validation'], 10, 4);
    add_action('woocommerce_store_api_validate_add_to_cart', [__CLASS__, 'store_api_add_to_cart'], 10, 1);
    add_action('woocommerce_check_cart_items', [__CLASS__, 'check_cart_items']);
    add_action('woocommerce_checkout_process', [__CLASS__, 'check_cart_items']);
    add_action('woocommerce_single_product_summary', [__CLASS__, 'product_box'], 25);

    add_filter('woocommerce_get_query_vars', [__CLASS__, 'query_vars']);
    add_filter('woocommerce_account_menu_items', [__CLASS__, 'account_menu']);
    add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'account_tab']);
  }

  /** Visitors without an account may start these placements when the admin allows them. */
  public static function guest_may_start($input): bool
  {
    $placement = is_array($input) && isset($input['placement']) && is_string($input['placement']) ? $input['placement'] : '';
    if ('registration' === $placement) {
      return Didit_Verify_Workflows::is_enabled('registration') && self::site_allows_registration();
    }
    if ('product_gate' === $placement) {
      return Didit_Verify_Workflows::is_enabled('product_gate');
    }
    return false;
  }

  /**
   * Whether visitors can create an account anywhere on the site: WordPress's own
   * "Anyone can register", or WooCommerce's independent My Account and checkout
   * account-creation settings.
   */
  public static function site_allows_registration(): bool
  {
    if (get_option('users_can_register', false)) {
      return true;
    }
    if (!class_exists('WooCommerce')) {
      return false;
    }
    return 'yes' === get_option('woocommerce_enable_myaccount_registration', 'no')
      || self::checkout_allows_registration();
  }

  private static function checkout_allows_registration(): bool
  {
    return 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout', 'no')
      || 'yes' === get_option('woocommerce_enable_delayed_account_creation', 'no');
  }

  /* ── Registration ── */

  private static function registration_markup(): string
  {
    return sprintf(
      '<div class="didit-registration">%s<input type="hidden" name="didit_registration_session_id" value="" /></div>',
      self::$plugin->render_shortcode(['placement' => 'registration', 'text' => __('Verify your identity to create an account', 'didit-verify')])
    );
  }

  public static function registration_box()
  {
    if (!Didit_Verify_Workflows::is_enabled('registration') || is_user_logged_in()) {
      return;
    }
    echo self::registration_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
  }

  /** Creating an account at checkout asks for the same registration check. */
  public static function checkout_registration_box()
  {
    if (!Didit_Verify_Workflows::is_enabled('registration') || is_user_logged_in() || !self::checkout_allows_registration()) {
      return;
    }
    printf('<p class="didit-registration-note">%s</p>', esc_html__('Creating an account needs an identity check.', 'didit-verify'));
    self::registration_box();
  }

  public static function block_checkout_registration_box($content)
  {
    if (!Didit_Verify_Workflows::is_enabled('registration') || is_user_logged_in() || !self::checkout_allows_registration()) {
      return $content;
    }
    return sprintf(
      '<div class="didit-checkout-registration"><p class="didit-registration-note">%s</p>%s</div>',
      esc_html__('Creating an account at checkout needs an identity check. Verify here first if you create one.', 'didit-verify'),
      self::registration_markup()
    ) . $content;
  }

  /**
   * The registration session for this request: the one the form posted, else the one
   * this browser started last (block checkout posts no form fields). Either way it only
   * counts if it is bound to this browser and was started for registration.
   */
  private static function posted_session(): string
  {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- the value is checked against the session bound to this browser.
    foreach (['didit_registration_session_id', 'didit_session_id'] as $field) {
      $posted = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
      if ('' !== $posted && self::registration_session_is_approved($posted, false)) {
        return $posted;
      }
    }
    // phpcs:enable
    return self::$plugin->visitor_registration_session();
  }

  /** The registration session this browser verified, if Didit approved it for the registration workflow. */
  public static function registration_session_is_approved(string $session_id, bool $confirm = true): bool
  {
    $plugin = self::$plugin;
    $record = $plugin->stored_session($session_id);
    if (
      !is_array($record) || !$plugin->session_belongs_to_request($record)
      || 0 !== (int) ($record['user_id'] ?? 0) || !empty($record['consumed_user_id'])
      || 'registration' !== ($record['placement'] ?? '')
      || $plugin->record_workflow($record) !== Didit_Verify_Workflows::workflow_for('registration')
    ) {
      return false;
    }
    return $confirm ? 'Approved' === $plugin->confirm_session($session_id) : 'Approved' === ($record['status'] ?? '');
  }

  public static function registration_errors($errors, $login = '', $email = '')
  {
    if (!Didit_Verify_Workflows::is_enabled('registration') || is_user_logged_in()) {
      return $errors;
    }
    if (!self::registration_session_is_approved(self::posted_session())) {
      $errors->add('didit_verification_required', __('Please verify your identity before creating your account.', 'didit-verify'));
    }
    return $errors;
  }

  /** Give the new account the approval its visitor earned, once. */
  public static function bind_registration_session($user_id)
  {
    $session_id = self::posted_session();
    if (!$user_id || is_user_logged_in() || !Didit_Verify_Workflows::is_enabled('registration')
      || !self::registration_session_is_approved($session_id, false)) {
      return;
    }
    $plugin = self::$plugin;
    $record = $plugin->stored_session($session_id);
    $record['user_id'] = (int) $user_id;
    $record['consumed_user_id'] = (int) $user_id;
    $plugin->store_session($session_id, $record);
    update_user_meta($user_id, '_didit_pending_session_id', $session_id);
    $plugin->apply_confirmed_status($session_id, $record, 'Approved', (int) ($record['event_timestamp'] ?? 0), $record['report'] ?? null);
  }

  /* ── Age-restricted products and categories ── */

  public static function sanitize_categories($value): array
  {
    return array_values(array_filter(array_map('absint', is_array($value) ? $value : [])));
  }

  public static function field_gate_categories()
  {
    if (!function_exists('get_terms') || !taxonomy_exists('product_cat')) {
      echo '<p class="description">' . esc_html__('WooCommerce is not active.', 'didit-verify') . '</p>';
      return;
    }
    $selected = self::sanitize_categories(get_option('didit_gate_categories', []));
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    echo '<select name="didit_gate_categories[]" multiple="multiple" size="6" style="min-width:20em">';
    foreach (is_array($terms) ? $terms : [] as $term) {
      printf('<option value="%d" %s>%s</option>', absint($term->term_id), selected(in_array((int) $term->term_id, $selected, true), true, false), esc_html($term->name));
    }
    echo '</select>';
    printf(
      '<p class="description">%s</p>',
      esc_html__('Products in these categories, and products marked "Age-restricted" in Product data → Advanced, can only be added to the cart after an approval from the age-restricted workflow.', 'didit-verify')
    );
  }

  public static function product_field()
  {
    echo '<div class="options_group">';
    woocommerce_wp_checkbox([
      'id' => '_didit_age_gate',
      'label' => __('Age-restricted', 'didit-verify'),
      'description' => __('Ask for an approval from the age-restricted workflow before this product can be bought.', 'didit-verify'),
    ]);
    echo '</div>';
  }

  public static function product_save($product)
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-edit nonce before firing this hook.
    $product->update_meta_data('_didit_age_gate', isset($_POST['_didit_age_gate']) ? 'yes' : 'no');
  }

  public static function product_is_gated(int $product_id): bool
  {
    if (!$product_id || !Didit_Verify_Workflows::is_enabled('product_gate')) {
      return false;
    }
    $parent = (int) wp_get_post_parent_id($product_id);
    $id = $parent ?: $product_id;
    if ('yes' === get_post_meta($id, '_didit_age_gate', true)) {
      return true;
    }
    $categories = self::sanitize_categories(get_option('didit_gate_categories', []));
    return $categories && has_term($categories, 'product_cat', $id);
  }

  public static function visitor_is_approved(): bool
  {
    $workflow_id = Didit_Verify_Workflows::workflow_for('product_gate');
    $user_id = get_current_user_id();
    return ($user_id && self::$plugin->user_has_workflow_approval($user_id, $workflow_id))
      || self::$plugin->visitor_has_workflow_approval($workflow_id);
  }

  private static function gate_message(): string
  {
    return __('This product is age-restricted. Please verify your age to buy it.', 'didit-verify');
  }

  public static function add_to_cart_validation($passed, $product_id, $quantity = 1, $variation_id = 0)
  {
    if ($passed && self::product_is_gated(absint($variation_id ?: $product_id)) && !self::visitor_is_approved()) {
      wc_add_notice(self::gate_message(), 'error');
      return false;
    }
    return $passed;
  }

  public static function store_api_add_to_cart($product)
  {
    if (is_object($product) && self::product_is_gated((int) $product->get_id()) && !self::visitor_is_approved()) {
      throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('didit_verification_required', esc_html(self::gate_message()), 403);
    }
  }

  public static function check_cart_items()
  {
    static $added = false;
    if ($added || !function_exists('WC') || !WC()->cart || !Didit_Verify_Workflows::is_enabled('product_gate')) {
      return;
    }
    foreach (WC()->cart->get_cart() as $item) {
      $id = absint(($item['variation_id'] ?? 0) ?: ($item['product_id'] ?? 0));
      if (self::product_is_gated($id)) {
        if (!self::visitor_is_approved()) {
          $added = true;
          wc_add_notice(__('Your cart contains age-restricted products. Please verify your age before checking out.', 'didit-verify'), 'error');
        }
        return;
      }
    }
  }

  public static function product_box()
  {
    global $product;
    if (!is_object($product) || !self::product_is_gated((int) $product->get_id()) || self::visitor_is_approved()) {
      return;
    }
    printf(
      '<div class="didit-product-gate"><p>%s</p>%s</div>',
      esc_html(self::gate_message()),
      self::$plugin->render_shortcode(['placement' => 'product_gate', 'text' => __('Verify my age', 'didit-verify')]) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
    );
  }

  /* ── My Account ── */

  private static function account_tab_enabled(): bool
  {
    return Didit_Verify_Workflows::is_enabled('my_account') || Didit_Verify_Workflows::is_enabled('business');
  }

  public static function flush_endpoints()
  {
    // WordPress rebuilds the rules on the next request.
    delete_option('rewrite_rules');
  }

  public static function query_vars($vars)
  {
    if (self::account_tab_enabled()) {
      $vars[self::ENDPOINT] = self::ENDPOINT;
    }
    return $vars;
  }

  public static function account_menu($items)
  {
    if (!self::account_tab_enabled()) {
      return $items;
    }
    $logout = isset($items['customer-logout']) ? ['customer-logout' => $items['customer-logout']] : [];
    unset($items['customer-logout']);
    $items[self::ENDPOINT] = __('Verification', 'didit-verify');
    return $items + $logout;
  }

  public static function customer_status_text(string $status): string
  {
    switch ($status) {
      case 'Approved':
        return __('Verified', 'didit-verify');
      case 'Declined':
        return __('Declined', 'didit-verify');
      case 'In Review':
        return __('Being reviewed', 'didit-verify');
      case 'Awaiting User':
      case 'Resubmitted':
        return __('Your action is needed', 'didit-verify');
      case 'Expired':
      case 'KYC Expired':
      case 'Abandoned':
        return __('Expired', 'didit-verify');
      case 'Not Started':
      case 'In Progress':
        return __('Not finished', 'didit-verify');
      default:
        return __('Not verified', 'didit-verify');
    }
  }

  /**
   * The button label for a status. Awaiting User and Resubmitted need the customer to
   * redo requested steps: the button reopens the same session, which the server hands
   * back because it is still open for this placement.
   */
  private static function action_text(string $status, string $start): string
  {
    if (in_array($status, ['Awaiting User', 'Resubmitted', 'Not Started', 'In Progress'], true)) {
      return __('Continue verification', 'didit-verify');
    }
    return 'Approved' === $status ? __('Verify again', 'didit-verify') : $start;
  }

  public static function account_tab()
  {
    $user_id = get_current_user_id();
    if (!$user_id) {
      return;
    }
    $plugin = self::$plugin;
    echo '<div class="didit-account">';
    if (Didit_Verify_Workflows::is_enabled('my_account')) {
      // The status of the workflow this tab runs, not of an age or checkout check.
      $status = $plugin->user_workflow_status($user_id, Didit_Verify_Workflows::workflow_for('my_account'));
      printf('<h3>%s</h3><p>%s <strong>%s</strong></p>', esc_html__('Identity', 'didit-verify'), esc_html__('Status:', 'didit-verify'), esc_html(self::customer_status_text($status)));
      if ('In Review' !== $status) {
        echo $plugin->render_shortcode(['placement' => 'my_account', 'text' => self::action_text($status, __('Verify your Identity', 'didit-verify'))]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
      }
    }
    if (Didit_Verify_Workflows::is_enabled('business')) {
      $status = (string) get_user_meta($user_id, '_didit_kyb_status', true);
      printf(
        '<h3>%s</h3><p>%s</p><p>%s <strong>%s</strong></p>',
        esc_html__('Business account', 'didit-verify'),
        esc_html__('Verify your company to buy as a business customer.', 'didit-verify'),
        esc_html__('Status:', 'didit-verify'),
        esc_html(self::customer_status_text($status))
      );
      if (!in_array($status, ['Approved', 'In Review'], true)) {
        echo $plugin->render_shortcode(['placement' => 'business', 'text' => self::action_text($status, __('Verify your business', 'didit-verify'))]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
      }
    }
    echo '</div>';
  }
}
