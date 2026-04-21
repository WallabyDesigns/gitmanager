<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupportService
{
    public function __construct(
        private readonly LicenseService $license,
        private readonly EditionService $edition,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('gitmanager.support.enabled', true);
    }

    public function endpoint(): string
    {
        return $this->resolveSupportBaseUrl();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTickets(): array
    {
        $json = $this->request('get', 'tickets');
        $tickets = $json['tickets'] ?? [];

        return is_array($tickets) ? $tickets : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createTicket(string $subject, string $message, string $priority = 'normal'): array
    {
        $json = $this->request('post', 'tickets', [
            'subject' => $subject,
            'message' => $message,
            'priority' => $priority,
        ]);

        $ticket = $json['ticket'] ?? null;
        if (! is_array($ticket)) {
            throw new RuntimeException('Support API did not return a ticket payload.');
        }

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTicket(int $ticketId): array
    {
        $json = $this->request('get', 'tickets/'.$ticketId);
        $ticket = $json['ticket'] ?? null;
        if (! is_array($ticket)) {
            throw new RuntimeException('Support API did not return the requested ticket.');
        }

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    public function addMessage(int $ticketId, string $message): array
    {
        $json = $this->request('post', 'tickets/'.$ticketId.'/messages', [
            'message' => $message,
        ]);

        $ticket = $json['ticket'] ?? null;
        if (! is_array($ticket)) {
            throw new RuntimeException('Support API did not return an updated ticket payload.');
        }

        return $ticket;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Support integration is disabled.');
        }

        $baseUrl = $this->resolveSupportBaseUrl();
        if ($baseUrl === '') {
            throw new RuntimeException('Support API endpoint is not configured.');
        }
        if (! $this->isEndpointAllowed($baseUrl)) {
            throw new RuntimeException('Support API endpoint must use HTTPS in production.');
        }

        $context = $this->license->supportAuthContext();
        $licenseKey = trim((string) ($context['license_key'] ?? ''));
        $installationUuid = strtolower(trim((string) ($context['installation_uuid'] ?? '')));
        $testingBypass = false;

        if ($licenseKey === '' && $this->localTestingBypassEnabled()) {
            $testingBypass = true;
            $licenseKey = 'gwm_local_testing_support';
        }

        if ($licenseKey === '' || $installationUuid === '') {
            throw new RuntimeException('A verified enterprise license key is required to contact support.');
        }

        $request = Http::timeout(max(3, (int) config('gitmanager.support.timeout', 15)))
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($licenseKey, $installationUuid)
            ->withHeaders([
                'X-GWM-License-Key' => $licenseKey,
                'X-GWM-License-Installation' => $installationUuid,
                'X-GWM-App-Url' => trim((string) ($context['app_url'] ?? '')),
                'X-GWM-App-Name' => trim((string) ($context['app_name'] ?? '')),
            ]);

        if ($testingBypass) {
            $request = $request->withHeaders([
                'X-GWM-Testing-Bypass' => '1',
            ]);
        }

        $publicIp = trim((string) ($context['public_ip'] ?? ''));
        if ($publicIp !== '') {
            $request = $request->withHeaders(['X-GWM-Public-IP' => $publicIp]);
        }

        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        if ($this->license->allowInsecureLocalTlsForEndpoint($url)) {
            $request = $request->withOptions(['verify' => false]);
        }

        try {
            $response = match (strtolower($method)) {
                'get' => $request->get($url, $payload),
                'post' => $request->post($url, $payload),
                'put' => $request->put($url, $payload),
                'delete' => $request->delete($url, $payload),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };
        } catch (\Throwable $exception) {
            throw new RuntimeException($this->formatRequestExceptionMessage($exception));
        }

        if (! $response->successful()) {
            $message = trim((string) ($response->json('message') ?? ''));
            if ($message === '') {
                $message = trim((string) $response->body());
            }
            if ($message === '') {
                $message = 'Support server returned HTTP '.$response->status().'.';
            }

            throw new RuntimeException($message);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Support server returned an invalid response.');
        }

        return $json;
    }

    private function resolveSupportBaseUrl(): string
    {
        $configured = $this->envOverrideAllowed()
            ? trim((string) config('gitmanager.support.api_url', ''))
            : '';
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $verifyUrl = trim($this->license->resolvedVerifyUrl());
        if ($verifyUrl === '') {
            return '';
        }

        $parts = parse_url($verifyUrl);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.'/api/v1/support';
    }

    private function envOverrideAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function localTestingBypassEnabled(): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        if (! $this->edition->canSwapForTesting()) {
            return false;
        }

        return $this->edition->current() === EditionService::ENTERPRISE;
    }

    private function isEndpointAllowed(string $endpoint): bool
    {
        $normalized = strtolower(trim($endpoint));
        if ($normalized === '') {
            return false;
        }

        if (str_starts_with($normalized, 'https://')) {
            return true;
        }

        if (app()->environment(['local', 'testing'])) {
            return str_starts_with($normalized, 'http://');
        }

        return false;
    }

    private function formatRequestExceptionMessage(\Throwable $exception): string
    {
        $raw = trim($exception->getMessage());
        $lower = strtolower($raw);

        if (str_contains($lower, 'curl error 60')) {
            if (app()->environment(['local', 'testing'])) {
                return 'Support request failed: SSL trust store is missing or outdated on this server (cURL error 60). Install/update CA certificates, or for local testing only enable the local TLS repair in System -> Edition & License or set GWM_LICENSE_ALLOW_INSECURE_LOCAL_TLS=true.';
            }

            return 'Support request failed: SSL trust store is missing or outdated on this server (cURL error 60). Install/update CA certificates (for example apt install ca-certificates), then restart PHP-FPM/web server.';
        }

        return 'Support request failed: '.$raw;
    }
}
