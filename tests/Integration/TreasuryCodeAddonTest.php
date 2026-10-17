<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Modules\Addons\NpicttTreasury\Plugin as TreasuryPlugin;
use OwnPay\Modules\Addons\NpicttTreasury\TreasuryApiMiddleware;
use OwnPay\Modules\Addons\NpicttTreasury\TreasuryAssignmentRepository;
use OwnPay\Modules\Addons\NpicttTreasury\TreasuryCodeRepository;
use OwnPay\Modules\Addons\NpicttTreasury\TreasuryValidator;
use OwnPay\Service\Payment\TransactionService;

require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/TreasuryCodeRepository.php';
require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/TreasuryAssignmentRepository.php';
require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/TreasuryValidator.php';
require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/TreasuryApiMiddleware.php';
require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/TreasuryAdminMiddleware.php';
require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/TreasuryCheckoutMiddleware.php';
require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/TreasuryAdminController.php';
require_once dirname(__DIR__, 2) . '/modules/addons/npictt-treasury/Plugin.php';

/**
 * Coverage for NPICTT 4-tier treasury compliance addon.
 */
final class TreasuryCodeAddonTest extends IntegrationTestCase
{
    private const SAMPLE = '18/02/001/01';

    private Database $db;
    private TreasuryCodeRepository $codes;
    private TreasuryAssignmentRepository $assignments;
    private TreasuryValidator $validator;
    private EventManager $events;
    private TransactionService $transactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->ensureTables();
        $this->cleanup();

