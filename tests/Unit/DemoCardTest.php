<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Core\Database;
use OwnPay\Gateway\Demo\DemoCard;
use OwnPay\Gateway\Demo\PrepaidCodeStore;
use PHPUnit\Framework\TestCase;

final class DemoCardTest extends TestCase
{
    public function testLuhn(): void
    {
        $this->assertTrue(DemoCard::luhn('4242424242424242'));
        $this->assertTrue(DemoCard::luhn('5555555555554444'));
        $this->assertFalse(DemoCard::luhn('4242424242424241'));
        $this->assertFalse(DemoCard::luhn('42424242'));
        $this->assertFalse(DemoCard::luhn('4242-4242-4242-4242'));
    }

    public function testOutcomesAndThreeDs(): void
    {
        $this->assertSame('approved', DemoCard::outcome('4242424242424242', 'test_cards'));
        $this->assertSame('declined', DemoCard::outcome('4000000000000002', 'test_cards'));
        $this->assertSame('challenge', DemoCard::outcome('4000000000003220', 'test_cards'));
        $this->assertSame('challenge', DemoCard::outcome('4242424242424242', 'always'));
        $this->assertSame('declined', DemoCard::outcome('4000000000000002', 'always'));
        $this->assertSame('approved', DemoCard::outcome('4000000000003220', 'never'));
    }

    public function testBrand(): void
    {
        $this->assertSame('Visa', DemoCard::brand('4242424242424242'));
        $this->assertSame('Mastercard', DemoCard::brand('5555555555554444'));
        $this->assertSame('Mastercard', DemoCard::brand('2223003122003222'));
        $this->assertSame('Amex', DemoCard::brand('378282246310005'));
        $this->assertSame('Card', DemoCard::brand('6011111111111117'));
    }

    public function testValidate(): void
    {
        $now = (int) strtotime('2026-10-14');
        $this->assertNull(DemoCard::validate('Jane Doe', '4242424242424242', '10/26', '123', $now));
        $this->assertSame('Enter the name on the card.', DemoCard::validate('', '4242424242424242', '12/30', '123', $now));
        $this->assertSame('The card number is not valid.', DemoCard::validate('Jane', '4242424242424241', '12/30', '123', $now));
        $this->assertSame('Enter the expiry date as MM/YY.', DemoCard::validate('Jane', '4242424242424242', '13/30', '123', $now));
        $this->assertSame('The card expiry date is in the past.', DemoCard::validate('Jane', '4242424242424242', '09/26', '123', $now));
        $this->assertSame('Enter the 3 or 4 digit security code.', DemoCard::validate('Jane', '4242424242424242', '12/30', '12', $now));
    }

    public function testSignatureCoversEveryField(): void
    {
        $sig = DemoCard::sign('T1', 'gp_x', 'approved', '10.00', 'key');
        $this->assertSame(64, strlen($sig));
        $this->assertNotSame($sig, DemoCard::sign('T1', 'gp_x', 'approved', '10.01', 'key'));
        $this->assertNotSame($sig, DemoCard::sign('T1', 'gp_x', 'declined', '10.00', 'key'));
        $this->assertNotSame($sig, DemoCard::sign('T2', 'gp_x', 'approved', '10.00', 'key'));
        $this->assertNotSame($sig, DemoCard::sign('T1', 'gp_x', 'approved', '10.00', 'other'));
    }

    public function testMessages(): void
    {
        $this->assertSame('Insufficient funds on the card.', DemoCard::message('insufficient_funds'));
        $this->assertSame('Payment was not completed.', DemoCard::message('nonsense'));
    }

    public function testPrepaidAllocationAcrossCodes(): void
    {
        $first = PrepaidCodeStore::allocate('100.00', '250.00');
        $this->assertSame('100.00', $first);
        $due = PrepaidCodeStore::remaining('250.00', [['amount' => $first]]);
        $this->assertSame('150.00', $due);
        $this->assertSame('150.00', PrepaidCodeStore::allocate('500.00', $due));
        $this->assertSame('0.00', PrepaidCodeStore::remaining('250.00', [['amount' => '100.00'], ['amount' => '150.00']]));

        $applied = [
            ['code_id' => 1, 'code' => 'A', 'channel' => 'voucher', 'amount' => '100.00', 'balance' => '100.00'],
            ['code_id' => 2, 'code' => 'B', 'channel' => 'sms', 'amount' => '150.00', 'balance' => '500.00'],
        ];
        $out = PrepaidCodeStore::reallocate('250.00', [$applied[1]]);
        $this->assertSame('250.00', $out[0]['amount']);
    }

    public function testPrepaidRejectReasons(): void
    {
        $now = (int) strtotime('2026-10-14 12:00:00');
        $ok = ['status' => 'active', 'currency' => 'TTD', 'balance' => '100.00', 'expires_at' => null];
        $this->assertNull(PrepaidCodeStore::rejectReason($ok, 'TTD', $now));
        $this->assertSame('That code is not valid.', PrepaidCodeStore::rejectReason(null, 'TTD', $now));
        $this->assertNotNull(PrepaidCodeStore::rejectReason(['status' => 'void'] + $ok, 'TTD', $now));
        $this->assertNotNull(PrepaidCodeStore::rejectReason(['expires_at' => '2026-10-13 23:59:59'] + $ok, 'TTD', $now));
        $this->assertNotNull(PrepaidCodeStore::rejectReason($ok, 'USD', $now));
        $this->assertNotNull(PrepaidCodeStore::rejectReason(['balance' => '0.00'] + $ok, 'TTD', $now));
    }

    public function testPrepaidCodeFormat(): void
    {
        $this->assertSame('GOV-QR-0250', PrepaidCodeStore::normalize(' gov-qr-0250 '));
        $this->assertMatchesRegularExpression('/^CP-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', PrepaidCodeStore::generateCode('cp'));
    }

    public function testPrepaidStoreRequiresPluginTables(): void
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $this->expectException(\InvalidArgumentException::class);
        new PrepaidCodeStore($db, 'op_transactions');
    }
}
