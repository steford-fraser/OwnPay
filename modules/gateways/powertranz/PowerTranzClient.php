<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Powertranz;

use OwnPay\Service\System\Logger;

/**
 * Minimal PowerTranz SPI client: HTTP transport, response parsing and currency mapping.
 */
final class PowerTranzClient
{
    public const STAGING_URL    = 'https://staging.ptranz.com';
    public const PRODUCTION_URL = 'https://gateway.ptranz.com';

    /** ISO codes that represent a successful step of the SPI flow (used for log levels only). */
    private const OK_ISO_CODES = ['00', 'SP4', 'SP1', '3D0'];

    private const NUMERIC_CURRENCY = [
        'USD' => '840',
        'TTD' => '780',
        'BBD' => '052',
        'JMD' => '388',
        'XCD' => '951',
        'CAD' => '124',
        'GBP' => '826',
        'EUR' => '978',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $merchantId,
        private readonly string $password,
        private readonly bool $verifySsl = true,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * @param array<string, string> $credentials
     */
    public static function fromCredentials(array $credentials, ?Logger $logger = null): self
    {
        $live = ($credentials['mode'] ?? 'sandbox') === 'live';
        $override = trim($credentials['base_url'] ?? '');
        $baseUrl = $override !== '' ? rtrim($override, '/') : ($live ? self::PRODUCTION_URL : self::STAGING_URL);

        $merchantId = trim($credentials['merchant_id'] ?? '');
        $password = $credentials['password'] ?? '';
        if ($merchantId === '' || $password === '') {
            throw new \RuntimeException('PowerTranz is not configured (missing merchant ID or password).');
        }

        return new self($baseUrl, $merchantId, $password, ($credentials['verify_ssl'] ?? '1') !== '0', $logger);
    }

    /**
     * Starts a 3-D Secure SPI sale. Returns the raw payload when a challenge (SP4) or a frictionless approval (00) came back.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function sale(array $body): array
    {
        $payload = $this->request('/api/spi/sale', $body, true, 'sale');
        $iso = self::string($payload['IsoResponseCode'] ?? null) ?? '';

        $challenge = $iso === 'SP4'
            && self::string($payload['RedirectData'] ?? null) !== null
            && self::string($payload['SpiToken'] ?? null) !== null;
        $approved = self::bool($payload['Approved'] ?? false) && $iso === '00';

        if ($challenge || $approved) {
            return $payload;
        }

        throw new \RuntimeException(
            'PowerTranz sale rejected: ' . (self::string($payload['ResponseMessage'] ?? null) ?? 'no response message') . " ({$iso})"
        );
    }

    /**
     * Completes an authenticated SPI transaction. The body is the bare SpiToken JSON string, unauthenticated.
     *
     * @return array<string, mixed>
     */
    public function complete(string $spiToken): array
    {
        return $this->request('/api/spi/payment', $spiToken, false, 'payment');
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(string $transactionIdentifier, string $amount, string $currency): array
    {
        return $this->request('/api/refund', [
            'TransactionIdentifier' => $transactionIdentifier,
            'TotalAmount'           => round((float) $amount, 2),
            'CurrencyCode'          => self::numericCurrency($currency),
        ], true, 'refund');
    }

    /**
     * True when the merchant-response payload shows successful (or not required) 3-D Secure authentication.
     *
     * @param array<string, mixed> $payload
     */
    public static function authenticationSucceeded(array $payload): bool
    {
        $iso = self::string($payload['IsoResponseCode'] ?? null);
        if ($iso === 'SP1') {
            return true;
        }
        if ($iso !== '3D0') {
            return false;
        }

        $risk = $payload['RiskManagement'] ?? [];
        $threeDs = is_array($risk) ? ($risk['ThreeDSecure'] ?? []) : [];
        $status = is_array($threeDs) ? self::string($threeDs['AuthenticationStatus'] ?? null) : null;

        return in_array($status, ['Y', 'A', null], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function approved(array $payload): bool
    {
        return self::bool($payload['Approved'] ?? false) && self::string($payload['IsoResponseCode'] ?? null) === '00';
    }

    public static function numericCurrency(string $currency): string
    {
        $currency = strtoupper($currency);
        if (!isset(self::NUMERIC_CURRENCY[$currency])) {
            throw new \RuntimeException("PowerTranz does not support currency {$currency}.");
        }
        return self::NUMERIC_CURRENCY[$currency];
    }

    public static function string(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    public static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }
        return (bool) $value;
    }

    /**
     * Normalises a merchant-response POST: a "Response" form field holding JSON, a JSON body, or loose fields.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function parseMerchantResponse(array $post, ?string $rawBody): array
    {
        foreach (['Response', 'response', 'payload', 'data'] as $key) {
            $value = $post[$key] ?? null;
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $raw = trim((string) $rawBody);
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $post;
    }

    /**
     * @param array<string, mixed>|string $body
     * @return array<string, mixed>
     */
    private function request(string $path, array|string $body, bool $authenticate, string $operation): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($authenticate) {
            $headers[] = 'PowerTranz-PowerTranzId: ' . $this->merchantId;
            $headers[] = 'PowerTranz-PowerTranzPassword: ' . $this->password;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => (string) json_encode($body),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log('error', $operation, $url, $httpCode, null, $curlError);
            throw new \RuntimeException('Could not connect to PowerTranz.');
        }

        $decoded = json_decode((string) $raw, true);
        $payload = is_array($decoded) ? $decoded : [];
        $iso = self::string($payload['IsoResponseCode'] ?? null);
        $message = self::string($payload['ResponseMessage'] ?? null);

        $ok = $httpCode >= 200 && $httpCode < 300 && ($iso === null || in_array($iso, self::OK_ISO_CODES, true));
        $this->log($ok ? 'info' : 'warning', $operation, $url, $httpCode, $iso, $message);

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            $detail = $message ?? (is_array($decoded) ? 'HTTP ' . $httpCode : substr((string) $raw, 0, 240));
            throw new \RuntimeException('PowerTranz request failed: ' . $detail);
        }

        return $payload;
    }

    private function log(string $level, string $operation, string $url, int $httpCode, ?string $iso, ?string $message): void
    {
        $this->logger?->{$level}("PowerTranz {$operation}", [
            'url'       => $url,
            'http'      => $httpCode,
            'iso'       => $iso,
            'message'   => $message,
        ]);
    }
}
