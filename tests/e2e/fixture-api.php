<?php
/**
 * Local test site only: deterministic upstream answers, never loaded by the plugin.
 *
 * Covers sessions and decisions, the workflow list, AML screening, phone and email
 * codes. Options: didit_test_decision (status, or "Offline"), didit_test_aml_status.
 * All data is synthetic.
 */
if (!defined('ABSPATH') || 'local' !== wp_get_environment_type()) {
  return;
}
add_filter('pre_http_request', function ($response, $args, $url) {
  if (0 !== strpos($url, 'https://verification.didit.me/v3/')) {
    return $response;
  }
  $json = function ($code, $body) {
    return ['response' => ['code' => $code], 'body' => wp_json_encode($body), 'headers' => []];
  };
  $method = $args['method'] ?? 'GET';
  $path = (string) wp_parse_url($url, PHP_URL_PATH);

  if ('/v3/workflows/' === $path) {
    return $json(200, ['count' => 4, 'next' => null, 'previous' => null, 'results' => [
      ['workflow_id' => '6f0c1e2a-0000-4000-8000-000000000001', 'workflow_label' => 'Identity check', 'workflow_type' => 'kyc', 'features' => 'OCR + LIVENESS + FACE_MATCH + AML', 'status' => 'published', 'is_default' => true, 'is_archived' => false],
      ['workflow_id' => '6f0c1e2a-0000-4000-8000-000000000002', 'workflow_label' => 'Age check 18+', 'workflow_type' => 'kyc', 'features' => 'AGE_ESTIMATION + LIVENESS', 'status' => 'published', 'is_default' => false, 'is_archived' => false],
      ['workflow_id' => '6f0c1e2a-0000-4000-8000-000000000003', 'workflow_label' => 'High-value orders', 'workflow_type' => 'kyc', 'features' => 'OCR + LIVENESS + FACE_MATCH + AML + PHONE + EMAIL + POA', 'status' => 'published', 'is_default' => false, 'is_archived' => false],
      ['workflow_id' => '6f0c1e2a-0000-4000-8000-000000000004', 'workflow_label' => 'Trade accounts', 'workflow_type' => 'kyb', 'features' => 'KYB_REGISTRY + KYB_KEY_PEOPLE + AML', 'status' => 'published', 'is_default' => false, 'is_archived' => false],
    ]]);
  }
  if ('/v3/aml/' === $path) {
    $status = get_option('didit_test_aml_status', 'In Review');
    return $json(200, ['request_id' => wp_generate_uuid4(), 'aml' => [
      'status' => $status, 'total_hits' => 'Approved' === $status ? 0 : 1, 'score' => 'Approved' === $status ? 0 : 74,
      'hits' => 'Approved' === $status ? [] : [['caption' => 'Alex Sample', 'match_score' => 86, 'risk_score' => 60, 'review_status' => 'Unreviewed', 'pep_matches' => [['level' => 1]]]],
    ]]);
  }
  if (preg_match('#^/v3/(phone|email)/send/$#', $path)) {
    return $json(200, ['request_id' => wp_generate_uuid4(), 'status' => 'Success']);
  }
  if (preg_match('#^/v3/(phone|email)/check/$#', $path)) {
    $body = json_decode((string) ($args['body'] ?? ''), true);
    return $json(200, ['status' => '123456' === ($body['code'] ?? '') ? 'Approved' : 'Declined']);
  }
  if (0 !== strpos($path, '/v3/session/')) {
    return $json(404, ['detail' => 'Not found']);
  }
  if ('POST' === $method) {
    $id = wp_generate_uuid4();
    update_option('didit_test_created_session', $id);
    return $json(201, ['session_id' => $id, 'status' => 'Not Started', 'url' => 'https://verify.didit.me/session/synthetic-test-only']);
  }
  preg_match('#/session/([^/]+)/decision/#', $url, $matches);
  $status = get_option('didit_test_decision', 'In Review');
  if ('Offline' === $status) {
    return new WP_Error('offline', 'Synthetic upstream outage');
  }
  return $json(200, [
    'session_id' => $matches[1] ?? '',
    'status' => $status,
    'session_kind' => 'user',
    'id_verifications' => [['status' => 'Approved', 'document_type' => 'Identity Card', 'document_subtype' => 'National ID', 'issuing_state' => 'ESP', 'age' => 34]],
    'liveness_checks' => [['status' => 'Approved', 'score' => 96.4, 'age_estimation' => 33]],
    'face_matches' => [['status' => 'Approved', 'score' => 91.2]],
    'aml_screenings' => [['status' => 'In Review', 'total_hits' => 1, 'score' => 74, 'is_ongoing_monitoring_enabled' => true, 'last_ongoing_screening_at' => '2026-11-24 09:00',
      'hits' => [['caption' => 'Alex Sample', 'match_score' => 86, 'risk_score' => 60, 'review_status' => 'Unreviewed', 'pep_matches' => [['level' => 1]]]]]],
    'phone_verifications' => [['status' => 'Approved', 'full_number' => '+34600000000']],
    'email_verifications' => [['status' => 'Approved', 'email' => 'alex.sample@example.com']],
    'poa_verifications' => [['status' => 'Approved', 'document_type' => 'Utility bill', 'issuing_state' => 'ESP']],
  ]);
}, 10, 3);
