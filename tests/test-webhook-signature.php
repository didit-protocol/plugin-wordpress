<?php
/**
 * Webhook signature verification tests for the Didit Verify plugin.
 *
 * Dependency-free on purpose: WordPress plugins have no build step here, so this
 * runs anywhere PHP does.
 *
 *   php tests/test-webhook-signature.php
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-webhook-signature.php
 *
 * Fixtures in tests/fixtures/webhook-signatures.json are produced by
 * tests/generate-fixtures.py, which carries Didit's server-side signing code
 * verbatim -- so a passing run means PHP reproduces the exact bytes Python signed.
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../didit-verify.php';

$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/webhook-signatures.json'), true);
if (!is_array($fixtures)) {
  fwrite(STDERR, "Could not read tests/fixtures/webhook-signatures.json\n");
  exit(1);
}

$SECRET = $fixtures['secret'];
$WRONG_SECRET = 'wh_test_secret_wrong';

$passed = 0;
$failed = 0;

function ok($condition, $label)
{
  global $passed, $failed;
  if ($condition) {
    $passed++;
    echo "  PASS  {$label}\n";
    return;
  }
  $failed++;
  echo "  FAIL  {$label}\n";
}

/** Invoke one of the plugin's private static verification helpers. */
function call_private($method, array $args)
{
  $ref = new ReflectionMethod('Didit_Verify', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

/** Run the real REST handler against a synthetic request. */
function handle($body, array $headers)
{
  $ref = new ReflectionMethod('Didit_Verify', 'rest_webhook');
  $ref->setAccessible(true);
  return $ref->invoke(Didit_Verify::init(), new Didit_Test_Request($body, $headers));
}

/** Rebuild the delivery with a fresh timestamp so the 5-minute window passes. */
function freshen(array $fixture, $secret)
{
  $payload = json_decode($fixture['body'], true);
  $payload['timestamp'] = time();

  // Re-sign with the same algorithm the server uses, now that timestamp moved.
  $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
  $decoded = json_decode($body, false);
  $ref = new ReflectionMethod('Didit_Verify', 'canonicalize_signed_payload');
  $ref->setAccessible(true);
  $canonical_ascii = json_encode($ref->invoke(null, $decoded), JSON_UNESCAPED_SLASHES);
  $canonical_v2 = json_encode($ref->invoke(null, $decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  return [
    'body' => $canonical_ascii,
    'headers' => [
      'X-Signature' => hash_hmac('sha256', $canonical_ascii, $secret),
      'X-Signature-V2' => hash_hmac('sha256', $canonical_v2, $secret),
      'X-Signature-Simple' => hash_hmac('sha256', implode(':', [
        $payload['timestamp'], $payload['session_id'], $payload['status'], $payload['webhook_type'],
      ]), $secret),
      'X-Timestamp' => (string) $payload['timestamp'],
    ],
    'payload' => $payload,
  ];
}

echo "\n== Canonical form matches Didit's server-side signing (per fixture) ==\n";
foreach ($fixtures['cases'] as $name => $case) {
  $body = $case['body'];

  ok(
    call_private('verify_signature_v2', [$body, $case['signatures']['v2'], $SECRET]),
    "{$name}: X-Signature-V2 verifies"
  );
  ok(
    call_private('verify_signature_raw', [$body, $case['signatures']['raw'], $SECRET]),
    "{$name}: X-Signature (raw bytes) verifies"
  );
  ok(
    call_private('verify_signature_simple', [json_decode($body, true), $case['signatures']['simple'], $SECRET]),
    "{$name}: X-Signature-Simple verifies"
  );
  ok(
    !call_private('verify_signature_v2', [$body, $case['signatures']['v2'], $WRONG_SECRET]),
    "{$name}: X-Signature-V2 rejects the wrong secret"
  );
}

echo "\n== The failures this change exists to fix ==\n";

// Failure mode 2 from the report: middleware re-encodes the body before PHP sees it.
$case = $fixtures['cases']['unicode_names'];
$reencoded = json_encode(json_decode($case['body'], false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ok($reencoded !== $case['body'], 'middleware re-encoding really does change the bytes');
ok(
  !call_private('verify_signature_raw', [$reencoded, $case['signatures']['raw'], $SECRET]),
  'X-Signature fails on a re-encoded body (the old handler stopped here)'
);
ok(
  call_private('verify_signature_v2', [$reencoded, $case['signatures']['v2'], $SECRET]),
  'X-Signature-V2 still verifies the re-encoded body'
);

// Failure mode 1: a proxy strips individual X-* headers.
$fresh = freshen($fixtures['cases']['basic_approved'], $SECRET);
$request_headers = $fresh['headers'];

ok(
  'v2' === call_private('verify_webhook_signature', [
    new Didit_Test_Request($fresh['body'], $request_headers), $fresh['body'], $fresh['payload'], $SECRET,
  ]),
  'all headers present: X-Signature-V2 is preferred'
);

$without_v2 = $request_headers;
unset($without_v2['X-Signature-V2']);
ok(
  'raw' === call_private('verify_webhook_signature', [
    new Didit_Test_Request($fresh['body'], $without_v2), $fresh['body'], $fresh['payload'], $SECRET,
  ]),
  'X-Signature-V2 stripped: falls back to legacy X-Signature'
);

$simple_only = ['X-Signature-Simple' => $request_headers['X-Signature-Simple'], 'X-Timestamp' => $request_headers['X-Timestamp']];
ok(
  'simple' === call_private('verify_webhook_signature', [
    new Didit_Test_Request($fresh['body'], $simple_only), $fresh['body'], $fresh['payload'], $SECRET,
  ]),
  'only X-Signature-Simple survives: still verified (the reported lockout)'
);

ok(
  '' === call_private('verify_webhook_signature', [
    new Didit_Test_Request($fresh['body'], ['X-Timestamp' => $request_headers['X-Timestamp']]),
    $fresh['body'], $fresh['payload'], $SECRET,
  ]),
  'no signature header at all: rejected'
);

ok(
  '' === call_private('verify_webhook_signature', [
    new Didit_Test_Request($fresh['body'], $request_headers), $fresh['body'], $fresh['payload'], $WRONG_SECRET,
  ]),
  'wrong secret: every variant rejected'
);

$tampered = str_replace('"Approved"', '"Declined"', $fresh['body']);
ok(
  '' === call_private('verify_webhook_signature', [
    new Didit_Test_Request($tampered, $request_headers), $tampered, json_decode($tampered, true), $SECRET,
  ]),
  'tampered body: every variant rejected'
);

echo "\n== rest_webhook end to end ==\n";

$GLOBALS['didit_test_options']['didit_webhook_secret'] = $SECRET;
$GLOBALS['didit_test_users'] = [42 => $fixtures['session_id']];

$response = handle($fresh['body'], $request_headers);
ok(is_array($response) && !empty($response['received']), 'full delivery is accepted');
ok(
  ($GLOBALS['didit_test_user_meta']['42|_didit_status'] ?? null) === 'Approved',
  'approved delivery marks the mapped user verified'
);

// The regression the old handler produced: X-Signature stripped by a proxy -> 401.
$GLOBALS['didit_test_user_meta'] = [];
$response = handle($fresh['body'], $simple_only);
ok(is_array($response) && !empty($response['received']), 'envelope-only delivery is accepted, not 401');
ok(
  ($GLOBALS['didit_test_user_meta']['42|_didit_status'] ?? null) === 'Approved',
  'envelope-only delivery resolves the user from the stored session mapping'
);

// X-Signature-Simple leaves metadata unsigned, so it must not steer the update.
$GLOBALS['didit_test_user_meta'] = [];
$GLOBALS['didit_test_users'] = [42 => $fixtures['session_id'], 1 => 'some-other-session'];
$spoofed_payload = $fresh['payload'];
$spoofed_payload['metadata'] = ['wp_user_id' => 1];
$spoofed_payload['vendor_data'] = 'wp-1';
$spoofed_body = json_encode($spoofed_payload, JSON_UNESCAPED_SLASHES);
$response = handle($spoofed_body, $simple_only);
ok(is_array($response) && !empty($response['received']), 'envelope-only delivery with rewritten metadata is still accepted');
ok(
  !isset($GLOBALS['didit_test_user_meta']['1|_didit_verified']),
  'unsigned metadata cannot redirect the result to another user'
);
ok(
  ($GLOBALS['didit_test_user_meta']['42|_didit_status'] ?? null) === 'Approved',
  'the stored mapping wins over unsigned metadata'
);

// Signed deliveries keep honouring metadata.wp_user_id, as before.
$GLOBALS['didit_test_user_meta'] = [];
$GLOBALS['didit_test_users'] = [7 => 'unrelated-session'];
$signed = freshen($fixtures['cases']['basic_approved'], $SECRET);
$signed_payload = $signed['payload'];
$signed_payload['metadata'] = ['wp_user_id' => 7];
$resigned = freshen(['body' => json_encode($signed_payload, JSON_UNESCAPED_SLASHES)], $SECRET);
$response = handle($resigned['body'], $resigned['headers']);
ok(
  ($GLOBALS['didit_test_user_meta']['7|_didit_status'] ?? null) === 'Approved',
  'signed delivery still routes by metadata.wp_user_id'
);

// Freshness.
$GLOBALS['didit_test_users'] = [42 => $fixtures['session_id']];
$stale = $fixtures['cases']['basic_approved'];
$stale_response = handle($stale['body'], [
  'X-Signature' => $stale['signatures']['raw'],
  'X-Signature-V2' => $stale['signatures']['v2'],
  'X-Timestamp' => (string) time(), // freshened header over an old signed body
]);
ok(
  $stale_response instanceof WP_Error && 401 === ($stale_response->get_error_data()['status'] ?? 0),
  'replay with a freshened X-Timestamp header is rejected on the signed body timestamp'
);

$no_ts_headers = $request_headers;
unset($no_ts_headers['X-Timestamp']);
$response = handle($fresh['body'], $no_ts_headers);
ok(is_array($response) && !empty($response['received']), 'X-Timestamp stripped: signed body timestamp carries the freshness check');

$response = handle('not json', $request_headers);
ok(
  $response instanceof WP_Error && 400 === ($response->get_error_data()['status'] ?? 0),
  'unparseable body is a 400'
);

$GLOBALS['didit_test_options']['didit_webhook_secret'] = '';
$response = handle($fresh['body'], $request_headers);
ok(
  $response instanceof WP_Error && 501 === ($response->get_error_data()['status'] ?? 0),
  'unconfigured secret still returns 501'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
