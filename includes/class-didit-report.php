<?php
/**
 * A short, storable summary of every check in a Didit decision, and its admin view.
 *
 * Only what an admin needs to act on is kept: each check's status and score, AML hit
 * names and monitoring state, contact results, the age and the document described by
 * type, subtype and country. Images, raw payloads, data source names and registry
 * document names are never stored.
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Didit_Verify_Report
{
  const META = '_didit_report';
  const MAX_ITEMS = 3;
  const MAX_HITS = 5;

  /**
   * @param array $decision Decoded GET /v3/session/{id}/decision/ body.
   */
  public static function summarize(array $decision): array
  {
    $summary = [
      'session_id' => self::text($decision['session_id'] ?? ''),
      'kind' => 'business' === ($decision['session_kind'] ?? '') ? 'business' : 'user',
      'status' => self::text($decision['status'] ?? ''),
      'workflow_id' => self::text($decision['workflow_id'] ?? ''),
      'updated_at' => time(),
      'checks' => [],
    ];

    foreach (self::items($decision, 'id_verifications') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'id',
        'status' => self::text($item['status'] ?? ''),
        'document' => self::document($item),
        'age' => isset($item['age']) && is_numeric($item['age']) ? (int) $item['age'] : null,
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'nfc_verifications') as $item) {
      $summary['checks'][] = ['type' => 'nfc', 'status' => self::text($item['status'] ?? '')];
    }
    foreach (self::items($decision, 'liveness_checks') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'liveness',
        'status' => self::text($item['status'] ?? ''),
        'score' => self::number($item['score'] ?? null),
        'age' => isset($item['age_estimation']) && is_numeric($item['age_estimation']) ? (int) round($item['age_estimation']) : null,
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'face_matches') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'face_match',
        'status' => self::text($item['status'] ?? ''),
        'score' => self::number($item['score'] ?? null),
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'aml_screenings') as $item) {
      $summary['checks'][] = self::aml($item);
    }
    foreach (self::items($decision, 'phone_verifications') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'phone',
        'status' => self::text($item['status'] ?? ''),
        'value' => self::text($item['full_number'] ?? ''),
        'flags' => array_values(array_filter([
          !empty($item['is_disposable']) ? 'disposable' : '',
          !empty($item['is_virtual']) ? 'virtual' : '',
        ])),
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'email_verifications') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'email',
        'status' => self::text($item['status'] ?? ''),
        'value' => sanitize_email((string) ($item['email'] ?? '')),
        'flags' => array_values(array_filter([
          !empty($item['is_breached']) ? 'breached' : '',
          !empty($item['is_disposable']) ? 'disposable' : '',
          !empty($item['is_undeliverable']) ? 'undeliverable' : '',
        ])),
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'poa_verifications') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'poa',
        'status' => self::text($item['status'] ?? ''),
        'document' => self::document($item),
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'database_validations') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'database',
        'status' => self::text($item['status'] ?? ''),
        'match' => self::text($item['match_type'] ?? ''),
        'country' => self::text($item['issuing_state'] ?? ''),
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'registry_checks') as $item) {
      $company = is_array($item['company'] ?? null) ? $item['company'] : [];
      $summary['checks'][] = array_filter([
        'type' => 'registry',
        'status' => self::text($item['status'] ?? ($company['status'] ?? '')),
        'company' => self::text($company['company_name'] ?? ''),
        'number' => self::text($company['registration_number'] ?? ''),
        'country' => self::text($company['country_code'] ?? ''),
        'risk' => self::text($company['risk_level'] ?? ''),
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'key_people_checks') as $item) {
      $ubo = is_array($item['ubo_kyc_summary'] ?? null) ? $item['ubo_kyc_summary'] : [];
      $summary['checks'][] = array_filter([
        'type' => 'key_people',
        'status' => self::text($item['status'] ?? ''),
        'flags' => $ubo ? [sprintf(
          /* translators: 1: approved people, 2: total people. */
          __('%1$d of %2$d people approved', 'didit-verify'),
          (int) ($ubo['approved'] ?? 0),
          (int) ($ubo['total'] ?? 0)
        )] : [],
      ], [__CLASS__, 'keep']);
    }
    foreach (self::items($decision, 'bank_verifications') as $item) {
      $summary['checks'][] = ['type' => 'bank', 'status' => self::text($item['status'] ?? '')];
    }
    foreach (self::items($decision, 'ip_analyses') as $item) {
      $summary['checks'][] = array_filter([
        'type' => 'ip',
        'status' => self::text($item['status'] ?? ''),
        'country' => self::text($item['ip_country_code'] ?? ($item['ip_country'] ?? '')),
        'flags' => array_values(array_filter([
          !empty($item['is_vpn_or_tor']) ? 'VPN or Tor' : '',
          !empty($item['is_data_center']) ? 'data center' : '',
        ])),
      ], [__CLASS__, 'keep']);
    }

    return $summary;
  }

  /** Summary of one AML screening, from a session or from POST /v3/aml/. */
  public static function aml(array $item): array
  {
    $hits = [];
    foreach (array_slice(is_array($item['hits'] ?? null) ? $item['hits'] : [], 0, self::MAX_HITS) as $hit) {
      if (!is_array($hit)) {
        continue;
      }
      $hits[] = array_filter([
        'name' => self::text($hit['caption'] ?? ''),
        'match' => self::number($hit['match_score'] ?? ($hit['score'] ?? null)),
        'risk' => self::number($hit['risk_score'] ?? null),
        'review' => self::text($hit['review_status'] ?? ''),
        'pep' => !empty($hit['pep_matches']),
        'sanction' => !empty($hit['sanction_matches']),
        'adverse_media' => !empty($hit['adverse_media_matches']),
      ], [__CLASS__, 'keep']);
    }
    return array_filter([
      'type' => 'aml',
      'status' => self::text($item['status'] ?? ''),
      'hits_total' => (int) ($item['total_hits'] ?? count($hits)),
      'score' => self::number($item['score'] ?? null),
      'hits' => $hits,
      'monitoring' => !empty($item['is_ongoing_monitoring_enabled']),
      'last_screened' => self::text($item['last_ongoing_screening_at'] ?? ''),
    ], [__CLASS__, 'keep']);
  }

  private static function items(array $decision, string $key): array
  {
    $items = $decision[$key] ?? [];
    if (!is_array($items)) {
      return [];
    }
    return array_slice(array_values(array_filter($items, 'is_array')), 0, self::MAX_ITEMS);
  }

  /** Document type, subtype and country. Never a registry document name. */
  private static function document(array $item): string
  {
    return implode(' · ', array_filter([
      self::text($item['document_type'] ?? ''),
      self::text($item['document_subtype'] ?? ''),
      self::text($item['issuing_state'] ?? ''),
    ]));
  }

  private static function text($value): string
  {
    return is_scalar($value) ? substr(sanitize_text_field((string) $value), 0, 120) : '';
  }

  private static function number($value)
  {
    return is_numeric($value) ? round((float) $value, 2) : null;
  }

  private static function keep($value): bool
  {
    return null !== $value && '' !== $value && [] !== $value && false !== $value;
  }

  /* ── Admin view ── */

  public static function check_label(string $type): string
  {
    $labels = [
      'id' => __('ID document', 'didit-verify'),
      'nfc' => __('NFC chip', 'didit-verify'),
      'liveness' => __('Liveness', 'didit-verify'),
      'face_match' => __('Face match', 'didit-verify'),
      'aml' => __('AML screening', 'didit-verify'),
      'phone' => __('Phone', 'didit-verify'),
      'email' => __('Email', 'didit-verify'),
      'poa' => __('Proof of address', 'didit-verify'),
      'database' => __('Database validation', 'didit-verify'),
      'registry' => __('Company registry', 'didit-verify'),
      'key_people' => __('Key people', 'didit-verify'),
      'bank' => __('Bank verification', 'didit-verify'),
      'ip' => __('IP analysis', 'didit-verify'),
    ];
    return $labels[$type] ?? $type;
  }

  /**
   * Console address of a session. The admin pastes any console URL once; without it the
   * link opens the console home, where the session ID can be searched.
   */
  public static function console_url(string $session_id): string
  {
    $base = (string) get_option('didit_console_url', '');
    if (preg_match('#^https://business\.didit\.me/console/([A-Za-z0-9-]+)/([A-Za-z0-9-]+)#', $base, $m)) {
      return 'https://business.didit.me/console/' . $m[1] . '/' . $m[2] . '/verifications?session-id=' . rawurlencode($session_id);
    }
    return 'https://business.didit.me';
  }

  public static function details(array $check): string
  {
    $parts = [];
    if (!empty($check['document'])) {
      $parts[] = $check['document'];
    }
    if (isset($check['age'])) {
      /* translators: %d: age in years. */
      $parts[] = sprintf(__('age %d', 'didit-verify'), $check['age']);
    }
    if (isset($check['score'])) {
      /* translators: %s: score. */
      $parts[] = sprintf(__('score %s', 'didit-verify'), $check['score']);
    }
    if (!empty($check['value'])) {
      $parts[] = $check['value'];
    }
    if (!empty($check['flags'])) {
      $parts[] = implode(', ', $check['flags']);
    }
    if (!empty($check['match'])) {
      $parts[] = str_replace('_', ' ', $check['match']);
    }
    if (!empty($check['company'])) {
      $parts[] = trim($check['company'] . ' ' . ($check['number'] ?? ''));
    }
    if (!empty($check['country']) && empty($check['document'])) {
      $parts[] = $check['country'];
    }
    if (!empty($check['risk'])) {
      /* translators: %s: risk level. */
      $parts[] = sprintf(__('risk %s', 'didit-verify'), strtolower($check['risk']));
    }
    if ('aml' === ($check['type'] ?? '')) {
      /* translators: %d: number of AML hits. */
      $parts[] = sprintf(_n('%d hit', '%d hits', (int) ($check['hits_total'] ?? 0), 'didit-verify'), (int) ($check['hits_total'] ?? 0));
      $parts[] = !empty($check['monitoring'])
        ? (!empty($check['last_screened'])
          /* translators: %s: date and time of the last screening. */
          ? sprintf(__('ongoing monitoring on, last screened %s', 'didit-verify'), $check['last_screened'])
          : __('ongoing monitoring on', 'didit-verify'))
        : __('ongoing monitoring off', 'didit-verify');
    }
    return implode(' · ', $parts);
  }

  /**
   * HTML table of a summary. Every value is escaped here.
   */
  public static function render($summary, string $session_id = ''): string
  {
    $summary = is_array($summary) ? $summary : [];
    $session_id = $session_id ?: (string) ($summary['session_id'] ?? '');
    $rows = '';
    foreach ((array) ($summary['checks'] ?? []) as $check) {
      if (!is_array($check) || empty($check['type'])) {
        continue;
      }
      $details = self::details($check);
      $rows .= sprintf(
        '<tr><th scope="row">%s</th><td>%s%s</td></tr>',
        esc_html(self::check_label($check['type'])),
        self::badge((string) ($check['status'] ?? '')),
        '' !== $details ? '<div class="didit-report-details">' . esc_html($details) . '</div>' : ''
      );
      foreach ((array) ($check['hits'] ?? []) as $hit) {
        $tags = array_filter([
          !empty($hit['pep']) ? 'PEP' : '',
          !empty($hit['sanction']) ? __('sanctions', 'didit-verify') : '',
          !empty($hit['adverse_media']) ? __('adverse media', 'didit-verify') : '',
          isset($hit['match']) ? sprintf(/* translators: %s: match score. */ __('match %s', 'didit-verify'), $hit['match']) : '',
          isset($hit['risk']) ? sprintf(/* translators: %s: risk score. */ __('risk score %s', 'didit-verify'), $hit['risk']) : '',
          $hit['review'] ?? '',
        ]);
        $rows .= sprintf(
          '<tr class="didit-report-hit"><td></td><td>%s%s</td></tr>',
          esc_html($hit['name'] ?? ''),
          $tags ? ' <span class="description">(' . esc_html(implode(', ', $tags)) . ')</span>' : ''
        );
      }
    }
    $html = '<div class="didit-report">';
    if ($rows) {
      $html .= '<table class="widefat striped didit-report-table"><tbody>' . $rows . '</tbody></table>';
    } elseif ($session_id) {
      $html .= '<p class="description">' . esc_html__('Check results appear here once Didit has a decision.', 'didit-verify') . '</p>';
    }
    if ($session_id) {
      $html .= sprintf(
        '<p class="didit-report-links"><code>%s</code> <a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></p>',
        esc_html($session_id),
        esc_url(self::console_url($session_id)),
        esc_html__('Open in Didit Console', 'didit-verify'),
        esc_html__('(opens in a new tab)', 'didit-verify')
      );
    }
    return $html . '</div>';
  }

  public static function badge(string $status): string
  {
    $styles = ['Approved' => 'approved', 'Declined' => 'declined', 'In Review' => 'review', 'Resub Requested' => 'review', 'Resubmitted' => 'review'];
    $label = $status ?: __('Not started', 'didit-verify');
    return '<span class="didit-admin-status didit-admin-status-' . esc_attr($styles[$status] ?? 'pending') . '">' . esc_html($label) . '</span>';
  }
}
