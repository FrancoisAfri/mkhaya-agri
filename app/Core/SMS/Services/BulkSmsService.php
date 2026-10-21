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

        // Laravel's HTTP client rather than raw curl: the same request, and tests can fake it.
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(20)->connectTimeout(20)->withBody($body, 'application/x-www-form-urlencoded')->post(self::URL);
        } catch (\Throwable $e) {
            Log::warning('BulkSMS dispatch failed', ['error' => $e->getMessage()]);

            return false;
        }
        $response = $r->body();
        if ($response === '' || $r->status() !== 200) {
            Log::warning('BulkSMS dispatch failed', ['http_code' => $r->status()]);
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
