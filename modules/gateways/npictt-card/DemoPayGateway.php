<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Demopay;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\Demo\DemoCard;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Customer\CheckoutIdentityService;
use OwnPay\Service\Payment\GatewayApiService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\System\Logger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * DemoPay - a simulated third-party hosted card payment page for demos.
 *
 * Flow: initiate() stores a DemoPay session on the transaction and sends the payer to page();
 * the card form posts to submit(), which maps the card to a test outcome (optionally via the
 * threeDs() OTP challenge) and renders an interstitial that posts an HMAC-signed result to
 * merchantResponse(). That completes the payment through the core callback pipeline
 * (verify() checks the signature and amount) and redirects to the checkout status page.
 *
 * No real card is charged and the full card number is never stored.
 */
final class DemoPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SLUG = 'npictt-card';
    private const PATH = '/checkout/card/';
    private const DEFAULT_LABEL = 'Credit/Debit Card';
    private const SESSION_TTL = 900;
    private const OTP = DemoCard::OTP;

    /** Payer-facing message per outcome. */
    public const OUTCOMES = DemoCard::OUTCOMES;

    /** Test card number => outcome ('challenge' = 3-D Secure OTP step). */
    public const TEST_CARDS = DemoCard::TEST_CARDS;

    private ?Container $container = null;

    /** @var array<int, array<string, string>> Decrypted credentials per merchant, memoised for the request. */
    private array $credentials = [];

    /** Gateway name shown on the payer's receipt / status page. */
    public function statusLabel(): string
    {
        return 'NPICTT Payment Gateway';
    }

    public static function metadata(): array
    {
        return [
            'name' => 'NPICTT Payment Gateway - Card', 'slug' => self::SLUG, 'version' => '1.0.0',
            'description' => 'Hosted card payments with 3-D Secure',
            'author' => 'NPICTT', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return self::SLUG; }

    public function register(EventManager $events, Container $container): void
    {
        $this->container = $container;

        $events->addFilter('checkout.render', function (mixed $data) use ($events): mixed {
            if (!is_array($data) || !is_array($data['gateways'] ?? null)) {
                return $data;
            }
            $mid = $this->getInt($this->getArray($data, 'txn')['merchant_id'] ?? 0);
            // Filter listeners run inside this plugin's DB sandbox, which blocks reads of core op_* tables.
            $events->pushOwner('core');
            try {
                $label = trim($this->credentialsFor($mid)['checkout_label'] ?? '');
            } finally {
                $events->popOwner();
            }
            $label = $label !== '' ? $label : self::DEFAULT_LABEL;
            $gateways = $data['gateways'];
            foreach ($gateways as $cat => $list) {
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $i => $gw) {
                    if (is_array($gw) && ($gw['slug'] ?? null) === self::SLUG) {
                        $gw['name'] = $label;
                        $list[$i] = $gw;
                    }
                }
                $gateways[$cat] = $list;
            }
            $data['gateways'] = $gateways;
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
            ['name' => 'demo_enabled', 'label' => 'Accept payments', 'type' => 'select', 'options' => ['yes' => 'Yes', 'no' => 'No'], 'default' => 'yes', 'required' => true],
            ['name' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text'],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
            ['name' => 'checkout_label', 'label' => 'Checkout Label', 'type' => 'text', 'default' => self::DEFAULT_LABEL],
            ['name' => 'three_ds', 'label' => '3-D Secure', 'type' => 'select', 'options' => ['test_cards' => 'Only for the 3DS test card', 'always' => 'Always challenge', 'never' => 'Never challenge'], 'default' => 'test_cards'],
            ['name' => 'processing_delay', 'label' => 'Processing delay (seconds)', 'type' => 'text', 'default' => '2'],
        ];
    }

    public function supports(string $feature): bool
    {
        return $feature === 'refund';
    }

    public function initiate(array $params, array $credentials): array
    {
        if (!self::enabled($credentials)) {
            throw new \RuntimeException('Card payments are not enabled for this brand.');
        }

        $trxId = $params['trx_id'];
        $returnBase = self::baseUrlOf($params['redirect_url']);
        if ($trxId === '' || $returnBase === '') {
            throw new \RuntimeException('Card payment initiation is missing the transaction reference.');
        }

        $session = 'cs_' . bin2hex(random_bytes(12));
        Database::getInstance()->update(
            "UPDATE op_transactions
             SET gateway_trx_id = :sid,
                 metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.demopay', CAST(:m AS JSON))
             WHERE trx_id = :trx",
            [
                'sid' => $session,
                'trx' => $trxId,
                'm'   => (string) json_encode([
                    'session'    => $session,
                    'expires_at' => time() + self::SESSION_TTL,
                    'amount'     => $this->toDecimalString($params['amount']),
                    'currency'   => $params['currency'],
                    'stage'      => 'card',
                ]),
            ]
        );

        return [
            'redirect_url' => $returnBase . self::PATH . rawurlencode($trxId) . '/page',
            'session_id'   => $session,
        ];
    }

    /**
     * Expects ['trx_id', 'session', 'outcome', 'amount', 'signature'] as posted by the processing page.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $session = $this->getString($callbackData['session'] ?? '');
        $outcome = $this->getString($callbackData['outcome'] ?? '');
        $amount = $this->getString($callbackData['amount'] ?? '');
        $signature = $this->getString($callbackData['signature'] ?? '');
        $secret = self::signingKey($credentials);

        $fail = ['success' => false, 'status' => 'failed', 'trx_id' => $trxId];
        if ($trxId === '' || $session === '' || $signature === '' || !is_numeric($amount)) {
            return $fail;
        }
        if (!hash_equals(self::sign($trxId, $session, $outcome, $amount, $secret), $signature)) {
            return $fail;
        }
        if ($outcome !== 'approved') {
            return $fail + ['message' => self::OUTCOMES[$outcome] ?? 'Payment was not completed.'];
        }

        return [
            'success'        => true,
            'gateway_trx_id' => $session,
            'amount'         => $this->toDecimalString($amount),
            'status'         => 'completed',
            'trx_id'         => $trxId,
        ];
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        if (!self::enabled($credentials)) {
            return ['success' => false, 'error' => 'Card payments are not enabled for this brand.'];
        }
        return ['success' => true, 'refund_id' => 're_' . bin2hex(random_bytes(8))];
    }

    /**
     * GET /checkout/card/{trx}/page - the hosted card form.
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
        if ($ctx['meta']['stage'] !== 'card') {
            return Response::redirect($this->statusUrl($ctx['trx']), 303);
        }
        if (!self::identityVerified($ctx['meta'])) {
            return $this->renderIdentify($request, $ctx);
        }
        return $this->render($request, 'page', $ctx, ['error' => '', 'name' => '']);
    }

    /**
     * POST /checkout/card/{trx}/identify - payer enters their email or mobile number.
     */
    public function identify(Request $request): Response
    {
        return $this->identityStep($request, 'identify');
    }

    /**
     * POST /checkout/card/{trx}/signup - new payer's details.
     */
    public function signup(Request $request): Response
    {
        return $this->identityStep($request, 'signup');
    }

    /**
     * POST /checkout/card/{trx}/verify - verification code.
     */
    public function verifyCode(Request $request): Response
    {
        return $this->identityStep($request, 'verify');
    }

    /**
     * POST /checkout/card/{trx}/identify/reset - start over with a different email or phone.
     */
    public function identifyReset(Request $request): Response
    {
        return $this->identityStep($request, 'reset');
    }

    /**
     * POST /checkout/card/{trx}/submit - validates the card and resolves the test outcome.
     */
    public function submit(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($ctx['meta']['stage'] !== 'card' || !self::identityVerified($ctx['meta']) || !$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }

        $name = trim($this->getString($request->post('cardholder', '')));
        $number = (string) preg_replace('/\D/', '', $this->getString($request->post('card_number', '')));
        $expiry = trim($this->getString($request->post('expiry', '')));
        $cvc = trim($this->getString($request->post('cvc', '')));

        $error = self::validateCard($name, $number, $expiry, $cvc);
        if ($error !== null) {
            return $this->render($request, 'page', $ctx, ['error' => $error, 'name' => $name], 422);
        }

        $card = ['brand' => self::cardBrand($number), 'last4' => substr($number, -4)];
        $outcome = self::cardOutcome($number, $ctx['creds']['three_ds'] ?? 'test_cards');
        if ($outcome === 'challenge') {
            $this->saveMeta($ctx['trx'], ['stage' => '3ds', 'card' => $card] + $ctx['meta']);
            return Response::redirect($this->path($ctx['trx'], '3ds'), 303);
        }

        return $this->finish($request, $ctx, $outcome, $card);
    }

    /**
     * GET|POST /checkout/card/{trx}/3ds - issuer-style one-time-passcode challenge.
     */
    public function threeDs(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($ctx['meta']['stage'] !== '3ds' || !self::identityVerified($ctx['meta'])) {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }
        if ($request->method() !== 'POST') {
            return $this->render($request, '3ds', $ctx, ['card' => $this->cardOf($ctx['meta'])]);
        }
        if (!$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], '3ds'), 303);
        }

        $otp = trim($this->getString($request->post('otp', '')));
        $outcome = hash_equals(self::OTP, $otp) ? 'approved' : 'auth_failed';
        return $this->finish($request, $ctx, $outcome, $this->cardOf($ctx['meta']));
    }

    /**
     * POST /checkout/card/{trx}/return - signed result posted back by the processing page.
     */
    public function merchantResponse(Request $request): Response
    {
        $trxId = $request->param('trx');
        $statusUrl = $this->statusUrl($trxId);
        $txn = $this->findTransaction($trxId);
        if ($txn === null) {
            return Response::html('Payment not found.', 404);
        }

        $meta = self::demoMeta($txn);
        $outcome = $this->getString($request->post('outcome', ''));
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

        $success = false;
        try {
            $svc = $this->container?->get(GatewayApiService::class);
            if ($svc instanceof GatewayApiService) {
                $result = $svc->handleCallback($mid, self::SLUG, [
                    'trx_id'    => $trxId,
                    'session'   => $this->getString($meta['session'] ?? ''),
                    'outcome'   => $outcome,
                    'amount'    => $this->getString($request->post('amount', '')),
                    'signature' => $this->getString($request->post('signature', '')),
                ]);
                $success = (bool) $result['success'];
            }
        } catch (\Throwable $e) {
            $this->logger()?->error('Card return failed: ' . $e->getMessage(), ['trx_id' => $trxId]);
        }

        if (!$success) {
            $reason = self::OUTCOMES[$outcome] ?? 'Payment was not completed.';
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
     * GET /checkout/card/{trx}/cancel - back to the checkout; core re-opens the transaction for retry.
     */
    public function cancel(Request $request): Response
    {
        return Response::redirect('/checkout/' . rawurlencode($request->param('trx')), 303);
    }

    public static function sign(string $trxId, string $session, string $outcome, string $amount, string $secret): string
    {
        return DemoCard::sign($trxId, $session, $outcome, $amount, $secret);
    }

    public static function luhn(string $digits): bool
    {
        return DemoCard::luhn($digits);
    }

    public static function cardOutcome(string $number, string $threeDs): string
    {
        return DemoCard::outcome($number, $threeDs);
    }

    public static function cardBrand(string $number): string
    {
        return DemoCard::brand($number);
    }

    public static function validateCard(string $name, string $number, string $expiry, string $cvc, ?int $now = null): ?string
    {
        return DemoCard::validate($name, $number, $expiry, $cvc, $now);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function identityVerified(array $meta): bool
    {
        return CheckoutIdentityService::isVerified(CheckoutIdentityService::state($meta['identity'] ?? null));
    }

    /**
     * @param 'identify'|'signup'|'verify'|'reset' $action
     */
    private function identityStep(Request $request, string $action): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($ctx['meta']['stage'] !== 'card' || self::identityVerified($ctx['meta']) || !$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }

        $input = [];
        foreach (CheckoutIdentityService::INPUT_FIELDS as $field) {
            $input[$field] = $this->getString($request->post($field, ''));
        }
        $state = CheckoutIdentityService::state($ctx['meta']['identity'] ?? null);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        [$next, $error] = $this->identityService()->handle($action, $mid, $ctx['trx'], $state, $input);

        $ctx['meta']['identity'] = $next;
        $this->saveMeta($ctx['trx'], $ctx['meta']);

        return $error !== null
            ? $this->renderIdentify($request, $ctx, $error, 422)
            : Response::redirect($this->path($ctx['trx'], 'page'), 303);
    }

    /**
     * @param array{trx: string, txn: array<string, mixed>, meta: array<string, mixed>, creds: array<string, string>} $ctx
     */
    private function renderIdentify(Request $request, array $ctx, string $error = '', int $status = 200): Response
    {
        $state = CheckoutIdentityService::state($ctx['meta']['identity'] ?? null);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $customerId = $this->getInt($ctx['txn']['customer_id'] ?? 0);
        return $this->render($request, 'identify', $ctx, [
            'ci'    => $this->identityService()->viewVars($mid, $state, $customerId > 0 ? $customerId : null),
            'error' => $error,
        ], $status);
    }

    private function identityService(): CheckoutIdentityService
    {
        $svc = $this->container?->get(CheckoutIdentityService::class);
        if (!$svc instanceof CheckoutIdentityService) {
            throw new \RuntimeException('Customer verification is unavailable.');
        }
        return $svc;
    }

    /**
     * @param array{trx: string, txn: array<string, mixed>, meta: array<string, mixed>, creds: array<string, string>} $ctx
     * @param array{brand: string, last4: string} $card
     */
    private function finish(Request $request, array $ctx, string $outcome, array $card): Response
    {
        $this->saveMeta($ctx['trx'], ['stage' => 'done', 'outcome' => $outcome, 'card' => $card] + $ctx['meta']);

        $session = $this->getString($ctx['meta']['session'] ?? '');
        $amount = $this->getString($ctx['meta']['amount'] ?? '0.00');
        $delay = max(0, min(10, $this->getInt($ctx['creds']['processing_delay'] ?? 2)));

        return $this->render($request, 'processing', $ctx, [
            'card'      => $card,
            'outcome'   => $outcome,
            'delay'     => $delay,
            'signature' => self::sign($ctx['trx'], $session, $outcome, $amount, self::signingKey($ctx['creds'])),
        ]);
    }

    /**
     * Loads the transaction, DemoPay session and credentials for a hosted-page request.
     *
     * @return array{trx: string, txn: array<string, mixed>, meta: array<string, mixed>, creds: array<string, string>}|Response
     */
    private function context(Request $request): array|Response
    {
        $trxId = $request->param('trx');
        $txn = $this->findTransaction($trxId);
        if ($txn === null) {
            return Response::html('Payment not found.', 404);
        }

        $meta = self::demoMeta($txn);
        if (($txn['gateway_slug'] ?? '') !== self::SLUG
            || ($txn['status'] ?? '') !== 'processing'
            || !isset($meta['session'], $meta['stage'])
        ) {
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        $mid = $this->getInt($txn['merchant_id'] ?? 0);
        $creds = $this->credentialsFor($mid);
        if (!self::enabled($creds)) {
            return Response::html('Card payments are not enabled for this brand.', 403);
        }

        if ($this->getInt($meta['expires_at'] ?? 0) < time()) {
            $svc = $this->container?->get(TransactionService::class);
            if ($svc instanceof TransactionService) {
                $svc->fail($this->getInt($txn['id'] ?? 0), $mid, 'Payment session expired.');
            }
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        return ['trx' => $trxId, 'txn' => $txn, 'meta' => $meta, 'creds' => $creds];
    }

    /**
     * @param array{trx: string, txn: array<string, mixed>, meta: array<string, mixed>, creds: array<string, string>} $ctx
     * @param array<string, mixed> $vars
     */
    private function render(Request $request, string $template, array $ctx, array $vars = [], int $status = 200): Response
    {
        $twig = $this->container?->get(Environment::class);
        if (!$twig instanceof Environment) {
            return Response::html('Payment page is unavailable.', 500);
        }
        $loader = $twig->getLoader();
        if ($loader instanceof FilesystemLoader && !in_array('gateway_demopay', $loader->getNamespaces(), true)) {
            $loader->addPath(__DIR__ . '/views', 'gateway_demopay');
        }

        $html = $twig->render('@gateway_demopay/' . $template . '.twig', $vars + [
            'nonce'      => $this->getString($request->getAttribute('csp_nonce', '')),
            'merchant'   => $this->merchantInfo($this->getInt($ctx['txn']['merchant_id'] ?? 0)),
            'trx'        => $ctx['trx'],
            'session'    => $this->getString($ctx['meta']['session'] ?? ''),
            'amount'     => $this->getString($ctx['meta']['amount'] ?? '0.00'),
            'currency'   => $this->getString($ctx['meta']['currency'] ?? ''),
            'token'      => $this->formToken($ctx),
            'expires_in' => max(0, $this->getInt($ctx['meta']['expires_at'] ?? 0) - time()),
            'base'       => rtrim(self::PATH, '/') . '/' . rawurlencode($ctx['trx']),
            'pk'         => $ctx['creds']['publishable_key'] ?? '',
        ]);

        return Response::html($html, $status)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * @param array{trx: string, meta: array<string, mixed>, creds: array<string, string>} $ctx
     */
    private function formToken(array $ctx): string
    {
        return hash_hmac('sha256', 'form|' . $ctx['trx'] . '|' . $this->getString($ctx['meta']['session'] ?? ''), self::signingKey($ctx['creds']));
    }

    /**
     * @param array<string, mixed> $creds
     */
    private static function enabled(array $creds): bool
    {
        return ($creds['demo_enabled'] ?? 'yes') !== 'no';
    }

    /**
     * @param array<string, mixed> $creds
     */
    private static function signingKey(array $creds): string
    {
        return DemoCard::secret($creds['secret_key'] ?? null, self::SLUG);
    }

    /**
     * @param array{trx: string, meta: array<string, mixed>, creds: array<string, string>} $ctx
     */
    private function validToken(Request $request, array $ctx): bool
    {
        return hash_equals($this->formToken($ctx), $this->getString($request->post('token', '')));
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{brand: string, last4: string}
     */
    private function cardOf(array $meta): array
    {
        $card = is_array($meta['card'] ?? null) ? $meta['card'] : [];
        return ['brand' => $this->getString($card['brand'] ?? 'Card'), 'last4' => $this->getString($card['last4'] ?? '')];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function saveMeta(string $trxId, array $meta): void
    {
        Database::getInstance()->update(
            "UPDATE op_transactions
             SET metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.demopay', CAST(:m AS JSON))
             WHERE trx_id = :trx",
            ['trx' => $trxId, 'm' => (string) json_encode($meta)]
        );
    }

    /**
     * @return array<string, string>
     */
    private function credentialsFor(int $merchantId): array
    {
        if (isset($this->credentials[$merchantId])) {
            return $this->credentials[$merchantId];
        }

        $creds = [];
        try {
            $configs = $this->container?->get(GatewayConfigRepository::class);
            $encryptor = $this->container?->get(FieldEncryptor::class);
            if ($merchantId > 0 && $configs instanceof GatewayConfigRepository && $encryptor instanceof FieldEncryptor) {
                $enc = $configs->forTenant($merchantId)->findCredentialsBySlug(self::SLUG);
                $decoded = $enc !== null && $enc !== '' ? json_decode($encryptor->decrypt($enc), true) : null;
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        $creds[(string) $k] = $this->getString($v);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger()?->warning('Card gateway credentials lookup failed: ' . $e->getMessage());
        }

        return $this->credentials[$merchantId] = $creds;
    }

    /**
     * @return array{name: string, color: string, logo: string}
     */
    private function merchantInfo(int $merchantId): array
    {
        $row = Database::getInstance()->fetchOne('SELECT name, settings FROM op_merchants WHERE id = :id', ['id' => $merchantId]);
        $settings = is_string($row['settings'] ?? null) ? json_decode($row['settings'], true) : null;
        $settings = is_array($settings) ? $settings : [];
        $color = $this->getString($settings['primary_color'] ?? '');
        return [
            'name'  => $this->getString($row['name'] ?? 'Merchant'),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#4F46E5',
            'logo'  => $this->getString($settings['logo'] ?? ''),
        ];
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
            'SELECT id, merchant_id, customer_id, trx_id, status, gateway_slug, metadata FROM op_transactions WHERE trx_id = :t LIMIT 1',
            ['t' => $trxId]
        );
    }

    /**
     * @param array<string, mixed> $txn
     * @return array<string, mixed>
     */
    private static function demoMeta(array $txn): array
    {
        $meta = is_string($txn['metadata'] ?? null) ? json_decode($txn['metadata'], true) : null;
        return is_array($meta) && is_array($meta['demopay'] ?? null) ? $meta['demopay'] : [];
    }

    private function path(string $trxId, string $step): string
    {
        return self::PATH . rawurlencode($trxId) . '/' . $step;
    }

    private function statusUrl(string $trxId): string
    {
        return '/checkout/' . rawurlencode($trxId) . '/status';
    }

    private static function baseUrlOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function logger(): ?Logger
    {
        $logger = $this->container?->has(Logger::class) ? $this->container->get(Logger::class) : null;
        return $logger instanceof Logger ? $logger : null;
    }
}
