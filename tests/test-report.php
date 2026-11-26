<?php
/**
 * Every check's result is summarized from the decision, refreshed by later webhooks,
 * and never keeps images, data source names or registry document names.
 *
 *   php tests/test-report.php
 */

require __DIR__ . '/wp-stubs-features.php';
require __DIR__ . '/../didit-verify.php';

$plugin = Didit_Verify::init();
$id = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
$decision = [
  'session_id' => $id,
  'session_kind' => 'user',
  'status' => 'Approved',
  'workflow_id' => 'wf-default',
  'id_verifications' => [[
    'status' => 'Approved', 'document_type' => 'Identity Card', 'document_subtype' => 'National ID',
    'issuing_state' => 'ESP', 'age' => 34, 'portrait_image' => 'https://images.example/portrait.jpg',
    'document_name' => 'Spain - Id Card (2015)', 'full_name' => 'Juana Real', 'document_number' => 'X1234567',
  ]],
  'liveness_checks' => [['status' => 'Approved', 'score' => 97.456, 'video_url' => 'https://images.example/v.mp4', 'age_estimation' => 33.6]],
  'face_matches' => [['status' => 'Approved', 'score' => 88.1, 'source_image' => 'https://images.example/s.jpg']],
  'aml_screenings' => [[
    'status' => 'In Review', 'total_hits' => 2, 'score' => 71, 'is_ongoing_monitoring_enabled' => true,
    'last_ongoing_screening_at' => '2026-11-20T10:00:00Z',
    'hits' => [
      ['caption' => 'Jane <b>Doe</b>', 'match_score' => 91, 'risk_score' => 60, 'review_status' => 'Unreviewed',
        'pep_matches' => [['x' => 1]], 'sanction_matches' => [], 'datasets' => ['Vendor list'], 'api_service' => 'supplier-x'],
      ['caption' => 'J. Doe', 'match_score' => 55],
    ],
  ]],
  'phone_verifications' => [['status' => 'Approved', 'full_number' => '+34600000000', 'is_virtual' => true, 'carrier' => ['name' => 'Carrier']]],
  'email_verifications' => [['status' => 'Declined', 'email' => 'jane@example.test', 'is_breached' => true, 'matches' => [['api_service' => 'supplier-x']]]],
  'poa_verifications' => [['status' => 'Approved', 'document_type' => 'Utility bill', 'issuing_state' => 'ESP', 'document_file' => 'https://images.example/poa.pdf']],
  'database_validations' => [['status' => 'Approved', 'match_type' => 'full_match', 'issuing_state' => 'ESP']],
  'bank_verifications' => [['status' => 'Approved', 'provider' => 'supplier-y']],
];
$summary = Didit_Verify_Report::summarize($decision);
$types = array_column($summary['checks'], 'type');
check(['id', 'liveness', 'face_match', 'aml', 'phone', 'email', 'poa', 'database', 'bank'] === $types, 'every check in the decision is summarized');
$stored = json_encode($summary);
foreach (['images.example', 'supplier-x', 'supplier-y', 'Vendor list', 'Spain - Id Card', 'Carrier', 'Juana Real', 'X1234567'] as $forbidden) {
  check(false === strpos($stored, $forbidden), "the summary never stores \"$forbidden\"");
}
check('Identity Card · National ID · ESP' === $summary['checks'][0]['document'] && 34 === $summary['checks'][0]['age'],
  'the document is described by type, subtype and country, with the age');
$aml = $summary['checks'][3];
check(2 === $aml['hits_total'] && $aml['monitoring'] && '2026-11-20T10:00:00Z' === $aml['last_screened'] && $aml['hits'][0]['pep'],
  'AML keeps hits, PEP flags and the ongoing-monitoring state');

$kyb = Didit_Verify_Report::summarize([
  'session_id' => $id, 'session_kind' => 'business', 'status' => 'In Review',
  'registry_checks' => [['status' => 'Approved', 'company' => ['company_name' => 'Acme SL', 'registration_number' => 'B123', 'country_code' => 'ES', 'risk_level' => 'LOW', 'officers' => [['name' => 'x']]]]],
  'key_people_checks' => [['status' => 'In Review', 'ubo_kyc_summary' => ['total' => 2, 'approved' => 1]]],
]);
check('business' === $kyb['kind'] && 'Acme SL' === $kyb['checks'][0]['company'] && 'key_people' === $kyb['checks'][1]['type'],
  'business sessions show the registry and key people results');

