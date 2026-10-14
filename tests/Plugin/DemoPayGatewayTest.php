<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/npictt-card/DemoPayGateway.php';

use OwnPay\Modules\Gateways\Demopay\DemoPayGateway;

final class DemoPayGatewayTest extends TestCase
{
    private const SECRET = 'sk_demo_test_secret';

    /**
     * @return array<string, string>
     */
    private function signed(string $outcome = 'approved', string $amount = '250.00'): array
    {
        return [
            'trx_id'    => 'TRX123',
            'session'   => 'cs_abc',
            'outcome'   => $outcome,
            'amount'    => $amount,
            'signature' => DemoPayGateway::sign('TRX123', 'cs_abc', $outcome, $amount, self::SECRET),
        ];
    }

    public function testLuhn(): void
    {
        $this->assertTrue(DemoPayGateway::luhn('4242424242424242'));
        $this->assertTrue(DemoPayGateway::luhn('4000000000003220'));
        $this->assertFalse(DemoPayGateway::luhn('4242424242424241'));
        $this->assertFalse(DemoPayGateway::luhn('1234'));
    }

    public function testTestCardOutcomes(): void
    {
        $this->assertSame('approved', DemoPayGateway::cardOutcome('4242424242424242', 'test_cards'));
        $this->assertSame('declined', DemoPayGateway::cardOutcome('4000000000000002', 'test_cards'));
        $this->assertSame('insufficient_funds', DemoPayGateway::cardOutcome('4000000000009995', 'test_cards'));
        $this->assertSame('expired_card', DemoPayGateway::cardOutcome('4000000000000069', 'test_cards'));
        $this->assertSame('challenge', DemoPayGateway::cardOutcome('4000000000003220', 'test_cards'));
        $this->assertSame('approved', DemoPayGateway::cardOutcome('5555555555554444', 'test_cards'));
    }

    public function testThreeDsSetting(): void
    {
        $this->assertSame('challenge', DemoPayGateway::cardOutcome('4242424242424242', 'always'));
        $this->assertSame('declined', DemoPayGateway::cardOutcome('4000000000000002', 'always'));
        $this->assertSame('approved', DemoPayGateway::cardOutcome('4000000000003220', 'never'));
    }

    public function testCardValidation(): void
    {
        $now = (int) strtotime('2026-10-13');
        $this->assertNull(DemoPayGateway::validateCard('Jane Doe', '4242424242424242', '12/30', '123', $now));
        $this->assertNull(DemoPayGateway::validateCard('Jane Doe', '4242424242424242', '10/26', '123', $now));
        $this->assertNotNull(DemoPayGateway::validateCard('', '4242424242424242', '12/30', '123', $now));
        $this->assertNotNull(DemoPayGateway::validateCard('Jane Doe', '4242424242424241', '12/30', '123', $now));
        $this->assertNotNull(DemoPayGateway::validateCard('Jane Doe', '4242424242424242', '09/26', '123', $now));
        $this->assertNotNull(DemoPayGateway::validateCard('Jane Doe', '4242424242424242', '13/30', '123', $now));
        $this->assertNotNull(DemoPayGateway::validateCard('Jane Doe', '4242424242424242', '12/30', '12', $now));
    }

    public function testVerifyAcceptsValidSignature(): void
    {
        $result = (new DemoPayGateway())->verify($this->signed(), ['secret_key' => self::SECRET]);

        $this->assertTrue($result['success']);
        $this->assertSame('250.00', $result['amount'] ?? null);
        $this->assertSame('cs_abc', $result['gateway_trx_id'] ?? null);
    }

    public function testVerifyRejectsTamperedAmount(): void
    {
        $data = $this->signed();
        $data['amount'] = '1.00';
        $this->assertFalse((new DemoPayGateway())->verify($data, ['secret_key' => self::SECRET])['success']);
    }

    public function testVerifyRejectsTamperedOutcome(): void
    {
        $data = $this->signed('declined');
        $data['outcome'] = 'approved';
        $this->assertFalse((new DemoPayGateway())->verify($data, ['secret_key' => self::SECRET])['success']);
    }

    public function testVerifyRejectsWrongKey(): void
    {
        $this->assertFalse((new DemoPayGateway())->verify($this->signed(), ['secret_key' => 'sk_demo_other'])['success']);
        $this->assertFalse((new DemoPayGateway())->verify($this->signed(), [])['success']);
    }

    public function testVerifyFailsForSignedDecline(): void
    {
        $result = (new DemoPayGateway())->verify($this->signed('insufficient_funds'), ['secret_key' => self::SECRET]);
        $this->assertFalse($result['success']);
    }

    public function testInitiateRefusesWhenDemoDisabled(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DemoPayGateway())->initiate(
            ['amount' => '10.00', 'currency' => 'TTD', 'trx_id' => 'T1', 'redirect_url' => 'https://x.test/checkout/T1/status', 'cancel_url' => 'https://x.test/checkout/T1/status'],
            ['demo_enabled' => 'no', 'secret_key' => self::SECRET]
        );
    }

    public function testRefund(): void
    {
        $gw = new DemoPayGateway();
        $this->assertTrue($gw->supports('refund'));

        $ok = $gw->refund('cs_abc', '10.00', ['demo_enabled' => 'yes']);
        $this->assertTrue($ok['success']);
        $this->assertStringStartsWith('re_', $ok['refund_id'] ?? '');

        $this->assertFalse($gw->refund('cs_abc', '10.00', ['demo_enabled' => 'no'])['success']);
    }

    public function testCardEntryRequiresVerifiedPayer(): void
    {
        $this->assertFalse(DemoPayGateway::identityVerified(['stage' => 'card']));
        $this->assertFalse(DemoPayGateway::identityVerified(['stage' => 'card', 'identity' => ['stage' => 'signup', 'contact' => ['type' => 'phone', 'value' => '+1 868 765 9021']]]));
        $this->assertTrue(DemoPayGateway::identityVerified(['stage' => 'card', 'identity' => ['stage' => 'verified']]));
    }
}
