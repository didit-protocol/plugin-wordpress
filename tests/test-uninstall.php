<?php
/**
 * Uninstall removes what the plugin created: scheduled jobs (including WP-Cron events
 * scheduled with an order ID), marked result notes, sign-in links and stored results,
 * while merchant notes stay.
 *
 *   php tests/test-uninstall.php
 */

require __DIR__ . '/wp-stubs-features.php';
define('WP_UNINSTALL_PLUGIN', 'didit-verify/didit-verify.php');

/** Records queries and answers the few lookups uninstall makes. */
class Didit_Test_Wpdb {
  public $prefix = 'wp_';
  public $options = 'wp_options';
  public $usermeta = 'wp_usermeta';
  public $comments = 'wp_comments';
  public $commentmeta = 'wp_commentmeta';
  public $postmeta = 'wp_postmeta';
  public $queries = [];
  public function esc_like($text) { return addcslashes($text, '_%\\'); }
  public function prepare($query, ...$args) {
    foreach ($args as $arg) { $query = preg_replace('/%s/', "'" . $arg . "'", $query, 1); }
    return $query;
  }
  public function get_col($query) {
    $this->queries[] = $query;
    if (false !== strpos($query, 'wp_comments') && false !== strpos($query, "'_didit_result_note'") && false !== strpos($query, "'order_note'")) {
      return ['501', '502'];
    }
    if (false !== strpos($query, 'wp_comments') && false !== strpos($query, "comment_author = 'WooCommerce'") && false !== strpos($query, "'Didit:%'")) {
      return ['502', '503'];
    }
    if (false !== strpos($query, 'wp_usermeta') && false !== strpos($query, 'auth\_link\_')) {
      return ['_didit_auth_link_1_abc'];
    }
    if (false !== strpos($query, 'didit\_registration\_')) {
      return ['didit_registration_xyz'];
    }
    return [];
  }
  public function get_var($query) { $this->queries[] = $query; return null; }
  public function query($query) { $this->queries[] = $query; return 0; }
}
$GLOBALS['wpdb'] = new Didit_Test_Wpdb();
$GLOBALS['didit_test_options']['didit_aml_enabled'] = true;
$GLOBALS['didit_test_options']['didit_registration_xyz'] = 'x';
$GLOBALS['didit_test_deleted_metadata'] = [];

require __DIR__ . '/../uninstall.php';

$unscheduled = $GLOBALS['didit_test_unscheduled'];
check(in_array(['wp', 'didit_aml_screen_order'], $unscheduled, true), 'WP-Cron AML events scheduled with an order ID are removed hook-wide');
check(in_array(['wp', 'didit_wc_verification_reminder'], $unscheduled, true), 'WP-Cron reminders scheduled with an order ID are removed hook-wide');
check(in_array(['as', 'didit_aml_screen_order'], $unscheduled, true), 'Action Scheduler AML jobs are removed');
check([501, 502, 503] === $GLOBALS['didit_test_deleted_comments'], 'marked result notes and unmarked notes of earlier versions are deleted, each once');
$legacy_query = implode("\n", array_filter($GLOBALS['wpdb']->queries, function ($q) { return false !== strpos($q, "'Didit:%'"); }));
check(false !== strpos($legacy_query, "comment_author = 'WooCommerce'"), 'only system notes are taken as notes of earlier versions, never a person\'s note');
check(!array_key_exists('didit_aml_enabled', $GLOBALS['didit_test_options']) && !array_key_exists('didit_registration_xyz', $GLOBALS['didit_test_options']),
  'settings and registration records are deleted');
$meta = array_column($GLOBALS['didit_test_deleted_metadata'], 1);
check(in_array('_didit_auth_link_1_abc', $meta, true) && in_array('_didit_auth_sub', $meta, true) && in_array('_didit_workflow_sessions', $meta, true),
  'sign-in links of every site and workflow sessions are deleted');

echo $GLOBALS['didit_test_passed'] . " passed\n";
