<?php

namespace App\Core\SMS\Services;

use Illuminate\Support\Facades\Log;

class BulkSmsService
{
    private const URL  = 'http://bulksms.2way.co.za/eapi/submission/send_sms/2/2.0';
    private const PORT = 80;

    public static function send(string|array $numbers, string $message, string $username, string $password): bool
    {
        $msisdn = is_array($numbers) ? implode(',', $numbers) : $numbers;
        // Convert numbers starting with 0 to 27
        $msisdn = preg_replace('/\b0(\d+)/', '27$1', $msisdn);
        if (strlen($message) > 160) {
            $chunks  = str_split($message, 158);
            $success = false;
            foreach ($chunks as $chunk) {
                $success = self::dispatch($username, $password, $chunk, $msisdn);
            }
            return $success;
        }
        return self::dispatch($username, $password, $message, $msisdn);
    }

    private static function dispatch(string $username, string $password, string $message, string $msisdn): bool
    {
        $body = self::buildPostBody([
            'username' => $username,
            'password' => $password,
            'message'  => $message,
            'msisdn'   => $msisdn,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::URL,
            CURLOPT_PORT           => self::PORT,
            CURLOPT_POST           => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            Log::warning('BulkSMS dispatch failed', ['http_code' => $httpCode, 'curl_error' => $curlErr]);
            return false;
        }

        $parts      = explode('|', (string) $response);
        $statusCode = $parts[0] ?? '';

        if (in_array($statusCode, ['0', '1'], true)) {
            return true;
        }

        Log::warning('BulkSMS error response', ['status' => $statusCode, 'detail' => $parts[1] ?? '']);
        return false;
    }

    private static function buildPostBody(array $fields): string
    {
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= urlencode($key) . '=' . urlencode((string) $value) . '&';
        }
        return rtrim($body, '&');
    }
}