        $this->codes = new TreasuryCodeRepository($this->db);
        $this->assignments = new TreasuryAssignmentRepository($this->db);
        $this->validator = new TreasuryValidator($this->codes);

        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);
        $container->instance(TreasuryCodeRepository::class, $this->codes);
        $container->instance(TreasuryAssignmentRepository::class, $this->assignments);
        $container->instance(TreasuryValidator::class, $this->validator);

        $events = $container->get(EventManager::class);
        $this->assertInstanceOf(EventManager::class, $events);
        $this->events = $events;

        $plugin = new TreasuryPlugin();
        $plugin->register($this->events, $container);
        $plugin->boot($container);

        $txnSvc = $container->get(TransactionService::class);
        $this->assertInstanceOf(TransactionService::class, $txnSvc);
        $this->transactions = $txnSvc;

        $this->seedCode(self::SAMPLE);
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable && isset($this->db)) {
            $this->cleanup();
        }
        if (class_exists(TreasuryPlugin::class, false)) {
            TreasuryPlugin::$requestTreasury = null;
        }
        parent::tearDown();
    }

    private function ensureTables(): void
    {
        $plugin = new TreasuryPlugin();
        $container = new Container();
        $plugin->boot($container);
    }

    private function cleanup(): void
    {
        $this->db->execute(
            "DELETE FROM op_plugin_npictt_treasury_assignments
             WHERE code_full LIKE '99/%'
                OR entity_id IN (900001, 800001, 800002)
                OR code_full LIKE 'zztreas-%'"
        );
        $this->db->execute("DELETE FROM op_plugin_npictt_treasury_codes WHERE code_full LIKE '99/%' OR label LIKE 'zz-treasury-%'");
        $this->db->execute("DELETE FROM op_transactions WHERE trx_id LIKE 'zztreas-%'");
    }

    private function seedCode(string $codeFull): void
    {
        $parts = $this->validator->parseString($codeFull);
        $this->assertNotNull($parts);
        if (!$this->codes->isActive($codeFull, null)) {
            $this->codes->create([
                'merchant_id' => null,
                'head' => $parts['head'],
                'sub_head' => $parts['sub_head'],
                'item' => $parts['item'],
                'sub_item' => $parts['sub_item'],
                'code_full' => $parts['code_full'],
                'head_name' => 'zz-head',
                'sub_head_name' => 'zz-sub-head',
                'item_name' => 'zz-item',
                'sub_item_name' => 'zz-sub-item',
                'label' => 'zz-treasury-seed',
                'status' => 'active',
            ]);
        }
    }

    public function testValidatorRequiresTreasury(): void
    {
        $result = $this->validator->validate([], null);
        $this->assertFalse($result['ok']);
        $this->assertSame(TreasuryValidator::ERR_REQUIRED, $result['code']);
    }

    public function testValidatorRejectsUnknownCode(): void
    {
        $result = $this->validator->validate(['treasury_code' => '99/99/999/99'], null);
        $this->assertFalse($result['ok']);
        $this->assertSame(TreasuryValidator::ERR_INVALID, $result['code']);
    }

    public function testValidatorAcceptsCatalogCode(): void
    {
        $result = $this->validator->validate(['treasury_code' => self::SAMPLE], null);
        $this->assertTrue($result['ok']);
        $this->assertSame(self::SAMPLE, $result['treasury']['code_full']);
    }

    public function testMetadataPayloadIsNumericOnly(): void
    {
        $parts = $this->validator->parseString(self::SAMPLE);
        $this->assertNotNull($parts);
        $payload = $this->validator->metadataPayload($parts);
        $this->assertSame(self::SAMPLE, $payload['treasury_code']);
        $this->assertSame(['head', 'sub_head', 'item', 'sub_item'], array_keys($payload['treasury']));
        $this->assertArrayNotHasKey('head_name', $payload['treasury']);
        $this->assertArrayNotHasKey('label', $payload);
    }

    public function testBuildLabelFromNames(): void
    {
        $label = TreasuryCodeRepository::buildLabel(
            'Ministry of Finance',
            'Goods and Services',
            'General Administration',
            'Travelling and Subsistence'
        );
        $this->assertSame(
            'Ministry of Finance / Goods and Services / General Administration / Travelling and Subsistence',
            $label
        );
    }

    public function testTransactionCreateRejectedWithoutTreasury(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Treasury Code');
        $this->transactions->create(1, [
            'gateway_slug' => 'manual',
            'amount' => '10.00',
            'currency' => 'TTD',
            'method' => 'api',
            'status' => 'pending',
            'trx_id' => 'zztreas-reject-1',
            'metadata' => json_encode(['note' => 'no treasury']),
        ]);
    }

    public function testTransactionRejectExplainsUnmappedPaymentLink(): void
    {
        try {
            $this->transactions->create(1, [
                'gateway_slug' => 'manual',
                'amount' => '10.00',
                'currency' => 'TTD',
                'method' => 'link',
                'status' => 'pending',
                'trx_id' => 'zztreas-reject-link',
                'metadata' => json_encode(['payment_link_id' => 900099]),
            ]);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('payment link', strtolower($e->getMessage()));
            $this->assertStringContainsString('900099', $e->getMessage());
        }
    }

    public function testTransactionInheritsPaymentLinkAssignment(): void
    {
        $this->assignments->upsert(1, 'payment_link', 900001, [
            'head' => '18',
            'sub_head' => '02',
            'item' => '001',
            'sub_item' => '01',
            'code_full' => self::SAMPLE,
        ]);

        $txn = $this->transactions->create(1, [
            'gateway_slug' => 'manual',
            'amount' => '25.00',
            'currency' => 'TTD',
            'method' => 'link',
            'status' => 'pending',
            'trx_id' => 'zztreas-link-1',
            'metadata' => json_encode(['payment_link_id' => 900001]),
        ]);

        $this->assertNotEmpty($txn['id']);
        $assigned = $this->assignments->find('transaction', (int) $txn['id']);
        $this->assertNotNull($assigned);
        $this->assertSame(self::SAMPLE, $assigned['code_full']);
    }

    public function testAssignmentUpsertForInvoice(): void
    {
        $treasury = [
            'head' => '18',
            'sub_head' => '02',
            'item' => '001',
            'sub_item' => '01',
            'code_full' => self::SAMPLE,
        ];
        $this->assignments->upsert(1, 'invoice', 800001, $treasury);
        $found = $this->assignments->find('invoice', 800001);
        $this->assertNotNull($found);
        $this->assertSame(self::SAMPLE, $found['code_full']);
    }

    public function testTransactionInheritsInvoiceAssignment(): void
    {
        $this->assignments->upsert(1, 'invoice', 800002, [
            'head' => '18',
            'sub_head' => '02',
            'item' => '001',
            'sub_item' => '01',
            'code_full' => self::SAMPLE,
        ]);

        $txn = $this->transactions->create(1, [
            'gateway_slug' => 'manual',
            'amount' => '30.00',
            'currency' => 'TTD',
            'method' => 'invoice',
            'status' => 'pending',
            'trx_id' => 'zztreas-inv-1',
            'metadata' => json_encode(['invoice_id' => 800002]),
        ]);

        $assigned = $this->assignments->find('transaction', (int) $txn['id']);
        $this->assertNotNull($assigned);
        $this->assertSame(self::SAMPLE, $assigned['code_full']);
    }

    public function testApiMiddlewareRequiresTreasury(): void
    {
        $container = new Container();
        $container->instance(TreasuryValidator::class, $this->validator);
        $mw = new TreasuryApiMiddleware($container);

        $req = new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/payments'],
            [],
            [],
            json_encode(['amount' => '1.00'], JSON_THROW_ON_ERROR)
        );
        $res = $mw->handle($req, static fn () => Response::json(['ok' => true]));
        $body = json_decode($res->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success'] ?? true);
        $this->assertSame(TreasuryValidator::ERR_REQUIRED, $body['errors'][0]['code'] ?? null);
        $this->assertStringContainsString('Treasury Code', (string) ($body['errors'][0]['message'] ?? ''));
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testApiMiddlewareAcceptsTreasuryInsideMetadata(): void
    {
        $container = new Container();
        $container->instance(TreasuryValidator::class, $this->validator);
        $mw = new TreasuryApiMiddleware($container);

        $req = new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/payments'],
            [],
            [],
            json_encode(['amount' => '1.00', 'metadata' => ['treasury_code' => self::SAMPLE]], JSON_THROW_ON_ERROR)
        );
        $called = false;
        $res = $mw->handle($req, static function () use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($called);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testApiMiddlewareAcceptsValidTreasury(): void
    {
        $container = new Container();
        $container->instance(TreasuryValidator::class, $this->validator);
        $mw = new TreasuryApiMiddleware($container);

        $req = new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/payments'],
            [],
            [],
            json_encode(['amount' => '1.00', 'treasury_code' => self::SAMPLE], JSON_THROW_ON_ERROR)
        );
        $called = false;
        $res = $mw->handle($req, static function () use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($called);
        $this->assertSame(200, $res->getStatusCode());
    }
}