// Questionnaires and business documents keep only statuses, types and the questionnaire title.
$extra = Didit_Verify_Report::summarize([
  'session_id' => $id, 'session_kind' => 'business', 'status' => 'In Review',
  'questionnaire_responses' => [[
    'node_id' => 'q1', 'questionnaire_id' => 'qq', 'title' => 'Source of funds', 'status' => 'Approved',
    'sections' => [['title' => 'S', 'items' => [
      ['uuid' => 'u1', 'element_type' => 'LONG_TEXT', 'title' => 'Where does the money come from?', 'answer' => ['value' => 'secret-answer-text']],
      ['uuid' => 'u2', 'element_type' => 'FILE_UPLOAD', 'answer' => ['files' => ['/media/ocr/secret-upload.pdf']]],
    ]]],
  ]],
  'document_verifications' => [[
    'node_id' => 'd1', 'status' => 'In Review', 'required_groups' => ['legal_presence', 'ownership'],
    'groups' => ['legal_presence' => ['approved' => 1, 'pending' => 0, 'missing' => 0], 'ownership' => ['approved' => 0, 'pending' => 0, 'missing' => 1]],
    'items' => [['uuid' => 'x', 'document_type' => 'certificate_of_incorporation', 'status' => 'Approved',
      'file_url' => 'https://images.example/cert.pdf', 'ocr_data' => ['company' => 'secret-ocr-text']]],
  ]],
  'document_ai_documents' => [['status' => 'Declined', 'items' => [['original_filename' => 'secret-file.pdf', 'file_url' => 'https://images.example/a.pdf', 'extracted_data' => ['x' => 'secret-extract']]]]],
]);
check(['documents', 'document_ai', 'questionnaire'] === array_column($extra['checks'], 'type'),
  'questionnaires, business documents and additional documents are summarized');
$byType = array_column($extra['checks'], null, 'type');
check('Approved' === $byType['questionnaire']['status'] && 'Source of funds' === $byType['questionnaire']['value'], 'a questionnaire keeps its status and title');
check('In Review' === $byType['documents']['status'] && in_array('certificate of incorporation: Approved', $byType['documents']['flags'], true)
  && in_array('missing ownership', $byType['documents']['flags'], true), 'business documents keep each type and status and the missing groups');
check('Declined' === $byType['document_ai']['status'], 'additional documents keep their status');
$stored = json_encode($extra);
foreach (['secret-answer-text', 'secret-upload', 'Where does the money', 'images.example', 'secret-ocr-text', 'secret-file', 'secret-extract'] as $forbidden) {
  check(false === strpos($stored, $forbidden), "questionnaire and document summaries never store \"$forbidden\"");
}
$extraHtml = Didit_Verify_Report::render($extra, $id);
check(false !== strpos($extraHtml, 'Questionnaire') && false !== strpos($extraHtml, 'Business documents') && false !== strpos($extraHtml, 'Additional documents'),
  'the new checks have readable labels');

// More results of one kind than are kept: the number left out is recorded and shown.
$many = Didit_Verify_Report::summarize(['session_id' => $id, 'status' => 'Approved',
  'face_matches' => array_fill(0, 5, ['status' => 'Approved', 'score' => 90])]);
check(3 === count($many['checks']) && ['face_match' => 2] === $many['truncated'], 'results beyond the cap are counted, not silently dropped');
check(false !== strpos(Didit_Verify_Report::render($many, $id), '2 more results not shown'), 'the admin view says results were left out');
check(!isset($summary['truncated']), 'nothing is marked as left out when every result fits');

