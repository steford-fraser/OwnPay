<?php

declare(strict_types=1);

namespace Tests\Plugin;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Gateway\Demo\DemoCard;
use OwnPay\Http\Request;
use OwnPay\Service\Customer\CheckoutIdentityService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require_once dirname(__DIR__, 2) . '/modules/gateways/govpay/GovPayGateway.php';

use OwnPay\Modules\Gateways\Govpay\GovPayGateway;
use OwnPay\Modules\Gateways\Govpay\GovPayWallet;

final class GovPayGatewayTest extends TestCase
{
    private const KEY = 'gp_test_key';

    protected function tearDown(): void
    {
        Database::reset();
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $outcome = 'approved', string $amount = '75.00'): array
    {
        return [
            'trx_id' => 'TRX1', 'method' => 'card', 'session' => 'gp_abc', 'outcome' => $outcome, 'amount' => $amount,
            'signature' => DemoCard::sign('TRX1', 'gp_abc', $outcome, $amount, self::KEY),
        ];
    }

    public function testMetadataAndBranding(): void
    {
        $gw = new GovPayGateway();
        $this->assertSame('govpay', $gw->slug());
        $this->assertSame('GovPay', GovPayGateway::metadata()['name']);
        $this->assertSame('GovPay', $gw->statusLabel());
        $this->assertTrue($gw->supports('refund'));

        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/gateways/govpay/manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame('GovPay', $manifest['name']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/modules/gateways/govpay/' . $manifest['icon']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/modules/gateways/govpay/assets/logo.png');
        $this->assertFileExists(dirname(__DIR__, 2) . '/modules/gateways/govpay/assets/coat-of-arms.png');

        $names = array_column($gw->fields(), 'name');
        foreach (['api_key', 'method_card', 'method_wallet', 'method_code', 'wallet_opening_balance'] as $field) {
            $this->assertContains($field, $names);
        }
    }

    public function testVerifyCard(): void
    {
        $gw = new GovPayGateway();
        $ok = $gw->verify($this->signed(), ['api_key' => self::KEY]);
        $this->assertTrue($ok['success']);
        $this->assertSame('75.00', $ok['amount'] ?? null);
        $this->assertSame('gp_abc', $ok['gateway_trx_id'] ?? null);

        $tampered = $this->signed();
        $tampered['amount'] = '1.00';
        $this->assertFalse($gw->verify($tampered, ['api_key' => self::KEY])['success']);
        $this->assertFalse($gw->verify($this->signed(), ['api_key' => 'gp_other'])['success']);
        $this->assertFalse($gw->verify($this->signed('declined'), ['api_key' => self::KEY])['success']);
        $this->assertFalse($gw->verify(['method' => 'cheque'] + $this->signed(), ['api_key' => self::KEY])['success']);
    }

    public function testVerifyWalletAndCodesUseRecordedTotals(): void
    {
        $this->useSqlite();
        $db = Database::getInstance();
        $db->execute("INSERT INTO op_plugin_govpay_wallet_ledger (wallet_id, trx_id, merchant_id, amount) VALUES (1, 'W1', 3, '60.00')");
        $db->execute("INSERT INTO op_plugin_govpay_redemptions (code_id, trx_id, merchant_id, amount) VALUES (1, 'C1', 3, '40.00'), (2, 'C1', 3, '35.00')");

        $gw = new GovPayGateway();
        $wallet = $gw->verify(['trx_id' => 'W1', 'method' => 'wallet', 'session' => 'gp_w'], []);
        $this->assertTrue($wallet['success']);
        $this->assertSame('60.00', $wallet['amount'] ?? null);

        $codes = $gw->verify(['trx_id' => 'C1', 'method' => 'code', 'session' => 'gp_c'], []);
        $this->assertTrue($codes['success']);
        $this->assertSame('75.00', $codes['amount'] ?? null);

        $this->assertFalse($gw->verify(['trx_id' => 'C1', 'method' => 'wallet', 'session' => 'gp_c'], [])['success']);
        $this->assertFalse($gw->verify(['trx_id' => 'NONE', 'method' => 'code'], [])['success']);
    }

    public function testInitiateRequiresAMethod(): void
    {
        $params = ['amount' => '10.00', 'currency' => 'TTD', 'trx_id' => 'T1', 'redirect_url' => 'https://x.test/checkout/T1/status', 'cancel_url' => 'https://x.test/checkout/T1'];
        $this->expectExceptionMessage('No GovPay payment methods are enabled.');
        (new GovPayGateway())->initiate($params, ['method_card' => 'no', 'method_wallet' => 'no', 'method_code' => 'no']);
    }

    public function testCardResultVerifiesWithoutConfiguredKey(): void
    {
        $data = ['trx_id' => 'T1', 'session' => 'gp_abc', 'outcome' => 'approved', 'amount' => '25.00', 'method' => 'card'];
        $data['signature'] = DemoCard::sign('T1', 'gp_abc', 'approved', '25.00', DemoCard::secret(null, 'govpay'));
        $this->assertTrue((new GovPayGateway())->verify($data, [])['success']);
        $this->assertFalse((new GovPayGateway())->verify($data, ['api_key' => self::KEY])['success']);
    }

