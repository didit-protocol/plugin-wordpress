<?php
/**
 * The documented escape hatch when the camera cannot open inside the frame.
 *
 * Didit's embedding docs recommend opening the hosted page at the top level
 * (redirect) when iframe camera access fails. Plugin 0.3.4 only offers modal
 * and embedded, so a shopper whose liveness step cannot reach the camera has
 * nowhere to go (DID-10021, question 3).
 *
 *   php tests/test-redirect-fallback.php
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../didit-verify.php';

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

$plugin = Didit_Verify::init();

// 1. The setting the shop owner needs is offered.
ob_start();
$plugin->field_display_mode();
$settings = ob_get_clean();
ok(false !== strpos($settings, 'value="redirect"'), 'Display Mode offers the documented redirect option');

// 2. The chosen mode reaches the browser integration.
$GLOBALS['didit_test_options']['didit_display_mode'] = 'redirect';
$plugin->render_shortcode([]);
$config = $GLOBALS['didit_test_localized_scripts']['didit-verify']['diditConfig'] ?? [];
ok('redirect' === ($config['displayMode'] ?? ''), 'the redirect mode reaches the frontend config');

// 3. In redirect mode the flow must run on the hosted page, not inside a frame.
$script = (string) file_get_contents(__DIR__ . '/../assets/js/didit-verify.js');
ok((bool) preg_match('/displayMode\s*===\s*["\']redirect["\']/', $script)
  && (bool) preg_match('/location\.(assign|replace|href)/', $script),
  'the frontend continues on the hosted page in redirect mode');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