// Location-only and e-signature workflows: the outcome is kept, never coordinates,
// addresses, signer details, signatures or documents.
$located = Didit_Verify_Report::summarize(['session_id' => $id, 'status' => 'Approved',
  'geolocation_checks' => [['node_id' => 'n1', 'status' => 'Approved', 'outcome' => 'verified', 'latitude' => 41.38791, 'longitude' => 2.16992,
    'country_code' => 'ES', 'city' => 'Secret City', 'parsed_address' => ['street' => 'Secret Street 1'], 'inside_geofence' => true, 'is_mock' => false]],
  'signatures' => [['id' => 'sig-1', 'status' => 'signed', 'verdict' => 'Approved', 'level' => 'aes',
    'signer' => ['email' => 'secret@example.com', 'name' => 'Secret Signer'], 'adopted_signature' => ['image' => 'data:secret'],
    'documents' => [['name' => 'secret-contract.pdf']], 'ip_address' => '203.0.113.9']]]);
$types = array_column($located['checks'], 'type');
check(['location', 'signature'] === $types, 'a location-only or e-signature decision produces a report');
check('Approved' === $located['checks'][0]['status'] && 'verified' === $located['checks'][0]['value'] && 'ES' === $located['checks'][0]['country'],
  'the location outcome and country are kept');
check('Approved' === $located['checks'][1]['status'] && 'signed' === $located['checks'][1]['value'], 'the e-signature verdict and signing state are kept');
$stored = json_encode($located);
check(false === strpos($stored, '41.38') && false === strpos($stored, '2.169') && false === strpos($stored, 'Secret'),
  'coordinates and addresses are never stored');
check(false === strpos($stored, 'secret') && false === strpos($stored, '203.0.113.9'), 'signer details, signatures and documents are never stored');
$locatedHtml = Didit_Verify_Report::render($located, $id);
check(false !== strpos($locatedHtml, 'Location') && false !== strpos($locatedHtml, 'E-signature') && false !== strpos($locatedHtml, 'inside the allowed area'),
  'location and e-signature results have readable labels');

// Business documents beyond the cap inside one result count toward "more results".
$docs = Didit_Verify_Report::summarize(['session_id' => $id, 'status' => 'Approved',
  'document_verifications' => [['status' => 'Approved', 'items' => array_fill(0, 8, ['document_type' => 'bank_statement', 'status' => 'Approved'])]]]);
check(['documents' => 3] === ($docs['truncated'] ?? null), 'nested business documents left out are counted');
check(false !== strpos(Didit_Verify_Report::render($docs, $id), '3 more results not shown'), 'the admin view says nested documents were left out');

$html = Didit_Verify_Report::render($summary, $id);
check(false === strpos($html, '<b>Doe</b>') && false !== strpos($html, 'Jane Doe'), 'markup in hit names is removed when stored');
$hostile = Didit_Verify_Report::render(['checks' => [['type' => 'aml', 'status' => '"><script>', 'hits_total' => 1, 'hits' => [['name' => '<img src=x onerror=1>']]]]]);
check(false === strpos($hostile, '<script>') && false === strpos($hostile, '<img'), 'stored values are escaped when shown');
check(false !== strpos($html, 'target="_blank" rel="noopener noreferrer"'), 'the console link opens in a new tab safely');
check(false !== strpos($html, 'ongoing monitoring on, last screened 2026-11-20T10:00:00Z'), 'the monitoring state is shown');
check('https://business.didit.me' === Didit_Verify_Report::console_url($id), 'without a console address the link opens the console home');
$GLOBALS['didit_test_options']['didit_console_url'] = 'https://business.didit.me/console/org-1/app-2/users';
check('https://business.didit.me/console/org-1/app-2/verifications?session-id=' . $id === Didit_Verify_Report::console_url($id),
  'with a console address the link opens the verification');
$GLOBALS['didit_test_options']['didit_console_url'] = 'https://evil.example/console/org-1/app-2/';
check('https://business.didit.me' === Didit_Verify_Report::console_url($id), 'a console address on another host is ignored');

// Confirming a session stores the summary on the user.
$GLOBALS['didit_test_options']['didit_api_key'] = 'test-only-key';
$GLOBALS['didit_test_options']['didit_workflow_id'] = 'wf-default';
$GLOBALS['didit_test_options']['didit_webhook_secret'] = 'test-only-webhook-secret';
$_COOKIE['didit_verification_owner'] = str_repeat('a', 64);
$owner = hash('sha256', $_COOKIE['didit_verification_owner']);
$plugin->store_session($id, ['owner' => $owner, 'user_id' => 42, 'order_id' => 0, 'status' => 'Not Started',
  'event_timestamp' => 0, 'consumed_order_id' => 0, 'placement' => 'default', 'workflow_id' => 'wf-default']);
