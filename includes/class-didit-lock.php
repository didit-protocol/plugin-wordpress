<?php
/**
 * Short mutexes stored as options rows. Each holder gets its own token, so a request
 * whose lock expired cannot release the lock of the request that took it over, and an
 * expired row can be taken over by only one request (a conditional UPDATE on the old
 * value).
 */

if (!defined('ABSPATH')) {
  exit;
}

class Didit_Verify_Lock
{
  /**
   * Returns the holder's token, or '' when the lock stayed busy for about one second.
   */
  public static function acquire(string $name, int $ttl = 30): string
  {
    for ($i = 0; $i < 20; $i++) {
      $token = bin2hex(random_bytes(10));
      $value = (time() + $ttl) . '|' . $token;
      if (self::insert($name, $value)) {
        return $token;
      }
      $current = self::read($name);
      $expires = (int) strtok((string) $current, '|');
      if ('' !== (string) $current && $expires < time() && self::replace($name, (string) $current, $value)) {
        return $token;
      }
      if ('' === (string) $current) {
        continue;
      }
      usleep(50000);
    }
    return '';
  }

  /** Releases the lock only if this token still holds it. */
  public static function release(string $name, string $token): bool
  {
    if ('' === $token) {
      return false;
    }
    global $wpdb;
    $current = (string) self::read($name);
    if ('' === $current || !hash_equals(substr($current, (int) strpos($current, '|') + 1), $token)) {
      return false;
    }
    if (self::has_db()) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- conditional delete keeps a successor's lock.
      return 1 === (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $current));
    }
    delete_option($name);
    return true;
  }

  private static function has_db(): bool
  {
    global $wpdb;
    return is_object($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'query');
  }

  private static function insert(string $name, string $value): bool
  {
    global $wpdb;
    if (self::has_db()) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic insert is the point; the row is never cached.
      return 1 === (int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value));
    }
    return (bool) add_option($name, $value, '', 'no');
  }

  private static function read(string $name)
  {
    global $wpdb;
    if (self::has_db()) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery
      return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }
    return get_option($name, '');
  }

  private static function replace(string $name, string $old, string $new): bool
  {
    global $wpdb;
    if (self::has_db()) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only one request can swap the expired value.
      return 1 === (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $name, $old));
    }
    if (get_option($name, '') !== $old) {
      return false;
    }
    update_option($name, $new, false);
    return true;
  }
}