    public function testEnabledMethods(): void
    {
        $this->assertSame(['card', 'wallet', 'code'], GovPayGateway::enabledMethods([]));
        $this->assertSame(['card', 'code'], GovPayGateway::enabledMethods(['method_wallet' => 'no']));
    }

    public function testWalletRules(): void
    {
        $this->assertSame(GovPayWallet::keyFor(['type' => 'email', 'value' => 'Jane@Example.com']), GovPayWallet::keyFor(['type' => 'email', 'value' => 'jane@example.com']));
        $this->assertNotSame(GovPayWallet::keyFor(['type' => 'email', 'value' => 'a@b.co']), GovPayWallet::keyFor(['type' => 'phone', 'value' => 'a@b.co']));
        $this->assertTrue(GovPayWallet::canPay('100.00', '100.00'));
        $this->assertFalse(GovPayWallet::canPay('99.99', '100.00'));
        $this->assertFalse(GovPayWallet::canPay('100.00', '0.00'));
    }

    public function testPaymentsRequireGovPaySignIn(): void
    {
        $this->useSqlite();
        $this->insertTransaction('T9', ['stage' => 'code', 'contact' => ['type' => 'email', 'value' => 'a@b.co'], 'customer_id' => 7]);
        $gw = $this->gateway();
        $token = hash_hmac('sha256', 'form|T9|gp_sess', self::KEY);

        foreach (['card' => 'card', 'wallet' => 'wallet', 'codeApply' => 'code', 'codeConfirm' => 'code'] as $action => $method) {
            $request = new Request([], ['token' => $token, 'code' => 'GOV-VCH-1000'], ['REQUEST_METHOD' => 'POST']);
            $request->setRouteParams(['trx' => 'T9']);
            $response = $gw->{$action}($request);
            $this->assertSame(303, $response->getStatusCode(), $action);
            $this->assertSame('/checkout/govpay/T9/page?method=' . $method, $response->getHeaders()['Location'] ?? null, $action);
        }
        $this->assertSame('processing', Database::getInstance()->fetchColumn("SELECT status FROM op_transactions WHERE trx_id = 'T9'"));
    }

    public function testSignInPageIsGovPayBranded(): void
    {
        $this->useSqlite();
        $this->insertTransaction('T7', null);
        $request = new Request(['method' => 'wallet'], [], ['REQUEST_METHOD' => 'GET']);
        $request->setRouteParams(['trx' => 'T7']);

        $response = $this->gateway()->page($request);
        $html = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<strong>GovPay</strong>', $html);
        $this->assertStringContainsString('Government of Trinidad and Tobago', $html);
        $this->assertStringContainsString('alt="MyTT"', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Sign in to GovPay', $html);
        $this->assertStringContainsString('name="method" value="wallet"', $html);
        $this->assertStringContainsString('800-GOVPAY', $html);
    }

    /**
     * @param array<string, mixed>|null $identity
     */
    private function insertTransaction(string $trx, ?array $identity): void
    {
        $meta = ['govpay' => [
            'session' => 'gp_sess', 'expires_at' => time() + 600, 'amount' => '100.00', 'currency' => 'TTD',
            'stage' => 'pay', 'method' => null, 'applied' => [], 'identity' => $identity,
        ]];
        Database::getInstance()->execute(
            "INSERT INTO op_transactions (merchant_id, customer_id, trx_id, status, gateway_slug, metadata) VALUES (3, NULL, :t, 'processing', 'govpay', :m)",
            ['t' => $trx, 'm' => (string) json_encode($meta)]
        );
    }

    private function gateway(): GovPayGateway
    {
        $container = new Container();
        $loader = new FilesystemLoader([dirname(__DIR__, 2) . '/templates']);
        $container->instance(Environment::class, new Environment($loader));
        $container->instance(CheckoutIdentityService::class, (new \ReflectionClass(CheckoutIdentityService::class))->newInstanceWithoutConstructor());

        $gw = new GovPayGateway();
        $gw->boot($container);
        $prop = new \ReflectionProperty(GovPayGateway::class, 'credentials');
        $prop->setValue($gw, [3 => ['api_key' => self::KEY]]);
        return $gw;
    }

    private function useSqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE op_plugin_govpay_redemptions (id INTEGER PRIMARY KEY, code_id INTEGER, trx_id TEXT, merchant_id INTEGER, amount TEXT, refunded_amount TEXT DEFAULT "0.00")');
        $pdo->exec('CREATE TABLE op_plugin_govpay_wallet_ledger (id INTEGER PRIMARY KEY, wallet_id INTEGER, trx_id TEXT, merchant_id INTEGER, amount TEXT, refunded_amount TEXT DEFAULT "0.00")');
        $pdo->exec('CREATE TABLE op_transactions (id INTEGER PRIMARY KEY, merchant_id INTEGER, customer_id INTEGER, trx_id TEXT, status TEXT, gateway_slug TEXT, metadata TEXT)');
        $pdo->exec('CREATE TABLE op_merchants (id INTEGER PRIMARY KEY, name TEXT, settings TEXT)');
        $pdo->exec("INSERT INTO op_merchants (id, name, settings) VALUES (3, 'Ministry of Works', NULL)");
        Database::setInstance(new Database($pdo));
    }
}