$GLOBALS['didit_test_user_meta']['42|_didit_pending_session_id'] = $id;
$GLOBALS['didit_test_api_response'] = function () { return ['response' => ['code' => 200], 'body' => json_encode($GLOBALS['decision'])]; };
$GLOBALS['decision'] = $decision;
$result = $plugin->rest_save_verification(new Didit_Test_Request(json_encode(['type' => 'completed', 'sessionId' => $id])));
check('Approved' === $result['status'] && 'aml' === get_user_meta(42, '_didit_report')['checks'][3]['type'], 'the confirmed decision is summarized on the user');

function deliver($type, $status, $timestamp) {
  global $id;
  $body = json_encode(['session_id' => $id, 'session_kind' => 'user', 'status' => $status, 'timestamp' => $timestamp, 'webhook_type' => $type]);
  return Didit_Verify::init()->rest_webhook(new Didit_Test_Request($body, [
    'X-Signature' => hash_hmac('sha256', $body, 'test-only-webhook-secret'),
  ]));
}

// Ongoing monitoring finds a new hit later: data.updated carries no trusted results, so the decision is read again.
$GLOBALS['decision']['aml_screenings'][0]['total_hits'] = 3;
$GLOBALS['decision']['aml_screenings'][0]['last_ongoing_screening_at'] = '2026-11-25T08:00:00Z';
$GLOBALS['didit_test_api_calls'] = [];
$now = time();
$response = deliver('data.updated', 'Approved', $now);
check(!is_wp_error($response) && 1 === count($GLOBALS['didit_test_api_calls']), 'data.updated reads the decision from Didit');
check(3 === get_user_meta(42, '_didit_report')['checks'][3]['hits_total'], 'a later monitoring update reaches the user summary');

$completed = array_filter($GLOBALS['didit_test_actions'] ?? [], function ($a) { return 'didit_verification_completed' === $a[0]; });
$before = count($completed);
deliver('data.updated', 'Approved', $now + 1);
$completed = array_filter($GLOBALS['didit_test_actions'] ?? [], function ($a) { return 'didit_verification_completed' === $a[0]; });
check($before === count($completed), 'a monitoring refresh of an approved session does not fire the completion hook again');

$GLOBALS['didit_test_actions'] = [];
$GLOBALS['decision']['status'] = 'Declined';
deliver('status.updated', 'Declined', $now + 1);
$completed = array_filter($GLOBALS['didit_test_actions'], function ($a) { return 'didit_verification_completed' === $a[0]; });
check(1 === count($completed), 'a decision fires the completion hook');
deliver('status.updated', 'Declined', $now + 1);
$completed = array_filter($GLOBALS['didit_test_actions'], function ($a) { return 'didit_verification_completed' === $a[0]; });
check(1 === count($completed), 'a replayed delivery does not fire the completion hook again');
check('Declined' === $plugin->confirmed_user_status(42), 'the replay leaves the recorded status as it was');

$GLOBALS['didit_test_api_response'] = new WP_Error('offline');
$response = deliver('data.updated', 'Approved', $now + 2);
check(is_wp_error($response) && 503 === $response->get_error_data()['status'], 'data.updated asks Didit to retry when the decision cannot be read');
check('Declined' === $plugin->confirmed_user_status(42), 'an unreadable update changes nothing');

$GLOBALS['didit_test_api_response'] = function () { return ['response' => ['code' => 200], 'body' => json_encode(array_merge($GLOBALS['decision'], ['status' => 'Resubmitted']))]; };
deliver('data.updated', 'Resubmitted', $now + 3);
check('Resubmitted' === $plugin->confirmed_user_status(42), 'Resubmitted is recognized');
check(didit_test_internal('status_is_open', 'Resubmitted') && didit_test_internal('status_is_open', 'Awaiting User'), 'Resubmitted and Awaiting User are open statuses');
check(!$plugin->is_user_verified(42), 'a resubmission request does not approve');

echo $GLOBALS['didit_test_passed'] . " passed\n";
