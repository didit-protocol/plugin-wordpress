<?php
/**
 * The bundled SDK must delegate camera access to its verification frame.
 *
 * The liveness step runs inside the SDK's iframe. Without `camera` in the
 * frame's allow attribute the hosted flow cannot open a camera at all, in
 * modal or embedded mode, whatever the browser has granted the site.
 *
 *   php tests/test-iframe-permissions.php
 */

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

$bundle = (string) file_get_contents(__DIR__ . '/../assets/js/didit-sdk.umd.min.js');

ok('' !== $bundle, 'the plugin ships a bundled SDK');

preg_match_all('/setAttribute\(\s*["\']allow["\']\s*,\s*["\']([^"\']*)["\']/', $bundle, $matches);
$frames = $matches[1] ?? [];

ok(2 <= count($frames), 'the modal and the embedded frame both declare permissions');

foreach ($frames as $index => $allow) {
  $directives = array_map('trim', explode(';', $allow));
  ok(in_array('camera', $directives, true), sprintf('frame %d delegates camera', $index + 1));
  ok(in_array('microphone', $directives, true), sprintf('frame %d delegates microphone', $index + 1));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
