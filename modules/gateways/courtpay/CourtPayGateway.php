<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Courtpay;

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

/**
 * CourtPay - simulated hosted checkout for the Judiciary of Trinidad and Tobago's payment
 * portal. No real money moves.
 *
 * Flow: initiate() opens a CourtPay session and sends the payer to page(), which offers:
 *  - Fines and Fees (card): no registration needed, as on the real portal. The payer may enter
 *    the court, ticket or notice number; test-card outcomes with an optional 3-D Secure
 *    challenge, completed by a signed result posted back to merchantResponse();
 *  - CourtPay Top-up Voucher: the payer signs in to their CourtPay account, then applies up
 *    to five voucher numbers (split tender), deducted in voucherConfirm().
 * Every path completes through the core callback pipeline (verify()).
 *
 * @phpstan-import-type Ctx from HostedDemoGateway
 */
final class CourtPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;
    use HostedDemoGateway;

    private const SLUG = 'courtpay';
    private const PATH = '/checkout/courtpay/';
    private const META = 'courtpay';
    private const VIEWS = 'gateway_courtpay';
    private const DEFAULT_LABEL = 'CourtPay';
    private const SESSION_TTL = 1800;

    public const METHODS = [
        'card'    => 'Fines & Fees (Card)',
        'voucher' => 'Top-up Voucher',
    ];

    /** Gateway name shown on the payer's receipt / status page. */
    public function statusLabel(): string
    {
        return 'CourtPay';
    }

    public static function metadata(): array
    {
        return [
            'name' => 'CourtPay', 'slug' => self::SLUG, 'version' => '1.0.0',
            'description' => 'Judiciary of Trinidad and Tobago payments: court fines and fees by card, CourtPay Top-up Vouchers',
            'author' => 'Judiciary of Trinidad and Tobago', 'type' => 'gateway',
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
            ['name' => 'merchant_code', 'label' => 'CourtPay Court Location Code', 'type' => 'text'],
            ['name' => 'api_key', 'label' => 'CourtPay API Key', 'type' => 'password'],
            ['name' => 'checkout_label', 'label' => 'Checkout Label', 'type' => 'text', 'default' => self::DEFAULT_LABEL],
            ['name' => 'method_card', 'label' => 'Accept fines and fees by card', 'type' => 'select', 'options' => $yesNo, 'default' => 'yes'],
            ['name' => 'method_voucher', 'label' => 'Accept CourtPay Top-up Vouchers', 'type' => 'select', 'options' => $yesNo, 'default' => 'yes'],
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
        return $this->openSession($params, $credentials, 'cp_', ['reference' => '']);
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $session = $this->getString($callbackData['session'] ?? '');
        return match ($this->getString($callbackData['method'] ?? '')) {
            'card'    => $this->verifyCard($callbackData, self::signingKey($credentials)),
            'voucher' => self::verifyTaken($trxId, $session, $this->codeStore()->redeemedTotal($trxId)),
            default   => ['success' => false, 'status' => 'failed', 'trx_id' => $trxId],
        };
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        $txn = $this->transactionBySession($gatewayTrxId);
        if ($txn === null) {
            return ['success' => false, 'error' => 'CourtPay transaction not found.'];
        }
        $left = ($txn['meta']['method'] ?? '') === 'voucher'
            ? $this->codeStore()->refund($txn['trx_id'], PrepaidCodeStore::money($amount))
            : '0.00';
        if (bccomp($left, '0', 2) > 0) {
            return ['success' => false, 'error' => "Only part of the refund could be credited to vouchers ({$left} left)."];
        }
        return ['success' => true, 'refund_id' => 'cprf_' . bin2hex(random_bytes(8))];
    }

    /**
     * GET /checkout/courtpay/{trx}/page[?method=card|voucher]
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

    /** POST /checkout/courtpay/{trx}/identify */
    public function identify(Request $request): Response
    {
        return $this->identityStep($request, 'identify');
    }

    /** POST /checkout/courtpay/{trx}/signup */
    public function signup(Request $request): Response
    {
        return $this->identityStep($request, 'signup');
    }

    /** POST /checkout/courtpay/{trx}/verify */
    public function verifyCode(Request $request): Response
    {
        return $this->identityStep($request, 'verify');
    }

    /** POST /checkout/courtpay/{trx}/identify/reset */
    public function identifyReset(Request $request): Response
    {
        return $this->identityStep($request, 'reset');
    }

    /**
     * POST /checkout/courtpay/{trx}/card - court fines and fees by card (no account needed).
     */
    public function card(Request $request): Response
    {
        $ctx = $this->payContext($request, 'card');
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $reference = self::cleanReference($this->getString($request->post('reference', '')));
        return $this->cardSubmit($request, $ctx, ['reference' => $reference]);
    }

    /**
     * GET|POST /checkout/courtpay/{trx}/3ds
     */
    public function threeDs(Request $request): Response
    {
        return $this->threeDsStep($request);
    }

    /**
     * POST /checkout/courtpay/{trx}/return
     */
    public function merchantResponse(Request $request): Response
    {
        return $this->cardReturn($request);
    }

    /** POST /checkout/courtpay/{trx}/voucher/apply */
    public function voucherApply(Request $request): Response
    {
        $ctx = $this->payContext($request, 'voucher');
        return $ctx instanceof Response ? $ctx : $this->applyCode($request, $ctx, 'voucher');
    }

    /** POST /checkout/courtpay/{trx}/voucher/remove */
    public function voucherRemove(Request $request): Response
    {
        $ctx = $this->payContext($request, 'voucher');
        return $ctx instanceof Response ? $ctx : $this->removeCode($request, $ctx, 'voucher');
    }

    /** POST /checkout/courtpay/{trx}/voucher/confirm */
    public function voucherConfirm(Request $request): Response
    {
        $ctx = $this->payContext($request, 'voucher');
        return $ctx instanceof Response ? $ctx : $this->confirmCode($request, $ctx, 'voucher');
    }

    /**
     * GET /checkout/courtpay/{trx}/cancel - nothing was taken yet, so just return to checkout.
     */
    public function cancel(Request $request): Response
    {
        return Response::redirect('/checkout/' . rawurlencode($request->param('trx')), 303);
    }

    /**
     * Court, ticket or notice number as typed by the payer: letters, digits, spaces, / and -.
     */
    public static function cleanReference(string $raw): string
    {
        $ref = strtoupper(trim((string) preg_replace('/\s+/', ' ', $raw)));
        return preg_match('#^[A-Z0-9][A-Z0-9 /-]{0,39}$#', $ref) ? $ref : '';
    }

    /**
     * Whether a method needs a signed-in CourtPay account (vouchers do; card fines do not).
     */
    public static function requiresAccount(string $method): bool
    {
        return $method === 'voucher';
    }

    /**
     * Context for a payment POST: session open, method enabled, account signed in where the
     * method needs one, and a valid form token.
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
            || !in_array($method, self::enabledMethods($ctx['creds']), true)
            || (self::requiresAccount($method) && !self::identityVerified($ctx['meta']))
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
        $vars += [
            'method'    => $method,
            'methods'   => array_intersect_key(self::METHODS, array_flip(self::enabledMethods($ctx['creds']))),
            'name'      => '',
            'reference' => $this->getString($ctx['meta']['reference'] ?? ''),
        ];
        if (self::requiresAccount($method) && !self::identityVerified($ctx['meta'])) {
            $vars['identify'] = $this->identifyVars($ctx);
        }
        if ($method === 'voucher') {
            $applied = self::appliedOf($ctx['meta']);
            $contact = self::verifiedContact($ctx['meta']);
            $vars += [
                'applied'   => $applied,
                'remaining' => PrepaidCodeStore::remaining(PrepaidCodeStore::money($ctx['meta']['amount'] ?? null), $applied),
                'can_add'   => count($applied) < self::MAX_CODES,
                'signed_in' => $contact !== null ? CheckoutIdentityService::maskContact($contact) : '',
            ];
        }
        return $this->render($request, 'page', $ctx, $vars, $status);
    }

    protected function brandVars(): array
    {
        return [
            'brand' => [
                'logo'    => $this->assetDataUri('logo.png'),
                'mark'    => $this->assetDataUri('icon.svg'),
                'hotline' => '(868) 866-CPAY',
                'helpdesk' => 'courtpayhelpdesk@ttlawcourts.org',
            ],
        ];
    }

    protected function codeStore(): PrepaidCodeStore
    {
        return new PrepaidCodeStore(Database::getInstance(), 'op_plugin_courtpay');
    }
}
