<?php
/** Local test site only: deterministic upstream decisions, never loaded by the plugin. */
if (!defined('ABSPATH') || 'local' !== wp_get_environment_type()) {
  return;
}
add_filter('pre_http_request', function ($response, $args, $url) {
  if (0 !== strpos($url, 'https://verification.didit.me/v3/session/')) {
    return $response;
  }
  if ('POST' === ($args['method'] ?? 'GET')) {
    $id = wp_generate_uuid4();
    update_option('didit_test_created_session', $id);
    return ['response' => ['code' => 201], 'body' => wp_json_encode([
      'session_id' => $id, 'status' => 'Not Started',
      'url' => 'https://verify.didit.me/session/synthetic-test-only',
    ])];
  }
  preg_match('#/session/([^/]+)/decision/#', $url, $matches);
  $status = get_option('didit_test_decision', 'In Review');
  if ('Offline' === $status) {
    return new WP_Error('offline', 'Synthetic upstream outage');
  }
  return ['response' => ['code' => 200], 'body' => wp_json_encode([
    'session_id' => $matches[1] ?? '', 'status' => $status,
  ])];
}, 10, 3);
