<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Powertranz;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Payment\GatewayApiService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\System\Logger;

/**
 * PowerTranz (First Atlantic Commerce) card payments via the SPI 3-D Secure hosted payment page.
 *
 * Flow: initiate() calls /api/spi/sale and stores the returned RedirectData + SpiToken on the
 * transaction; the customer is sent to start(), which renders the hosted page; PowerTranz posts
 * the 3DS result back to merchantResponse(), which completes the sale through the core
 * callback pipeline (verify() -> /api/spi/payment) and redirects to the checkout status page.
 */
final class PowerTranzGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SLUG = 'powertranz';
    private const DEFAULT_LABEL = 'Credit/Debit Card';

    private ?Container $container = null;

    /** @var array<int, string> Checkout label per merchant, memoised for the request. */
    private array $labels = [];

    /** @var array<string, mixed>|null Result of the last verify() call, read back by merchantResponse(). */
    private ?array $lastVerification = null;

    public static function metadata(): array
    {
        return [
            'name' => 'PowerTranz', 'slug' => self::SLUG, 'version' => '1.0.0',
            'description' => 'PowerTranz card payments (3-D Secure hosted payment page)',
            'author' => 'NPICTT', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return self::SLUG; }
    public function name(): string { return 'PowerTranz'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'PowerTranz card payments (3-D Secure hosted payment page)'; }

    public function register(EventManager $events, Container $container): void
    {
        $this->container = $container;

        $events->addFilter('checkout.render', function (mixed $data) use ($events): mixed {
            if (!is_array($data) || !is_array($data['gateways'] ?? null)) {
                return $data;
            }
            $mid = $this->getInt($data['txn']['merchant_id'] ?? 0);
            // Filter listeners run inside this plugin's DB sandbox, which blocks reads of core op_* tables.
            $events->pushOwner('core');
            try {
                $label = $this->checkoutLabel($mid);
            } finally {
                $events->popOwner();
            }
            foreach ($data['gateways'] as $cat => $list) {
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $i => $gw) {
                    if (is_array($gw) && ($gw['slug'] ?? null) === self::SLUG) {
                        $data['gateways'][$cat][$i]['name'] = $label;
                    }
                }
            }
            return $data;
        });
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
    }

    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox (staging.ptranz.com)', 'live' => 'live (gateway.ptranz.com)'], 'required' => true],
            ['name' => 'merchant_id', 'label' => 'PowerTranz ID', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'PowerTranz Password', 'type' => 'password', 'required' => true],
            ['name' => 'page_set', 'label' => 'Hosted Page Set', 'type' => 'text', 'required' => true],
            ['name' => 'page_name', 'label' => 'Hosted Page Name', 'type' => 'text', 'required' => true],
            ['name' => 'checkout_label', 'label' => 'Checkout Label', 'type' => 'text', 'default' => self::DEFAULT_LABEL, 'help' => 'Name shown to payers on the checkout and receipt pages.'],
        ];
    }

    /**
     * Payer-facing name of this gateway for a brand (the "Checkout Label" setting).
     */
    public function checkoutLabel(int $merchantId): string
    {
        if (isset($this->labels[$merchantId])) {
            return $this->labels[$merchantId];
        }

        $label = '';
        try {
            $configs = $this->container?->get(GatewayConfigRepository::class);
            $encryptor = $this->container?->get(FieldEncryptor::class);
            if ($merchantId > 0 && $configs instanceof GatewayConfigRepository && $encryptor instanceof FieldEncryptor) {
                $enc = $configs->forTenant($merchantId)->findCredentialsBySlug(self::SLUG);
                $creds = $enc !== null && $enc !== '' ? json_decode($encryptor->decrypt($enc), true) : null;
                $label = is_array($creds) ? trim($this->getString($creds['checkout_label'] ?? '')) : '';
            }
        } catch (\Throwable $e) {
            $this->logger()?->warning('PowerTranz checkout label lookup failed: ' . $e->getMessage());
        }

        return $this->labels[$merchantId] = $label !== '' ? $label : self::DEFAULT_LABEL;
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, ['refund'], true);
    }

    public function supportedCurrencies(): array
    {
        return ['TTD', 'USD', 'BBD', 'JMD', 'XCD'];
    }

    public function initiate(array $params, array $credentials): array
    {
        $trxId = $this->getString($params['trx_id'] ?? '');
        $returnBase = $this->baseUrlOf($this->getString($params['redirect_url'] ?? ''));
        if ($trxId === '' || $returnBase === '') {
            throw new \RuntimeException('PowerTranz initiation is missing the transaction reference.');
        }

        $pageSet = trim($credentials['page_set'] ?? '');
        $pageName = trim($credentials['page_name'] ?? '');
        if ($pageSet === '' || $pageName === '') {
            throw new \RuntimeException('PowerTranz hosted page set/name is not configured.');
        }

        $tid = $this->uuid();
        $payload = $this->client($credentials)->sale([
            'TransactionIdentifier' => $tid,
            'TotalAmount'           => round((float) $this->toDecimalString($params['amount'] ?? '0'), 2),
            'CurrencyCode'          => PowerTranzClient::numericCurrency($this->getString($params['currency'] ?? '')),
            'ThreeDSecure'          => true,
            'OrderIdentifier'       => $trxId,
            'AddressMatch'          => false,
            'Tokenize'              => false,
            'ExtendedData'          => [
                'ThreeDSecure'        => ['ChallengeWindowSize' => 5, 'ChallengeIndicator' => '01'],
                'MerchantResponseUrl' => $returnBase . '/checkout/ptz/' . rawurlencode($trxId) . '/return',
                'HostedPage'          => ['PageSet' => $pageSet, 'PageName' => $pageName],
            ],
        ]);

        $redirectData = PowerTranzClient::string($payload['RedirectData'] ?? null);
        $spiToken = PowerTranzClient::string($payload['SpiToken'] ?? null);
        if ($redirectData === null || $spiToken === null) {
            throw new \RuntimeException('PowerTranz did not return a hosted payment page.');
        }

        Database::getInstance()->update(
            "UPDATE op_transactions
             SET gateway_trx_id = :tid,
                 metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.powertranz', CAST(:pt AS JSON))
             WHERE trx_id = :trx",
            [
                'tid' => $tid,
                'trx' => $trxId,
                'pt'  => (string) json_encode([
                    'tid'           => $tid,
                    'spi_token'     => $spiToken,
                    'redirect_data' => $redirectData,
                ]),
            ]
        );

        return [
            'redirect_url' => $returnBase . '/checkout/ptz/' . rawurlencode($trxId) . '/start',
            'session_id'   => $tid,
        ];
    }

    /**
     * Expects ['trx_id', 'payload' (merchant-response JSON), 'spi_token' (stored at initiate)].
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $payload = is_array($callbackData['payload'] ?? null) ? $callbackData['payload'] : [];
        $fail = function (string $message, ?string $iso = null) use ($trxId): array {
            return $this->lastVerification = [
                'success' => false, 'status' => 'failed', 'trx_id' => $trxId, 'message' => $message, 'iso' => $iso,
            ];
        };

        if (!PowerTranzClient::authenticationSucceeded($payload)) {
            return $fail(
                PowerTranzClient::string($payload['ResponseMessage'] ?? null) ?? 'Card authentication failed.',
                PowerTranzClient::string($payload['IsoResponseCode'] ?? null) ?? 'auth'
            );
        }

        $spiToken = $this->getString($callbackData['spi_token'] ?? '');
        if ($spiToken === '') {
            $spiToken = PowerTranzClient::string($payload['SpiToken'] ?? null) ?? '';
        }
        if ($spiToken === '') {
            return $fail('Payment authentication expired. Please try again.');
        }

        try {
            $result = $this->client($credentials)->complete($spiToken);
        } catch (\RuntimeException $e) {
            return $fail($e->getMessage());
        }

        $order = PowerTranzClient::string($result['OrderIdentifier'] ?? null);
        if ($order !== null && $trxId !== '' && $order !== $trxId) {
            return $fail('PowerTranz order reference mismatch.');
        }
        if (!PowerTranzClient::approved($result)) {
            return $fail(
                PowerTranzClient::string($result['ResponseMessage'] ?? null) ?? 'Payment was declined.',
                PowerTranzClient::string($result['IsoResponseCode'] ?? null)
            );
        }

        return $this->lastVerification = [
            'success'        => true,
            'gateway_trx_id' => PowerTranzClient::string($result['TransactionIdentifier'] ?? null) ?? '',
            'amount'         => $this->toDecimalString($result['TotalAmount'] ?? '0'),
            'status'         => 'completed',
            'trx_id'         => $trxId,
            'message'        => PowerTranzClient::string($result['ResponseMessage'] ?? null) ?? 'Approved',
        ];
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        $txn = Database::getInstance()->fetchOne(
            "SELECT currency FROM op_transactions WHERE gateway_trx_id = :g LIMIT 1",
            ['g' => $gatewayTrxId]
        );
        $currency = $this->getString($txn['currency'] ?? '');
        if ($currency === '') {
            return ['success' => false, 'error' => 'PowerTranz transaction not found.'];
        }

        try {
            $result = $this->client($credentials)->refund($gatewayTrxId, $amount, $currency);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (!PowerTranzClient::approved($result)) {
            return ['success' => false, 'error' => PowerTranzClient::string($result['ResponseMessage'] ?? null) ?? 'Refund declined.'];
        }

        return [
            'success'   => true,
            'refund_id' => PowerTranzClient::string($result['TransactionIdentifier'] ?? null) ?? $gatewayTrxId,
        ];
    }

    /**
     * GET /checkout/ptz/{trx}/start - renders the PowerTranz hosted payment page returned by the sale call.
     */
    public function start(Request $request): Response
    {
        $trxId = $request->param('trx');
        $txn = $this->findTransaction($trxId);
        if ($txn === null) {
            return Response::html('Payment not found.', 404);
        }

        $redirectData = $this->getString($this->powertranzMeta($txn)['redirect_data'] ?? '');
        if (!in_array($txn['status'] ?? '', ['processing', 'callback_processing'], true) || $redirectData === '') {
            return Response::redirect('/checkout/' . rawurlencode($trxId) . '/status', 303);
        }

        $nonce = $this->getString($request->getAttribute('csp_nonce', ''));
        if ($nonce !== '') {
            $redirectData = (string) preg_replace(
                '/<script\b(?![^>]*\bnonce=)/i',
                '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"',
                $redirectData
            );
        }

        return Response::html($redirectData)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * POST /checkout/ptz/{trx}/return - PowerTranz MerchantResponseUrl (browser form post after 3DS).
     */
    public function merchantResponse(Request $request): Response
    {
        $trxId = $request->param('trx');
        $statusUrl = '/checkout/' . rawurlencode($trxId) . '/status';
        $txn = $this->findTransaction($trxId);
        if ($txn === null) {
            return Response::html('Payment not found.', 404);
        }

        $post = $request->post();
        $payload = PowerTranzClient::parseMerchantResponse(is_array($post) ? $post : [], $request->rawBody());
        $meta = $this->powertranzMeta($txn);
        $storedTid = $this->getString($meta['tid'] ?? '');

        $postedOrder = PowerTranzClient::string($payload['OrderIdentifier'] ?? null);
        $postedTid = PowerTranzClient::string($payload['TransactionIdentifier'] ?? null);
        if (($postedOrder !== null && $postedOrder !== $trxId) || ($postedTid !== null && $postedTid !== $storedTid)) {
            $this->logger()?->warning('PowerTranz merchant response does not match transaction', ['trx_id' => $trxId]);
            return Response::redirect($statusUrl, 303);
        }

        $txnId = $this->getInt($txn['id'] ?? 0);
        $mid = $this->getInt($txn['merchant_id'] ?? 0);
        $db = Database::getInstance();

        $claimed = $db->update(
            "UPDATE op_transactions SET status = 'callback_processing'
             WHERE id = :id AND merchant_id = :mid AND status = 'processing' AND gateway_slug = :gw",
            ['id' => $txnId, 'mid' => $mid, 'gw' => self::SLUG]
        );
        if ($claimed === 0) {
            return Response::redirect($statusUrl, 303);
        }

        $this->lastVerification = null;
        $success = false;
        try {
            $svc = $this->container?->get(GatewayApiService::class);
            if ($svc instanceof GatewayApiService) {
                $result = $svc->handleCallback($mid, self::SLUG, [
                    'trx_id'    => $trxId,
                    'payload'   => $payload,
                    'spi_token' => $this->getString($meta['spi_token'] ?? ''),
                ]);
                $success = (bool) $result['success'];
                if (!$success) {
                    $this->logger()?->warning('PowerTranz payment not completed', [
                        'trx_id' => $trxId,
                        'error'  => $result['error'] ?? null,
                        'reason' => $this->lastVerification['message'] ?? null,
                        'iso'    => $this->lastVerification['iso'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger()?->error('PowerTranz merchant response failed: ' . $e->getMessage(), ['trx_id' => $trxId]);
        }

        if (!$success) {
            $reason = $this->getString($this->lastVerification['message'] ?? '') ?: 'Payment was not completed.';
            $txnService = $this->container?->get(TransactionService::class);
            if ($txnService instanceof TransactionService) {
                $txnService->fail($txnId, $mid, $reason);
            } else {
                $db->update(
                    "UPDATE op_transactions SET status = 'failed' WHERE id = :id AND status = 'callback_processing'",
                    ['id' => $txnId]
                );
            }
        }

        return Response::redirect($statusUrl, 303);
    }

    /**
     * @param array<string, string> $credentials
     */
    private function client(array $credentials): PowerTranzClient
    {
        return PowerTranzClient::fromCredentials($credentials, $this->logger());
    }

    private function logger(): ?Logger
    {
        $logger = $this->container?->has(Logger::class) ? $this->container->get(Logger::class) : null;
        return $logger instanceof Logger ? $logger : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTransaction(string $trxId): ?array
    {
        if ($trxId === '') {
            return null;
        }
        return Database::getInstance()->fetchOne(
            "SELECT id, merchant_id, trx_id, status, gateway_slug, metadata FROM op_transactions WHERE trx_id = :t LIMIT 1",
            ['t' => $trxId]
        );
    }

    /**
     * @param array<string, mixed> $txn
     * @return array<string, mixed>
     */
    private function powertranzMeta(array $txn): array
    {
        $meta = is_string($txn['metadata'] ?? null) ? json_decode($txn['metadata'], true) : null;
        return is_array($meta) && is_array($meta['powertranz'] ?? null) ? $meta['powertranz'] : [];
    }

    private function baseUrlOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
