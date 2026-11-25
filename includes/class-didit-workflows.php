<?php
/**
 * The site's Didit workflows and which one each placement runs.
 *
 * The browser only ever names a placement. The server maps it to a workflow, so a
 * visitor cannot pick a cheaper or weaker workflow than the one the admin chose.
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Didit_Verify_Workflows
{
  const CACHE_KEY = 'didit_workflows_cache';
  const OPTION = 'didit_placements';

  /** Placement keys and the option that switches each one on ('' = always available). */
  const PLACEMENTS = [
    'default' => '',
    'checkout' => '',
    'registration' => 'didit_registration_enabled',
    'product_gate' => 'didit_gate_enabled',
    'my_account' => 'didit_my_account_enabled',
    'business' => 'didit_business_enabled',
  ];

  public static function hooks()
  {
    add_action('admin_post_didit_refresh_workflows', [__CLASS__, 'handle_refresh']);
  }

  public static function placement_labels(): array
  {
    return [
      'default' => __('Shortcodes and content gate', 'didit-verify'),
      'checkout' => __('WooCommerce checkout and after purchase', 'didit-verify'),
      'registration' => __('Registration', 'didit-verify'),
      'product_gate' => __('Age-restricted products and categories', 'didit-verify'),
      'my_account' => __('My Account re-verification', 'didit-verify'),
      'business' => __('Business customers (KYB)', 'didit-verify'),
    ];
  }

  public static function is_placement(string $placement): bool
  {
    return array_key_exists($placement, self::PLACEMENTS);
  }

  public static function is_enabled(string $placement): bool
  {
    if (!self::is_placement($placement)) {
      return false;
    }
    $option = self::PLACEMENTS[$placement];
    return '' === $option || (bool) get_option($option, false);
  }

  /**
   * The workflow a placement runs: its own choice, else the default Workflow ID.
   */
  public static function workflow_for(string $placement): string
  {
    $map = get_option(self::OPTION, []);
    $chosen = is_array($map) && isset($map[$placement]) ? (string) $map[$placement] : '';
    return '' !== $chosen ? $chosen : (string) get_option('didit_workflow_id', '');
  }

  public static function sanitize_map($value): array
  {
    $clean = [];
    if (!is_array($value)) {
      return $clean;
    }
    foreach (array_keys(self::PLACEMENTS) as $placement) {
      $id = isset($value[$placement]) ? trim((string) $value[$placement]) : '';
      if ('' !== $id && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
        $clean[$placement] = $id;
      }
    }
    return $clean;
  }

  /**
   * The application's workflows, cached for ten minutes.
   *
   * @return array|WP_Error List of normalized workflows.
   */
  public static function all(bool $refresh = false)
  {
    if (!$refresh) {
      $cached = get_transient(self::CACHE_KEY);
      if (is_array($cached)) {
        return $cached;
      }
    }
    $workflows = [];
    $path = '/v3/workflows/';
    for ($page = 0; $page < 5 && $path; $page++) {
      $data = Didit_Verify_Api::request('GET', $path);
      if (is_wp_error($data)) {
        return $data;
      }
      foreach ((array) ($data['results'] ?? []) as $row) {
        $workflow = self::normalize(is_array($row) ? $row : []);
        if ($workflow) {
          $workflows[] = $workflow;
        }
      }
      $path = self::next_path($data['next'] ?? '');
    }
    set_transient(self::CACHE_KEY, $workflows, 10 * MINUTE_IN_SECONDS);
    return $workflows;
  }

  /** Follow pagination only on the API host. */
  private static function next_path($next): string
  {
    if (!is_string($next) || '' === $next) {
      return '';
    }
    $host = wp_parse_url($next, PHP_URL_HOST);
    if ($host && wp_parse_url(Didit_Verify_Api::BASE_URL, PHP_URL_HOST) !== $host) {
      return '';
    }
    $path = (string) wp_parse_url($next, PHP_URL_PATH);
    $query = (string) wp_parse_url($next, PHP_URL_QUERY);
    return 0 === strpos($path, '/v3/workflows/') ? $path . ($query ? '?' . $query : '') : '';
  }

  public static function normalize(array $row)
  {
    $id = (string) ($row['workflow_id'] ?? $row['uuid'] ?? '');
    if ('' === $id || !empty($row['is_archived'])) {
      return null;
    }
    $features = array_values(array_filter(array_map('trim', preg_split('/[+,]/', (string) ($row['features'] ?? '')))));
    return [
      'id' => sanitize_text_field($id),
      'label' => sanitize_text_field((string) ($row['workflow_label'] ?? '')),
      'type' => 'kyb' === strtolower((string) ($row['workflow_type'] ?? '')) ? 'kyb' : 'kyc',
      'features' => array_map('sanitize_text_field', $features),
      'status' => sanitize_text_field((string) ($row['status'] ?? '')),
      'is_default' => !empty($row['is_default']),
    ];
  }

  /** Readable name of a workflow feature code. */
  public static function feature_label(string $code): string
  {
    $labels = [
      'OCR' => __('ID document', 'didit-verify'),
      'ID_VERIFICATION' => __('ID document', 'didit-verify'),
      'NFC' => __('NFC chip', 'didit-verify'),
      'LIVENESS' => __('Liveness', 'didit-verify'),
      'FACE_MATCH' => __('Face match', 'didit-verify'),
      'AML' => __('AML screening', 'didit-verify'),
      'PHONE' => __('Phone', 'didit-verify'),
      'PHONE_VERIFICATION' => __('Phone', 'didit-verify'),
      'EMAIL' => __('Email', 'didit-verify'),
      'EMAIL_VERIFICATION' => __('Email', 'didit-verify'),
      'POA' => __('Proof of address', 'didit-verify'),
      'PROOF_OF_ADDRESS' => __('Proof of address', 'didit-verify'),
      'DATABASE_VALIDATION' => __('Database validation', 'didit-verify'),
      'IP_ANALYSIS' => __('IP analysis', 'didit-verify'),
      'AGE_ESTIMATION' => __('Age estimation', 'didit-verify'),
      'QUESTIONNAIRE' => __('Questionnaire', 'didit-verify'),
      'BANK' => __('Bank verification', 'didit-verify'),
      'KYB_REGISTRY' => __('Company registry', 'didit-verify'),
      'KYB_KEY_PEOPLE' => __('Key people', 'didit-verify'),
      'KYB_DOCUMENTS' => __('Company documents', 'didit-verify'),
    ];
    $key = strtoupper(str_replace([' ', '-'], '_', $code));
    return $labels[$key] ?? ucwords(strtolower(str_replace('_', ' ', $code)));
  }

  public static function handle_refresh()
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You are not allowed to do this.', 'didit-verify'), '', ['response' => 403]);
    }
    check_admin_referer('didit_refresh_workflows');
    delete_transient(self::CACHE_KEY);
    wp_safe_redirect(admin_url('options-general.php?page=didit-verify#didit_placements_section'));
    exit;
  }

  /** Settings field: the workflow list with the checks each one runs. */
  public static function render_catalogue()
  {
    $workflows = get_option('didit_api_key') ? self::all() : new WP_Error('didit_not_configured', __('Add your Didit API key first.', 'didit-verify'));
    $refresh = wp_nonce_url(admin_url('admin-post.php?action=didit_refresh_workflows'), 'didit_refresh_workflows');
    if (is_wp_error($workflows)) {
      printf('<p class="description">%s</p>', esc_html($workflows->get_error_message()));
    } elseif (!$workflows) {
      printf('<p class="description">%s</p>', esc_html__('This application has no workflows yet. Create one in the Didit Console.', 'didit-verify'));
    } else {
      echo '<table class="widefat striped didit-workflows"><thead><tr>';
      printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th>', esc_html__('Workflow', 'didit-verify'), esc_html__('Type', 'didit-verify'), esc_html__('Checks', 'didit-verify'), esc_html__('Workflow ID', 'didit-verify'));
      echo '</tr></thead><tbody>';
      foreach ($workflows as $workflow) {
        printf(
          '<tr><td>%s%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
          esc_html($workflow['label'] ?: $workflow['id']),
          $workflow['is_default'] ? ' <span class="didit-admin-status didit-admin-status-approved">' . esc_html__('Default', 'didit-verify') . '</span>' : '',
          esc_html('kyb' === $workflow['type'] ? __('Business (KYB)', 'didit-verify') : __('Person (KYC)', 'didit-verify')),
          esc_html(implode(', ', array_unique(array_map([__CLASS__, 'feature_label'], $workflow['features'])))),
          esc_html($workflow['id'])
        );
      }
      echo '</tbody></table>';
    }
    printf('<p><a class="button" href="%s">%s</a></p>', esc_url($refresh), esc_html__('Refresh list', 'didit-verify'));
  }

  /** Settings field: one workflow per placement, plus the switch of optional placements. */
  public static function render_placements()
  {
    $workflows = get_option('didit_api_key') ? self::all() : [];
    $workflows = is_wp_error($workflows) ? [] : $workflows;
    $map = get_option(self::OPTION, []);
    $map = is_array($map) ? $map : [];
    echo '<table class="widefat striped didit-placements"><tbody>';
    foreach (self::placement_labels() as $placement => $label) {
      $toggle = self::PLACEMENTS[$placement];
      $current = (string) ($map[$placement] ?? '');
      echo '<tr><th scope="row">';
      if ($toggle) {
        printf(
          '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
          esc_attr($toggle),
          checked((bool) get_option($toggle, false), true, false),
          esc_html($label)
        );
      } else {
        echo esc_html($label);
      }
      echo '</th><td>';
      $name = self::OPTION . '[' . $placement . ']';
      if ($workflows) {
        printf('<select name="%s">', esc_attr($name));
        printf('<option value="">%s</option>', esc_html__('Default Workflow ID', 'didit-verify'));
        $known = false;
        foreach ($workflows as $workflow) {
          $known = $known || $workflow['id'] === $current;
          printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($workflow['id']),
            selected($current, $workflow['id'], false),
            esc_html(($workflow['label'] ?: $workflow['id']) . ' (' . strtoupper($workflow['type']) . ')')
          );
        }
        if ($current && !$known) {
          printf('<option value="%1$s" selected="selected">%1$s</option>', esc_attr($current));
        }
        echo '</select>';
      } else {
        printf('<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s" />', esc_attr($name), esc_attr($current), esc_attr__('Default Workflow ID', 'didit-verify'));
      }
      echo '</td></tr>';
    }
    echo '</tbody></table>';
    printf(
      '<p class="description">%s</p>',
      esc_html__('Each placement starts sessions with its own workflow, so one site can ask for an ID check at registration, an age check on restricted products and a business check for trade accounts. An approval only counts for the workflow that produced it. Choose a KYB workflow for business customers.', 'didit-verify')
    );
  }
}
