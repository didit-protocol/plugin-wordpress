<?php
/**
 * Small client for the Didit verification API, shared by the plugin's features.
 *
 * The API key never leaves the server and is never logged.
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Didit_Verify_Api
{
  const BASE_URL = 'https://verification.didit.me';

  /**
   * Call the API and return the decoded body.
   *
   * @param string     $method  GET or POST.
   * @param string     $path    Path starting with /v3/.
   * @param array|null $body    JSON body for POST.
   * @param array      $headers Extra headers (for example Idempotency-Key).
   * @param int        $timeout Seconds.
   * @return array|WP_Error Decoded JSON object, or an error that is safe to show to an admin.
   */
  public static function request(string $method, string $path, $body = null, array $headers = [], int $timeout = 15)
  {
    $api_key = (string) get_option('didit_api_key', '');
    if ('' === $api_key) {
      return new WP_Error('didit_not_configured', __('Add your Didit API key first.', 'didit-verify'), ['status' => 503]);
    }
    $args = [
      'headers' => array_merge(['x-api-key' => $api_key, 'Accept' => 'application/json'], $headers),
      'timeout' => $timeout,
    ];
    $url = self::BASE_URL . $path;
    if ('POST' === $method) {
      $args['headers']['Content-Type'] = 'application/json';
      $args['body'] = wp_json_encode(null === $body ? new stdClass() : $body);
      $response = wp_remote_post($url, $args);
    } else {
      $response = wp_remote_get($url, $args);
    }
    if (is_wp_error($response) || !is_array($response)) {
      return new WP_Error('didit_unreachable', __('Didit could not be reached. Please try again.', 'didit-verify'), ['status' => 503]);
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (401 === $code || 403 === $code) {
      return new WP_Error('didit_unauthorized', __('Didit rejected the API key. Check it in the Connection settings.', 'didit-verify'), ['status' => $code]);
    }
    if ($code < 200 || $code >= 300 || !is_array($data)) {
      return new WP_Error('didit_api_error', __('Didit returned an error. Please try again.', 'didit-verify'), ['status' => $code ?: 502]);
    }
    return $data;
  }
}
