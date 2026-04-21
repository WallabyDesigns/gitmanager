<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LicenseService
{
    private const EDITION_COMMUNITY = EditionService::COMMUNITY;
    private const EDITION_ENTERPRISE = EditionService::ENTERPRISE;
    private const SETTING_KEY = 'system.license.key';
    private const SETTING_STATUS = 'system.license.status';
    private const SETTING_MESSAGE = 'system.license.message';
    private const SETTING_EDITION = 'system.license.edition';
    private const SETTING_INSTALLATION_UUID = 'system.license.installation_uuid';
    private const SETTING_BOUND_IP = 'system.license.bound_ip';
    private const SETTING_DETECTED_IP = 'system.license.detected_ip';
    private const SETTING_VERIFIED_AT = 'system.license.verified_at';
    private const SETTING_EXPIRES_AT = 'system.license.expires_at';
    private const SETTING_GRACE_ENDS_AT = 'system.license.grace_ends_at';
    private const SETTING_ALLOW_INSECURE_LOCAL_TLS = 'system.license.allow_insecure_local_tls';
    private const SETTING_REQUEST_SIGNING_SECRET = 'system.license.request_signing_secret';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function keyConfigured(): bool
    {
        return trim($this->licenseKey()) !== '';
    }

    public function setLicenseKey(string $key): void
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return;
        }

        $this->settings->setEncrypted(self::SETTING_KEY, $trimmed);
        $this->settings->set(self::SETTING_STATUS, 'unverified');
        $this->settings->set(self::SETTING_MESSAGE, 'License key saved. Verify to activate.');
        $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
    }

    public function clearLicense(): void
    {
        $this->settings->set(self::SETTING_KEY, '');
        $this->settings->set(self::SETTING_STATUS, 'missing');
        $this->settings->set(self::SETTING_MESSAGE, 'No license key configured.');
        $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
        $this->settings->set(self::SETTING_BOUND_IP, '');
        $this->settings->set(self::SETTING_DETECTED_IP, '');
        $this->settings->set(self::SETTING_VERIFIED_AT, '');
        $this->settings->set(self::SETTING_EXPIRES_AT, '');
        $this->settings->set(self::SETTING_GRACE_ENDS_AT, '');
    }

    public function markInvalid(string $message = 'License is invalid.'): void
    {
        $this->settings->set(self::SETTING_STATUS, 'invalid');
        $this->settings->set(self::SETTING_MESSAGE, trim($message) !== '' ? trim($message) : 'License is invalid.');
        $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
        $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());
    }

    public function installationUuid(): string
    {
        $uuid = trim((string) $this->settings->get(self::SETTING_INSTALLATION_UUID, ''));
        if (Str::isUuid($uuid)) {
            return strtolower($uuid);
        }

        $uuid = (string) Str::uuid();
        $this->settings->set(self::SETTING_INSTALLATION_UUID, $uuid);

        return strtolower($uuid);
    }

    /**
     * @return array{
     *   license_key: string,
     *   installation_uuid: string,
     *   app_url: string,
     *   app_name: string,
     *   public_ip: ?string
     * }
     */
    public function supportAuthContext(bool $allowRemoteIpLookup = false): array
    {
        return [
            'license_key' => $this->licenseKey(),
            'installation_uuid' => $this->installationUuid(),
            'app_url' => (string) config('app.url'),
            'app_name' => (string) config('app.name'),
            'public_ip' => $this->detectPublicIp($allowRemoteIpLookup),
        ];
    }

    public function resolvedVerifyUrl(): string
    {
        return $this->verifyUrl();
    }

    /**
     * @return array{
     *   configured: bool,
     *   status: string,
     *   message: string,
     *   edition: string,
     *   installation_uuid: string,
     *   bound_ip: ?string,
     *   detected_ip: ?string,
     *   verified_at: ?string,
     *   expires_at: ?string,
     *   grace_ends_at: ?string
     * }
     */
    public function state(): array
    {
        $status = (string) $this->settings->get(self::SETTING_STATUS, 'missing');
        $message = (string) $this->settings->get(self::SETTING_MESSAGE, 'No license key configured.');
        $edition = $this->normalizeEdition((string) $this->settings->get(self::SETTING_EDITION, self::EDITION_COMMUNITY));
        $boundIp = $this->cleanIp((string) $this->settings->get(self::SETTING_BOUND_IP, ''));
        $detectedIp = $this->cleanIp((string) $this->settings->get(self::SETTING_DETECTED_IP, ''));
        $verifiedAt = $this->normalizeDate((string) $this->settings->get(self::SETTING_VERIFIED_AT, ''));
        $expiresAt = $this->normalizeDate((string) $this->settings->get(self::SETTING_EXPIRES_AT, ''));
        $graceEndsAt = $this->normalizeDate((string) $this->settings->get(self::SETTING_GRACE_ENDS_AT, ''));
        $installationUuid = $this->installationUuid();

        return [
            'configured' => $this->keyConfigured(),
            'status' => $status !== '' ? $status : 'missing',
            'message' => $message !== '' ? $message : 'No license key configured.',
            'edition' => $edition,
            'installation_uuid' => $installationUuid,
            'bound_ip' => $boundIp,
            'detected_ip' => $detectedIp,
            'verified_at' => $verifiedAt,
            'expires_at' => $expiresAt,
            'grace_ends_at' => $graceEndsAt,
        ];
    }

    public function hasValidEnterpriseLicense(bool $forceRefresh = false): bool
    {
        return $this->currentLicensedEdition($forceRefresh) === self::EDITION_ENTERPRISE;
    }

    public function currentLicensedEdition(bool $forceRefresh = false): string
    {
        $this->installationUuid();

        if (! $this->keyConfigured()) {
            return self::EDITION_COMMUNITY;
        }

        $state = $forceRefresh ? $this->verifyNow() : $this->state();

        if (($state['status'] ?? '') !== 'valid' || $this->isExpired($state)) {
            if (! $forceRefresh && $this->isStale($state)) {
                $state = $this->verifyNow();
            }
        }

        if (($state['status'] ?? '') !== 'valid') {
            return self::EDITION_COMMUNITY;
        }

        if ($this->isExpired($state)) {
            return self::EDITION_COMMUNITY;
        }

        if ($this->strictIpEnabled()) {
            $boundIp = $this->cleanIp((string) ($state['bound_ip'] ?? ''));
            if ($boundIp !== null) {
                $currentIp = $this->detectPublicIp(false) ?? $this->cleanIp((string) ($state['detected_ip'] ?? ''));
                if ($currentIp !== null && ! hash_equals($boundIp, $currentIp)) {
                    return self::EDITION_COMMUNITY;
                }
            }
        }

        if (! $forceRefresh && $this->isStale($state)) {
            $state = $this->verifyNow();
            if (($state['status'] ?? '') !== 'valid' || $this->isExpired($state)) {
                return self::EDITION_COMMUNITY;
            }
        }

        return $this->normalizeEdition((string) ($state['edition'] ?? self::EDITION_COMMUNITY));
    }

    /**
     * @return array{
     *   configured: bool,
     *   status: string,
     *   message: string,
     *   edition: string,
     *   installation_uuid: string,
     *   bound_ip: ?string,
     *   detected_ip: ?string,
     *   verified_at: ?string,
     *   expires_at: ?string,
     *   grace_ends_at: ?string
     * }
     */
    public function verifyNow(): array
    {
        $key = $this->licenseKey();
        if ($key === '') {
            $this->clearLicense();

            return $this->state();
        }

        $endpoint = $this->verifyUrl();
        if ($endpoint === '') {
            $this->settings->set(self::SETTING_STATUS, 'invalid');
            $this->settings->set(self::SETTING_MESSAGE, 'License verification endpoint is not configured.');
            $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
            $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());

            return $this->state();
        }

        $installationUuid = $this->installationUuid();
        if (! $this->isEndpointAllowed($endpoint)) {
            $this->settings->set(self::SETTING_STATUS, 'invalid');
            $this->settings->set(self::SETTING_MESSAGE, 'License verification endpoint must use HTTPS in production.');
            $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
            $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());

            return $this->state();
        }

        $detectedIp = $this->detectPublicIp(true);
        $timeout = $this->timeoutSeconds();
        $hadSigningSecret = $this->requestSigningSecret() !== '';

        [$response, $exception] = $this->attemptVerifyRequest($endpoint, $installationUuid, $key, $detectedIp, $timeout);

        if ($exception !== null) {
            $this->settings->set(self::SETTING_STATUS, 'invalid');
            $this->settings->set(self::SETTING_MESSAGE, $this->formatVerifyExceptionMessage($exception));
            $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
            $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());
            if ($detectedIp !== null) {
                $this->settings->set(self::SETTING_DETECTED_IP, $detectedIp);
            }

            return $this->state();
        }

        // Bootstrap: when no signing secret exists yet, try to extract it from any
        // response body (even a rejection). The server includes the secret on first
        // contact so the app can store it and immediately retry signed.
        if (! $hadSigningSecret) {
            $bootstrapJson = json_decode((string) $response->body(), true);
            if (is_array($bootstrapJson)) {
                $this->persistEnterpriseRuntimeConfig($bootstrapJson);
                $this->extractAndStoreRequestSigningSecret($bootstrapJson);
            }
            if ($this->requestSigningSecret() !== '') {
                [$retryResponse, $retryException] = $this->attemptVerifyRequest($endpoint, $installationUuid, $key, $detectedIp, $timeout);
                if ($retryException === null) {
                    $response = $retryResponse;
                }
            }
        }

        if (! $response->successful()) {
            $this->settings->set(self::SETTING_STATUS, 'invalid');
            $this->settings->set(self::SETTING_MESSAGE, 'License server rejected verification.');
            $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
            $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());
            if ($detectedIp !== null) {
                $this->settings->set(self::SETTING_DETECTED_IP, $detectedIp);
            }

            return $this->state();
        }

        $body = (string) $response->body();
        if ($this->requiresSignatureVerification() && ! $this->verifyResponseSignature($response, $body)) {
            $this->settings->set(self::SETTING_STATUS, 'invalid');
            $this->settings->set(self::SETTING_MESSAGE, 'License server signature validation failed.');
            $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
            $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());
            if ($detectedIp !== null) {
                $this->settings->set(self::SETTING_DETECTED_IP, $detectedIp);
            }

            return $this->state();
        }

        $json = json_decode($body, true);
        if (! is_array($json)) {
            $this->settings->set(self::SETTING_STATUS, 'invalid');
            $this->settings->set(self::SETTING_MESSAGE, 'License server returned invalid JSON.');
            $this->settings->set(self::SETTING_EDITION, self::EDITION_COMMUNITY);
            $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());
            if ($detectedIp !== null) {
                $this->settings->set(self::SETTING_DETECTED_IP, $detectedIp);
            }

            return $this->state();
        }

        $this->persistEnterpriseRuntimeConfig($json);
        $this->extractAndStoreRequestSigningSecret($json);

        $valid = (bool) ($json['valid'] ?? false);
        $message = trim(strip_tags((string) ($json['message'] ?? '')));
        $responseEdition = trim((string) ($json['edition'] ?? ($json['tier'] ?? ($json['plan'] ?? ''))));
        $licensedEdition = $responseEdition !== ''
            ? $this->normalizeEdition($responseEdition)
            : ($valid ? self::EDITION_ENTERPRISE : self::EDITION_COMMUNITY);
        $serverInstallationUuid = trim((string) ($json['installation_uuid'] ?? ($json['instance_uuid'] ?? ($json['uuid'] ?? ''))));
        $boundIp = $this->cleanIp((string) ($json['bound_ip'] ?? ($json['ip'] ?? '')));
        $expiresAt = $this->normalizeDate((string) ($json['expires_at'] ?? ''));
        $graceEndsAt = $this->normalizeDate((string) ($json['grace_ends_at'] ?? ''));

        if ($serverInstallationUuid !== '' && ! hash_equals(strtolower($installationUuid), strtolower($serverInstallationUuid))) {
            $valid = false;
            if ($message === '') {
                $message = 'License installation UUID mismatch.';
            }
        }

        if ($this->strictIpEnabled() && $boundIp !== null && $detectedIp !== null) {
            if (! hash_equals($boundIp, $detectedIp)) {
                $valid = false;
                if ($message === '') {
                    $message = 'License IP mismatch.';
                }
            }
        }

        $this->settings->set(self::SETTING_STATUS, $valid ? 'valid' : 'invalid');
        $this->settings->set(self::SETTING_MESSAGE, $message !== '' ? $message : ($valid ? 'License validated.' : 'License is invalid.'));
        $this->settings->set(self::SETTING_EDITION, $valid ? $licensedEdition : self::EDITION_COMMUNITY);
        $this->settings->set(self::SETTING_BOUND_IP, $boundIp ?? '');
        $this->settings->set(self::SETTING_DETECTED_IP, $detectedIp ?? '');
        $this->settings->set(self::SETTING_VERIFIED_AT, now()->toIso8601String());
        $this->settings->set(self::SETTING_EXPIRES_AT, $expiresAt ?? '');
        $this->settings->set(self::SETTING_GRACE_ENDS_AT, $graceEndsAt ?? '');

        return $this->state();
    }

    /**
     * @return array{0: \Illuminate\Http\Client\Response|null, 1: \Throwable|null}
     */
    private function attemptVerifyRequest(
        string $endpoint,
        string $installationUuid,
        string $key,
        ?string $detectedIp,
        int $timeout,
    ): array {
        $timestamp = now()->toIso8601String();
        $payload = [
            'license_key' => $key,
            'installation_uuid' => $installationUuid,
            'uuid' => $installationUuid,
            'app_url' => (string) config('app.url'),
            'app_name' => (string) config('app.name'),
            'public_ip' => $detectedIp,
            'timestamp' => $timestamp,
        ];
        $headers = $this->requestSignatureHeaders($installationUuid, $timestamp, $key);

        try {
            $request = Http::timeout($timeout)->acceptJson()->asJson();
            if ($this->allowInsecureLocalTlsForEndpoint($endpoint)) {
                $request = $request->withOptions(['verify' => false]);
            }
            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }

            return [$request->post($endpoint, $payload), null];
        } catch (\Throwable $exception) {
            return [null, $exception];
        }
    }

    private function licenseKey(): string
    {
        $value = $this->settings->getDecrypted(self::SETTING_KEY, '');

        return is_string($value) ? trim($value) : '';
    }

    private function isStale(array $state): bool
    {
        $verifiedAt = $this->parseDate($state['verified_at'] ?? null);
        if (! $verifiedAt) {
            return true;
        }

        $cacheSeconds = $this->cacheSeconds();

        return $verifiedAt->lt(now()->subSeconds($cacheSeconds));
    }

    private function isExpired(array $state): bool
    {
        $expiresAt = $this->parseDate($state['expires_at'] ?? null);
        if (! $expiresAt) {
            return false;
        }

        return $expiresAt->lte(now());
    }

    private function detectPublicIp(bool $allowRemoteLookup): ?string
    {
        $configured = $this->cleanIp($this->configuredPublicIp());
        if ($configured !== null) {
            return $configured;
        }

        $serverAddr = $this->cleanIp((string) ($_SERVER['SERVER_ADDR'] ?? ''));
        if ($serverAddr !== null && ! $this->isPrivateIp($serverAddr)) {
            return $serverAddr;
        }

        if (! $allowRemoteLookup) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds())
                ->get('https://api64.ipify.org');
            if (! $response->successful()) {
                return null;
            }

            return $this->cleanIp(trim((string) $response->body()));
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function cleanIp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private function isPrivateIp(string $ip): bool
    {
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function normalizeDate(?string $value): ?string
    {
        $parsed = $this->parseDate($value);

        return $parsed?->toIso8601String();
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $exception) {
            return null;
        }
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

    /**
     * @return array<string, string>
     */
    private function requestSignatureHeaders(string $installationUuid, string $timestamp, string $licenseKey): array
    {
        $secret = $this->requestSigningSecret();
        if ($secret === '') {
            return [];
        }

        $digest = hash('sha256', implode('|', [
            strtolower(trim($installationUuid)),
            trim($timestamp),
            hash('sha256', trim($licenseKey)),
            trim((string) config('app.url')),
        ]));
        $signature = hash_hmac('sha256', $digest, $secret);

        return [
            'X-GWM-License-Timestamp' => $timestamp,
            'X-GWM-License-Signature' => 'sha256='.$signature,
            'X-GWM-License-Installation' => $installationUuid,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistEnterpriseRuntimeConfig(array $payload): void
    {
        $this->callEnterprisePersistor(\GitManagerEnterprise\Support\LicenseRuntimeConfig::class, $payload);
        $this->callEnterprisePersistor(\GitManagerEnterprise\Support\CommerceRuntimeConfig::class, $payload);
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $payload
     */
    private function callEnterprisePersistor(string $class, array $payload): void
    {
        if (! class_exists($class) || ! method_exists($class, 'persistFromLicensePayload')) {
            return;
        }

        try {
            $class::persistFromLicensePayload($payload);
        } catch (\Throwable $exception) {
            // Runtime config hydration should never block license verification.
        }
    }

    private function requiresSignatureVerification(): bool
    {
        if (! $this->signatureVerificationEnabled()) {
            return false;
        }

        return $this->responseSigningSecret() !== '';
    }

    private function verifyResponseSignature(\Illuminate\Http\Client\Response $response, string $body): bool
    {
        $secret = $this->responseSigningSecret();
        if ($secret === '') {
            return true;
        }

        $header = trim((string) $response->header('X-GWM-License-Signature', ''));
        if ($header === '') {
            return false;
        }

        $signature = str_starts_with($header, 'sha256=')
            ? substr($header, 7)
            : $header;
        $signature = trim((string) $signature);
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, $signature);
    }

    private function normalizeEdition(string $edition): string
    {
        return strtolower(trim($edition)) === self::EDITION_ENTERPRISE
            ? self::EDITION_ENTERPRISE
            : self::EDITION_COMMUNITY;
    }

    private function enterpriseConfig(string $method, mixed $fallback): mixed
    {
        $class = \GitManagerEnterprise\Support\LicenseRuntimeConfig::class;
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return $fallback;
        }

        try {
            return $class::$method();
        } catch (\Throwable $exception) {
            return $fallback;
        }
    }

    private function verifyUrl(): string
    {
        $fallback = $this->envFallbackAllowed()
            ? trim((string) config('gitmanager.license.verify_url', ''))
            : '';
        return trim((string) $this->enterpriseConfig('verifyUrl', $fallback));
    }

    private function timeoutSeconds(): int
    {
        $fallback = $this->envFallbackAllowed()
            ? max(3, (int) config('gitmanager.license.timeout', 10))
            : 10;
        return max(3, (int) $this->enterpriseConfig('timeout', $fallback));
    }

    private function cacheSeconds(): int
    {
        $fallback = $this->envFallbackAllowed()
            ? max(60, (int) config('gitmanager.license.cache_seconds', 900))
            : 900;
        return max(60, (int) $this->enterpriseConfig('cacheSeconds', $fallback));
    }

    private function strictIpEnabled(): bool
    {
        $fallback = $this->envFallbackAllowed()
            ? (bool) config('gitmanager.license.strict_ip', true)
            : true;
        return (bool) $this->enterpriseConfig('strictIp', $fallback);
    }

    private function configuredPublicIp(): string
    {
        $fallback = $this->envFallbackAllowed()
            ? trim((string) config('gitmanager.license.public_ip', ''))
            : '';
        return trim((string) $this->enterpriseConfig('publicIp', $fallback));
    }

    private function signatureVerificationEnabled(): bool
    {
        $fallback = $this->envFallbackAllowed()
            ? (bool) config('gitmanager.license.verify_signature', true)
            : true;
        return (bool) $this->enterpriseConfig('verifySignature', $fallback);
    }

    private function responseSigningSecret(): string
    {
        $fallback = $this->envFallbackAllowed()
            ? trim((string) config('gitmanager.license.response_signing_secret', ''))
            : '';
        return trim((string) $this->enterpriseConfig('responseSigningSecret', $fallback));
    }

    private function requestSigningSecret(): string
    {
        // DB-stored value (auto-populated from TOFU bootstrap) takes priority so the
        // secret persists without any env var or enterprise package dependency.
        $stored = $this->settings->getDecrypted(self::SETTING_REQUEST_SIGNING_SECRET, '');
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        $fallback = $this->envFallbackAllowed()
            ? trim((string) config('gitmanager.license.request_signing_secret', ''))
            : '';
        return trim((string) $this->enterpriseConfig('requestSigningSecret', $fallback));
    }

    /**
     * Pull the request-signing secret out of any server response body and persist it
     * encrypted in the settings table. The server may nest it under runtime_config or
     * send it at the top level under several plausible key names.
     *
     * @param array<string, mixed> $payload
     */
    private function extractAndStoreRequestSigningSecret(array $payload): void
    {
        $candidates = [
            $payload['request_signing_secret'] ?? null,
            $payload['signing_secret'] ?? null,
            $payload['hmac_secret'] ?? null,
            $payload['runtime_config']['request_signing_secret'] ?? null,
            $payload['runtime_config']['signing_secret'] ?? null,
            $payload['runtime_config']['hmac_secret'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                $this->settings->setEncrypted(self::SETTING_REQUEST_SIGNING_SECRET, $value);
                return;
            }
        }
    }

    private function allowInsecureLocalTlsForEndpoint(string $endpoint): bool
    {
        if (! $this->isLocalInstallContext()) {
            return false;
        }

        $scheme = strtolower((string) parse_url(trim($endpoint), PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return false;
        }

        // Both the DB setting (repair button) and the env var take absolute priority
        // within a local context. The enterprise runtime config returns false as its
        // production-safe default and must not override a deliberate local override.
        if ((bool) $this->settings->get(self::SETTING_ALLOW_INSECURE_LOCAL_TLS, false)) {
            return true;
        }

        if ((bool) config('gitmanager.license.allow_insecure_local_tls', false)) {
            return true;
        }

        return false;
    }

    private function isLocalInstallContext(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    private function envFallbackAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function formatVerifyExceptionMessage(\Throwable $exception): string
    {
        $raw = trim($exception->getMessage());
        $lower = strtolower($raw);

        if (str_contains($lower, 'curl error 60')) {
            if (app()->environment(['local', 'testing'])) {
                return 'License verification failed: SSL trust store is missing or outdated on this server (cURL error 60). Install/update CA certificates, or for local testing only set GWM_LICENSE_ALLOW_INSECURE_LOCAL_TLS=true.';
            }

            return 'License verification failed: SSL trust store is missing or outdated on this server (cURL error 60). Install/update CA certificates (for example apt install ca-certificates), then restart PHP-FPM/web server.';
        }

        return 'License verification failed: '.$raw;
    }
}
