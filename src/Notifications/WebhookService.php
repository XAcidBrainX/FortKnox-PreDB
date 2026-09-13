<?php

declare(strict_types=1);

namespace FortKnox\Notifications;

final class WebhookService
{
    public function __construct(array $env = [])
    {
    }

    public function send(string $title, string $message, string $type = 'info'): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (!file_exists($envFile)) {
            error_log("Webhook Error: .env file not found at " . $envFile);
            return;
        }

        $env = parse_ini_file($envFile);
        if ($env === false) {
            error_log("Webhook Error: Could not parse .env file");
            return;
        }

        $enabled = $env['WEBHOOK_ENABLED'] ?? 'false';
        if (filter_var($enabled, FILTER_VALIDATE_BOOLEAN) !== true) {
            error_log("Webhook Skipped: WEBHOOK_ENABLED is not true (Value was: " . var_export($enabled, true) . ")");
            return;
        }

        $url = trim((string) ($env['WEBHOOK_URL'] ?? ''));
        if ($url === '' || !str_starts_with($url, 'http')) {
            error_log("Webhook Error: Invalid or empty WEBHOOK_URL");
            return;
        }

        $color = match ($type) {
            'success', 'release' => 3066993, // Grün
            'danger', 'nuke'     => 15158332, // Rot
            'warning'            => 16776960, // Gelb
            default              => 3447003,  // Blau
        };

        $payload = json_encode([
            'embeds' => [
                [
                    'title' => $title,
                    'description' => $message,
                    'color' => $color,
                    'timestamp' => date('c'),
                ]
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("Webhook SUCCESS: Sent to Discord!");
        } else {
            error_log("Webhook FAILED: HTTP Code {$httpCode}, cURL Error: {$curlError}, Response: {$response}");
        }
    }
}
