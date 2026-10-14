<?php

declare(strict_types=1);

namespace Tests\Plugin;

use OwnPay\Core\Database;
use OwnPay\Http\Request;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/npictt-voucher/VoucherBalanceChanged.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/npictt-voucher/VoucherStore.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/npictt-voucher/VoucherAdmin.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/npictt-voucher/VoucherPayGateway.php';

use OwnPay\Modules\Gateways\Voucherpay\VoucherPayGateway;
use OwnPay\Modules\Gateways\Voucherpay\VoucherStore;

final class VoucherPayGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::reset();
    }

    public function testSingleVoucherCoversPayment(): void
    {
        $this->assertSame('250.00', VoucherStore::allocate('500.00', '250.00'));
        $this->assertSame('0.00', VoucherStore::remaining('250.00', [['amount' => '250.00']]));
    }

    public function testSplitAcrossTwoVouchers(): void
    {
        $first = VoucherStore::allocate('100.00', '250.00');
        $this->assertSame('100.00', $first);
        $due = VoucherStore::remaining('250.00', [['amount' => $first]]);
        $this->assertSame('150.00', $due);

        $second = VoucherStore::allocate('500.00', $due);
        $this->assertSame('150.00', $second);
        $this->assertSame('0.00', VoucherStore::remaining('250.00', [['amount' => $first], ['amount' => $second]]));
    }

    public function testReallocateAfterRemoval(): void
    {
        $applied = [
            ['voucher_id' => 2, 'code' => 'B', 'amount' => '150.00', 'balance' => '500.00'],
        ];
        $out = VoucherPayGateway::reallocate('250.00', $applied);
        $this->assertSame('250.00', $out[0]['amount']);
    }

    public function testRejectReasons(): void
    {
        $now = (int) strtotime('2026-10-13 12:00:00');
        $ok = ['status' => 'active', 'currency' => 'TTD', 'balance' => '100.00', 'expires_at' => null];

        $this->assertNull(VoucherStore::rejectReason($ok, 'TTD', $now));
        $this->assertSame('Voucher not valid.', VoucherStore::rejectReason(null, 'TTD', $now));
        $this->assertNotNull(VoucherStore::rejectReason(['status' => 'void'] + $ok, 'TTD', $now));
        $this->assertNotNull(VoucherStore::rejectReason(['expires_at' => '2026-10-12 23:59:59'] + $ok, 'TTD', $now));
        $this->assertNotNull(VoucherStore::rejectReason($ok, 'USD', $now));
        $this->assertNotNull(VoucherStore::rejectReason(['balance' => '0.00'] + $ok, 'TTD', $now));
    }

    public function testCodeNormalizationAndFormat(): void
    {
        $this->assertSame('DEMO-100', VoucherStore::normalize('  demo-100 '));
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', VoucherStore::generateCode());
    }

    public function testVerifyReturnsRedeemedTotal(): void
    {
        $this->useSqlite();
        Database::getInstance()->execute(
            "INSERT INTO op_plugin_voucherpay_redemptions (voucher_id, trx_id, merchant_id, amount) VALUES (1, 'T1', 3, '100.00'), (2, 'T1', 3, '150.00')"
        );

        $result = (new VoucherPayGateway())->verify(['trx_id' => 'T1', 'session' => 'vch_x'], []);
        $this->assertTrue($result['success']);
        $this->assertSame('250.00', $result['amount'] ?? null);
        $this->assertSame('vch_x', $result['gateway_trx_id'] ?? null);
    }

    public function testVerifyFailsWithoutRedemptions(): void
    {
        $this->useSqlite();
        $this->assertFalse((new VoucherPayGateway())->verify(['trx_id' => 'T2'], [])['success']);
        $this->assertFalse((new VoucherPayGateway())->verify([], [])['success']);
    }

    public function testIdentityMustBeVerified(): void
    {
        $this->assertFalse(VoucherPayGateway::identityVerified([]));
        $this->assertFalse(VoucherPayGateway::identityVerified(['identity' => ['stage' => 'code']]));
        $this->assertTrue(VoucherPayGateway::identityVerified(['identity' => ['stage' => 'verified']]));
    }

    public function testPaymentActionsRejectedBeforeVerification(): void
    {
        $this->useSqlite();
        $session = 'vch_test';
        $meta = ['voucherpay' => ['session' => $session, 'amount' => '100.00', 'currency' => 'TTD', 'applied' => [
            ['voucher_id' => 1, 'code' => 'NPICTT-100', 'amount' => '100.00', 'balance' => '100.00'],
        ], 'identity' => ['stage' => 'code', 'contact' => ['type' => 'email', 'value' => 'a@b.co'], 'customer_id' => 7]]];
        Database::getInstance()->execute(
            "INSERT INTO op_transactions (id, merchant_id, customer_id, trx_id, status, gateway_slug, metadata) VALUES (1, 3, NULL, 'T9', 'processing', 'npictt-voucher', :m)",
            ['m' => (string) json_encode($meta)]
        );
        $token = hash_hmac('sha256', 'voucher-form|T9', $session);

        foreach (['apply', 'remove', 'confirm'] as $action) {
            $request = new Request([], ['token' => $token, 'code' => 'NPICTT-100'], ['REQUEST_METHOD' => 'POST']);
            $request->setRouteParams(['trx' => 'T9']);
            $response = (new VoucherPayGateway())->{$action}($request);
            $this->assertSame(303, $response->getStatusCode(), $action);
            $this->assertSame('/checkout/voucher/T9/page', $response->getHeaders()['Location'] ?? null, $action);
        }
        $this->assertSame('processing', Database::getInstance()->fetchColumn("SELECT status FROM op_transactions WHERE trx_id = 'T9'"));
    }

    private function useSqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE op_plugin_voucherpay_redemptions (id INTEGER PRIMARY KEY, voucher_id INTEGER, trx_id TEXT, merchant_id INTEGER, amount TEXT, refunded_amount TEXT DEFAULT "0.00")');
        $pdo->exec('CREATE TABLE op_transactions (id INTEGER PRIMARY KEY, merchant_id INTEGER, customer_id INTEGER, trx_id TEXT, status TEXT, gateway_slug TEXT, metadata TEXT)');
        Database::setInstance(new Database($pdo));
    }
}
