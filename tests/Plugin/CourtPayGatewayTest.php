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

require_once dirname(__DIR__, 2) . '/modules/gateways/courtpay/CourtPayGateway.php';

use OwnPay\Modules\Gateways\Courtpay\CourtPayGateway;

final class CourtPayGatewayTest extends TestCase
{
    private const KEY = 'cp_test_key';

    protected function tearDown(): void
    {
        Database::reset();
    }

    public function testMetadataAndBranding(): void
    {
        $gw = new CourtPayGateway();
        $this->assertSame('courtpay', $gw->slug());
        $this->assertSame('CourtPay', CourtPayGateway::metadata()['name']);
        $this->assertSame('CourtPay', $gw->statusLabel());

        $dir = dirname(__DIR__, 2) . '/modules/gateways/courtpay/';
        $manifest = json_decode((string) file_get_contents($dir . 'manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame('CourtPay', $manifest['name']);
        $this->assertFileExists($dir . $manifest['icon']);
        $this->assertFileExists($dir . 'assets/logo.png');
        $this->assertStringContainsString('#076AAB', (string) file_get_contents($dir . 'assets/icon.svg'));
    }

    public function testVerifyCardAndVoucher(): void
    {
        $gw = new CourtPayGateway();
        $data = [
            'trx_id' => 'TRX1', 'method' => 'card', 'session' => 'cp_abc', 'outcome' => 'approved', 'amount' => '300.00',
            'signature' => DemoCard::sign('TRX1', 'cp_abc', 'approved', '300.00', self::KEY),
        ];
        $this->assertTrue($gw->verify($data, ['api_key' => self::KEY])['success']);
        $this->assertFalse($gw->verify(['amount' => '3.00'] + $data, ['api_key' => self::KEY])['success']);
        $this->assertFalse($gw->verify($data, ['api_key' => 'cp_other'])['success']);

        $this->useSqlite();
        Database::getInstance()->execute("INSERT INTO op_plugin_courtpay_redemptions (code_id, trx_id, merchant_id, amount) VALUES (1, 'V1', 3, '300.00')");
        $voucher = $gw->verify(['trx_id' => 'V1', 'method' => 'voucher', 'session' => 'cp_v'], []);
        $this->assertTrue($voucher['success']);
        $this->assertSame('300.00', $voucher['amount'] ?? null);
        $this->assertFalse($gw->verify(['trx_id' => 'V2', 'method' => 'voucher'], [])['success']);
    }

    public function testReferenceAndAccountRules(): void
    {
        $this->assertSame('POS-TT-2026-001234', CourtPayGateway::cleanReference('  pos-tt-2026-001234 '));
        $this->assertSame('MC 12/2026', CourtPayGateway::cleanReference('mc   12/2026'));
        $this->assertSame('', CourtPayGateway::cleanReference('<script>'));
        $this->assertSame('', CourtPayGateway::cleanReference(str_repeat('A', 41)));

        $this->assertFalse(CourtPayGateway::requiresAccount('card'));
        $this->assertTrue(CourtPayGateway::requiresAccount('voucher'));
        $this->assertSame(['card', 'voucher'], CourtPayGateway::enabledMethods([]));
        $this->assertSame(['voucher'], CourtPayGateway::enabledMethods(['method_card' => 'no']));
    }

    public function testVoucherNeedsCourtPayAccount(): void
    {
        $this->useSqlite();
        $this->insertTransaction('T9');
        $gw = $this->gateway();
        $token = hash_hmac('sha256', 'form|T9|cp_sess', self::KEY);

        foreach (['voucherApply', 'voucherRemove', 'voucherConfirm'] as $action) {
            $request = new Request([], ['token' => $token, 'code' => 'CP-TOPUP-1000'], ['REQUEST_METHOD' => 'POST']);
            $request->setRouteParams(['trx' => 'T9']);
            $response = $gw->{$action}($request);
            $this->assertSame(303, $response->getStatusCode(), $action);
            $this->assertSame('/checkout/courtpay/T9/page?method=voucher', $response->getHeaders()['Location'] ?? null, $action);
        }
    }

    public function testFinesByCardNeedNoAccount(): void
    {
        $this->useSqlite();
        $this->insertTransaction('T8');
        $token = hash_hmac('sha256', 'form|T8|cp_sess', self::KEY);
        $request = new Request([], [
            'token' => $token, 'reference' => 'pos-tt-1', 'cardholder' => 'Jane Doe',
            'card_number' => '4242 4242 4242 4241', 'expiry' => '12/30', 'cvc' => '123',
        ], ['REQUEST_METHOD' => 'POST']);
        $request->setRouteParams(['trx' => 'T8']);

        $response = $this->gateway()->card($request);
        $html = $response->getBody();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('The card number is not valid.', $html);
        $this->assertStringContainsString('value="POS-TT-1"', $html);
        $this->assertStringContainsString('alt="CourtPay"', $html);
        $this->assertStringContainsString('Judiciary of Trinidad and Tobago', $html);
        $this->assertStringContainsString('First Atlantic Commerce', $html);
        $this->assertStringContainsString('866-CPAY', $html);
    }

    public function testVoucherTabAsksForSignIn(): void
    {
        $this->useSqlite();
        $this->insertTransaction('T7');
        $request = new Request(['method' => 'voucher'], [], ['REQUEST_METHOD' => 'GET']);
        $request->setRouteParams(['trx' => 'T7']);

        $html = $this->gateway()->page($request)->getBody();
        $this->assertStringContainsString('CourtPay account', $html);
        $this->assertStringContainsString('action="/checkout/courtpay/T7/identify"', $html);
        $this->assertStringContainsString('name="method" value="voucher"', $html);
    }

    private function insertTransaction(string $trx): void
    {
        $meta = ['courtpay' => [
            'session' => 'cp_sess', 'expires_at' => time() + 600, 'amount' => '300.00', 'currency' => 'TTD',
            'stage' => 'pay', 'method' => null, 'applied' => [], 'reference' => '',
        ]];
        Database::getInstance()->execute(
            "INSERT INTO op_transactions (merchant_id, customer_id, trx_id, status, gateway_slug, metadata) VALUES (3, NULL, :t, 'processing', 'courtpay', :m)",
            ['t' => $trx, 'm' => (string) json_encode($meta)]
        );
    }

    private function gateway(): CourtPayGateway
    {
        $container = new Container();
        $loader = new FilesystemLoader([dirname(__DIR__, 2) . '/templates']);
        $container->instance(Environment::class, new Environment($loader));
        $container->instance(CheckoutIdentityService::class, (new \ReflectionClass(CheckoutIdentityService::class))->newInstanceWithoutConstructor());

        $gw = new CourtPayGateway();
        $gw->boot($container);
        $prop = new \ReflectionProperty(CourtPayGateway::class, 'credentials');
        $prop->setValue($gw, [3 => ['api_key' => self::KEY]]);
        return $gw;
    }

    private function useSqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE op_plugin_courtpay_redemptions (id INTEGER PRIMARY KEY, code_id INTEGER, trx_id TEXT, merchant_id INTEGER, amount TEXT, refunded_amount TEXT DEFAULT "0.00")');
        $pdo->exec('CREATE TABLE op_transactions (id INTEGER PRIMARY KEY, merchant_id INTEGER, customer_id INTEGER, trx_id TEXT, status TEXT, gateway_slug TEXT, metadata TEXT)');
        $pdo->exec('CREATE TABLE op_merchants (id INTEGER PRIMARY KEY, name TEXT, settings TEXT)');
        $pdo->exec("INSERT INTO op_merchants (id, name, settings) VALUES (3, 'Magistrates Court', NULL)");
        Database::setInstance(new Database($pdo));
    }
}
