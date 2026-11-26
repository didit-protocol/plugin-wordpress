<?php
/**
 * Workflow choices per placement: a rejected choice keeps the previous workflow, and the
 * selector offers only workflows of the matching type.
 *
 *   php tests/test-workflows.php
 */

if (!function_exists('add_settings_error')) {
  function add_settings_error($setting, $code, $message, $type = 'error') { $GLOBALS['didit_test_settings_errors'][] = $code; }
}
if (!function_exists('checked')) {
  function checked($a, $b, $echo = true) { $out = ((string) $a === (string) $b) ? ' checked="checked"' : ''; if ($echo) { echo $out; } return $out; }
}

require __DIR__ . '/wp-stubs-features.php';
require __DIR__ . '/../didit-verify.php';

$opts = &$GLOBALS['didit_test_options'];
$opts['didit_api_key'] = 'test-only-key';
$opts['didit_workflow_id'] = 'wf-default';
$GLOBALS['didit_test_transients'][Didit_Verify_Workflows::CACHE_KEY] = [
  ['id' => 'wf-age', 'label' => 'Age check', 'type' => 'kyc', 'features' => [], 'status' => '', 'is_default' => false],
  ['id' => 'wf-kyb', 'label' => 'Company check', 'type' => 'kyb', 'features' => [], 'status' => '', 'is_default' => false],
];

// A KYB workflow chosen for checkout is rejected; checkout keeps its stricter workflow.
$opts[Didit_Verify_Workflows::OPTION] = ['checkout' => 'wf-age', 'business' => 'wf-kyb'];
$GLOBALS['didit_test_settings_errors'] = [];
$saved = Didit_Verify_Workflows::sanitize_map(['checkout' => 'wf-kyb', 'business' => 'wf-kyb']);
check(['checkout' => 'wf-age', 'business' => 'wf-kyb'] == $saved, 'a rejected workflow choice keeps the previous workflow of that placement');
check(in_array('didit_workflow_type_checkout', $GLOBALS['didit_test_settings_errors'], true), 'the admin is told the choice was rejected');
$opts[Didit_Verify_Workflows::OPTION] = $saved;
check('wf-age' === Didit_Verify_Workflows::workflow_for('checkout'), 'checkout does not fall back to the default workflow after a rejection');

// A malformed ID is rejected the same way.
$saved = Didit_Verify_Workflows::sanitize_map(['checkout' => '<b>', 'business' => 'wf-kyb']);
check(['checkout' => 'wf-age', 'business' => 'wf-kyb'] == $saved, 'a malformed Workflow ID keeps the previous workflow');

// A placement without an earlier choice stays on the default; clearing a choice is allowed.
$saved = Didit_Verify_Workflows::sanitize_map(['registration' => 'wf-kyb', 'business' => 'wf-kyb']);
check(!isset($saved['registration']) && !isset($saved['checkout']), 'a rejected choice with nothing saved before stores nothing, and an emptied choice is cleared');

// The selector lists only workflows of the matching type.
$opts[Didit_Verify_Workflows::OPTION] = ['checkout' => 'wf-age', 'business' => 'wf-kyb'];
ob_start();
Didit_Verify_Workflows::render_placements();
$html = ob_get_clean();
preg_match_all('#<select name="didit_placements\[([a-z_]+)\]">(.*?)</select>#s', $html, $m, PREG_SET_ORDER);
$selects = [];
foreach ($m as $row) {
  $selects[$row[1]] = $row[2];
}
check(isset($selects['checkout'], $selects['business']), 'every placement has a selector');
check(false !== strpos($selects['checkout'], 'value="wf-age"') && false === strpos($selects['checkout'], 'value="wf-kyb"'), 'person placements do not offer KYB workflows');
check(false !== strpos($selects['business'], 'value="wf-kyb"') && false === strpos($selects['business'], 'value="wf-age"'), 'the business placement offers only KYB workflows');

echo $GLOBALS['didit_test_passed'] . " passed\n";
