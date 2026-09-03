<?php
/**
 * Shortcode asset loading tests for the Didit Verify plugin.
 *
 *   php tests/test-shortcode-assets.php
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

ok(empty($GLOBALS['didit_test_enqueued_scripts']), 'plugin load does not enqueue frontend scripts by itself');
ok(empty($GLOBALS['didit_test_enqueued_styles']), 'plugin load does not enqueue frontend styles by itself');

$plugin->enqueue_scripts();

ok(isset($GLOBALS['didit_test_enqueued_styles']['didit-verify']), 'frontend enqueue prints shortcode stylesheet before header closes');
ok(!empty($GLOBALS['didit_test_inline_styles']['didit-verify']), 'frontend enqueue prints button appearance CSS before header closes');
ok(empty($GLOBALS['didit_test_enqueued_scripts']), 'frontend enqueue without detected shortcode does not enqueue SDK scripts');

$style_count = count($GLOBALS['didit_test_enqueued_styles']);
$inline_style_count = count($GLOBALS['didit_test_inline_styles']['didit-verify']);

$html = $plugin->render_shortcode([]);

ok(false !== strpos($html, 'class="didit-verify-btn"'), 'shortcode renders the verification button');
ok(isset($GLOBALS['didit_test_enqueued_styles']['didit-verify']), 'shortcode render enqueues plugin stylesheet');
ok(isset($GLOBALS['didit_test_enqueued_scripts']['didit-sdk']), 'shortcode render enqueues Didit SDK');
ok(isset($GLOBALS['didit_test_enqueued_scripts']['didit-verify']), 'shortcode render enqueues frontend integration script');
ok(isset($GLOBALS['didit_test_localized_scripts']['didit-verify']['diditConfig']), 'shortcode render localizes frontend config');
ok(!empty($GLOBALS['didit_test_inline_styles']['didit-verify']), 'shortcode render adds button appearance CSS');
ok($style_count === count($GLOBALS['didit_test_enqueued_styles']), 'late shortcode render reuses the header stylesheet');
ok($inline_style_count === count($GLOBALS['didit_test_inline_styles']['didit-verify']), 'late shortcode render reuses header button appearance CSS');

$script_count = count($GLOBALS['didit_test_enqueued_scripts']);
$plugin->render_shortcode(['mode' => 'embedded']);

ok($script_count === count($GLOBALS['didit_test_enqueued_scripts']), 'repeated shortcode render keeps script enqueue one-shot');
ok($inline_style_count === count($GLOBALS['didit_test_inline_styles']['didit-verify']), 'repeated shortcode render keeps inline CSS one-shot');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
