<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Govpay;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\Demo\DemoCard;
use OwnPay\Gateway\Demo\HostedDemoGateway;
use OwnPay\Gateway\Demo\PrepaidCodeStore;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Service\Customer\CheckoutIdentityService;

require_once __DIR__ . '/GovPayWallet.php';

/**
 * GovPay - simulated hosted checkout for the Government of Trinidad and Tobago's payment
 * platform (operated by NPICTT). No real money moves.
 *
 * Flow: initiate() opens a GovPay session and sends the payer to page(). The payer signs in to
 * GovPay (email or mobile + code) and pays with one of:
 *  - card: test-card outcomes with an optional 3-D Secure challenge, completed by a signed
 *    result posted back to merchantResponse();
 *  - GovPay Wallet: deducted from the payer's simulated wallet balance in wallet();
 *  - voucher, SMS or QR codes: split tender across up to five prepaid codes, deducted in
 *    codeConfirm().
 * Every path completes through the core callback pipeline (verify()).
 *
 * @phpstan-import-type Ctx from HostedDemoGateway
 */
final class GovPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;
    use HostedDemoGateway;

    private const SLUG = 'govpay';
    private const PATH = '/checkout/govpay/';
    private const META = 'govpay';
    private const VIEWS = 'gateway_govpay';
    private const DEFAULT_LABEL = 'GovPay';
    private const SESSION_TTL = 1800;
    private const DEFAULT_OPENING_BALANCE = '1000.00';

    public const METHODS = [
        'card'   => 'Card',
        'wallet' => 'GovPay Wallet',
        'code'   => 'Voucher / SMS / QR',
    ];

    /** Gateway name shown on the payer's receipt / status page. */
    public function statusLabel(): string
    {
        return 'GovPay';
    }

    public static function metadata(): array
    {
        return [
            'name' => 'GovPay', 'slug' => self::SLUG, 'version' => '1.0.0',
            'description' => 'Government of Trinidad and Tobago payments: card, GovPay Wallet, voucher, SMS and QR codes',
            'author' => 'NPICTT', 'type' => 'gateway',
        ];
    }

    public function register(EventManager $events, Container $container): void
    {
        $this->container = $container;
        $this->registerLabelFilter($events);
    }

    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    public function fields(): array
    {
        $yesNo = ['yes' => 'Yes', 'no' => 'No'];
        return [
            ['name' => 'merchant_code', 'label' => 'GovPay Agency Code', 'type' => 'text'],
            ['name' => 'api_key', 'label' => 'GovPay API Key', 'type' => 'password'],
            ['name' => 'checkout_label', 'label' => 'Checkout Label', 'type' => 'text', 'default' => self::DEFAULT_LABEL],
            ['name' => 'method_card', 'label' => 'Accept cards', 'type' => 'select', 'options' => $yesNo, 'default' => 'yes'],
            ['name' => 'method_wallet', 'label' => 'Accept GovPay Wallet', 'type' => 'select', 'options' => $yesNo, 'default' => 'yes'],
            ['name' => 'method_code', 'label' => 'Accept voucher, SMS and QR codes', 'type' => 'select', 'options' => $yesNo, 'default' => 'yes'],
            ['name' => 'wallet_opening_balance', 'label' => 'Demo wallet opening balance', 'type' => 'text', 'default' => self::DEFAULT_OPENING_BALANCE],
            ['name' => 'three_ds', 'label' => '3-D Secure', 'type' => 'select', 'options' => DemoCard::THREE_DS_OPTIONS, 'default' => 'test_cards'],
            ['name' => 'processing_delay', 'label' => 'Processing delay (seconds)', 'type' => 'text', 'default' => '2'],
        ];
    }

    public function supports(string $feature): bool
    {
        return $feature === 'refund';
    }

    public function initiate(array $params, array $credentials): array
    {
        return $this->openSession($params, $credentials, 'gp_');
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $session = $this->getString($callbackData['session'] ?? '');
        return match ($this->getString($callbackData['method'] ?? '')) {
            'card'   => $this->verifyCard($callbackData, self::signingKey($credentials)),
            'wallet' => self::verifyTaken($trxId, $session, $this->wallets()->debitedTotal($trxId)),
            'code'   => self::verifyTaken($trxId, $session, $this->codeStore()->redeemedTotal($trxId)),
            default  => ['success' => false, 'status' => 'failed', 'trx_id' => $trxId],
        };
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        $txn = $this->transactionBySession($gatewayTrxId);
        if ($txn === null) {
            return ['success' => false, 'error' => 'GovPay transaction not found.'];
        }
        $value = PrepaidCodeStore::money($amount);
        $left = match ($txn['meta']['method'] ?? '') {
            'wallet' => $this->wallets()->refund($txn['trx_id'], $value),
            'code'   => $this->codeStore()->refund($txn['trx_id'], $value),
            default  => '0.00',
        };
        if (bccomp($left, '0', 2) > 0) {
            return ['success' => false, 'error' => "Only part of the refund could be credited ({$left} left)."];
        }
        return ['success' => true, 'refund_id' => 'gprf_' . bin2hex(random_bytes(8))];
    }

    /**
     * GET /checkout/govpay/{trx}/page[?method=card|wallet|code]
     */
    public function page(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($ctx['meta']['stage'] === '3ds') {
            return Response::redirect($this->path($ctx['trx'], '3ds'), 303);
        }
        if ($ctx['meta']['stage'] !== 'pay') {
            return Response::redirect($this->statusUrl($ctx['trx']), 303);
        }
        return $this->renderPage($request, $ctx, $this->activeMethod($ctx, $this->getString($request->query('method', ''))));
    }

    /** POST /checkout/govpay/{trx}/identify */
    public function identify(Request $request): Response
    {
        return $this->identityStep($request, 'identify');
    }

    /** POST /checkout/govpay/{trx}/signup */
    public function signup(Request $request): Response
    {
        return $this->identityStep($request, 'signup');
    }

    /** POST /checkout/govpay/{trx}/verify */
    public function verifyCode(Request $request): Response
    {
        return $this->identityStep($request, 'verify');
    }

    /** POST /checkout/govpay/{trx}/identify/reset */
    public function identifyReset(Request $request): Response
    {
        return $this->identityStep($request, 'reset');
    }

    /**
     * POST /checkout/govpay/{trx}/card
     */
    public function card(Request $request): Response
    {
        $ctx = $this->payContext($request, 'card');
        return $ctx instanceof Response ? $ctx : $this->cardSubmit($request, $ctx);
    }

    /**
     * GET|POST /checkout/govpay/{trx}/3ds
     */
    public function threeDs(Request $request): Response
    {
        return $this->threeDsStep($request);
    }

    /**
     * POST /checkout/govpay/{trx}/return
     */
    public function merchantResponse(Request $request): Response
    {
        return $this->cardReturn($request);
    }

    /**
     * POST /checkout/govpay/{trx}/wallet - pays the full amount from the payer's wallet.
     */
    public function wallet(Request $request): Response
    {
        $ctx = $this->payContext($request, 'wallet');
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $wallet = $this->walletFor($ctx);
        $amount = PrepaidCodeStore::money($ctx['meta']['amount'] ?? null);
        if ($wallet === null || !GovPayWallet::canPay($wallet['balance'], $amount)) {
            return $this->renderPage($request, $ctx, 'wallet', ['message' => 'Your GovPay Wallet balance is too low for this payment.', 'is_error' => true], 422);
        }

        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $ctx['meta']['method'] = 'wallet';
        $this->saveMeta($ctx['trx'], $ctx['meta']);
        return $this->complete($request, $ctx, 'wallet', function () use ($wallet, $ctx, $mid, $amount): void {
            $this->wallets()->debit($wallet['id'], $ctx['trx'], $mid, $amount);
        }, static fn(array $meta): array => $meta);
    }

    /** POST /checkout/govpay/{trx}/code/apply */
    public function codeApply(Request $request): Response
    {
        $ctx = $this->payContext($request, 'code');
        return $ctx instanceof Response ? $ctx : $this->applyCode($request, $ctx, 'code');
    }

    /** POST /checkout/govpay/{trx}/code/remove */
    public function codeRemove(Request $request): Response
    {
        $ctx = $this->payContext($request, 'code');
        return $ctx instanceof Response ? $ctx : $this->removeCode($request, $ctx, 'code');
    }

    /** POST /checkout/govpay/{trx}/code/confirm */
    public function codeConfirm(Request $request): Response
    {
        $ctx = $this->payContext($request, 'code');
        return $ctx instanceof Response ? $ctx : $this->confirmCode($request, $ctx, 'code');
    }

    /**
     * GET /checkout/govpay/{trx}/cancel - nothing was taken yet, so just return to checkout.
     */
    public function cancel(Request $request): Response
    {
        return Response::redirect('/checkout/' . rawurlencode($request->param('trx')), 303);
    }

    /**
     * Context for a payment POST: the session must be open, the payer signed in to GovPay,
     * the method enabled and the form token valid.
     *
     * @return Ctx|Response
     */
    private function payContext(Request $request, string $method): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($ctx['meta']['stage'] !== 'pay'
            || !self::identityVerified($ctx['meta'])
            || !in_array($method, self::enabledMethods($ctx['creds']), true)
            || !$this->validToken($request, $ctx)
        ) {
            return Response::redirect($this->path($ctx['trx'], 'page') . '?method=' . rawurlencode($method), 303);
        }
        return $ctx;
    }

    /**
     * @param Ctx $ctx
     * @param array<string, mixed> $vars
     */
    protected function renderPage(Request $request, array $ctx, string $method, array $vars = [], int $status = 200): Response
    {
        if (!self::identityVerified($ctx['meta'])) {
            return $this->render($request, 'page', $ctx, $vars + ['identify' => $this->identifyVars($ctx), 'method' => $method], $status);
        }

        $vars += ['method' => $method, 'name' => ''];
        if ($method === 'wallet') {
            $wallet = $this->walletFor($ctx);
            $amount = PrepaidCodeStore::money($ctx['meta']['amount'] ?? null);
            $vars += ['wallet' => $wallet, 'wallet_ok' => $wallet !== null && GovPayWallet::canPay($wallet['balance'], $amount)];
        }
        if ($method === 'code') {
            $applied = self::appliedOf($ctx['meta']);
            $vars += [
                'applied'   => $applied,
                'remaining' => PrepaidCodeStore::remaining(PrepaidCodeStore::money($ctx['meta']['amount'] ?? null), $applied),
                'can_add'   => count($applied) < self::MAX_CODES,
            ];
        }
        $contact = self::verifiedContact($ctx['meta']);
        return $this->render($request, 'page', $ctx, $vars + [
            'methods'     => array_intersect_key(self::METHODS, array_flip(self::enabledMethods($ctx['creds']))),
            'signed_in'   => $contact !== null ? CheckoutIdentityService::maskContact($contact) : '',
        ], $status);
    }

    /**
     * The signed-in payer's wallet in the payment currency, opened on first use.
     *
     * @param Ctx $ctx
     * @return array{id: int, holder: string, currency: string, balance: numeric-string}|null
     */
    private function walletFor(array $ctx): ?array
    {
        $contact = self::verifiedContact($ctx['meta']);
        if ($contact === null) {
            return null;
        }
        $opening = $ctx['creds']['wallet_opening_balance'] ?? '';
        $opening = preg_match('/^\d{1,10}(\.\d{1,2})?$/', $opening) ? PrepaidCodeStore::money($opening) : self::DEFAULT_OPENING_BALANCE;
        return $this->wallets()->open(
            GovPayWallet::keyFor($contact),
            CheckoutIdentityService::maskContact($contact),
            $this->getString($ctx['meta']['currency'] ?? 'TTD'),
            $opening
        );
    }

    protected function brandVars(): array
    {
        return [
            'brand' => [
                'logo'    => $this->assetDataUri('logo.png'),
                'arms'    => $this->assetDataUri('coat-of-arms.png'),
                'support' => '800-GOVPAY (800-468-7291)',
            ],
        ];
    }

    protected function codeStore(): PrepaidCodeStore
    {
        return new PrepaidCodeStore(Database::getInstance(), 'op_plugin_govpay');
    }

    private function wallets(): GovPayWallet
    {
        return new GovPayWallet(Database::getInstance());
    }
}
