<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Customer\CheckoutIdentityService;
use PHPUnit\Framework\TestCase;

final class CheckoutIdentityServiceTest extends TestCase
{
    public function testParsesEmail(): void
    {
        $this->assertSame(['type' => 'email', 'value' => 'jane.doe@gmail.com'], CheckoutIdentityService::parseContact('  Jane.Doe@Gmail.com '));
        $this->assertNull(CheckoutIdentityService::parseContact('jane@'));
        $this->assertNull(CheckoutIdentityService::parseContact(''));
    }

    public function testNormalizesTrinidadPhonesToStoredFormat(): void
    {
        $expected = '+1 868 765 9021';
        foreach (['+1 868 765 9021', '+18687659021', '868-765-9021', '(868) 765 9021', '765-9021', '1 868 765 9021'] as $raw) {
            $this->assertSame(['type' => 'phone', 'value' => $expected], CheckoutIdentityService::parseContact($raw), $raw);
        }
        $this->assertSame('+447700900123', CheckoutIdentityService::normalizePhone('+44 7700 900123'));
        $this->assertNull(CheckoutIdentityService::normalizePhone('12345'));
        $this->assertNull(CheckoutIdentityService::normalizePhone('call me'));
    }

    public function testMasksContacts(): void
    {
        $this->assertSame('k' . str_repeat('*', 14) . '@gmail.com', CheckoutIdentityService::maskContact(['type' => 'email', 'value' => 'keisha.baptiste@gmail.com']));
        $this->assertSame('j***@x.com', CheckoutIdentityService::maskContact(['type' => 'email', 'value' => 'jo@x.com']));
        $this->assertSame('*** *** 9021', CheckoutIdentityService::maskContact(['type' => 'phone', 'value' => '+1 868 765 9021']));
    }

    public function testCodeCheck(): void
    {
        $this->assertTrue(CheckoutIdentityService::checkCode('123456'));
        $this->assertTrue(CheckoutIdentityService::checkCode(' 123456 '));
        $this->assertFalse(CheckoutIdentityService::checkCode('654321'));
        $this->assertFalse(CheckoutIdentityService::checkCode(''));
    }

    public function testStateSanitisesStoredValues(): void
    {
        $this->assertSame(CheckoutIdentityService::fresh(), CheckoutIdentityService::state(null));
        $this->assertSame(CheckoutIdentityService::fresh(), CheckoutIdentityService::state(['stage' => 'bogus']));
        // A code step with no contact cannot continue.
        $this->assertSame('contact', CheckoutIdentityService::state(['stage' => 'code', 'customer_id' => 4])['stage']);

        $state = CheckoutIdentityService::state([
            'stage' => 'code', 'contact' => ['type' => 'email', 'value' => 'a@b.co'], 'customer_id' => 7, 'attempts' => 2,
        ]);
        $this->assertSame('code', $state['stage']);
        $this->assertSame(7, $state['customer_id']);
        $this->assertSame(2, $state['attempts']);
        $this->assertFalse(CheckoutIdentityService::isVerified($state));
        $this->assertTrue(CheckoutIdentityService::isVerified(['stage' => 'verified'] + $state));
    }

    public function testWrongCodeCountsAttemptsThenResets(): void
    {
        $svc = $this->service();
        $state = ['stage' => 'code', 'contact' => ['type' => 'email', 'value' => 'a@b.co'], 'customer_id' => 7, 'pending' => null, 'attempts' => 0];

        for ($i = 1; $i < CheckoutIdentityService::MAX_ATTEMPTS; $i++) {
            [$state, $error] = $svc->handle('verify', 3, 'T1', $state, ['code' => '000000']);
            $this->assertSame('code', $state['stage']);
            $this->assertSame($i, $state['attempts']);
            $this->assertNotNull($error);
        }
        [$state, $error] = $svc->handle('verify', 3, 'T1', $state, ['code' => '000000']);
        $this->assertSame('contact', $state['stage']);
        $this->assertNotNull($error);
    }

    public function testInvalidContactAndSignupValidation(): void
    {
        $svc = $this->service();

        [$state, $error] = $svc->handle('identify', 3, 'T1', CheckoutIdentityService::fresh(), ['contact' => 'nope']);
        $this->assertSame('contact', $state['stage']);
        $this->assertNotNull($error);

        $signup = ['stage' => 'signup', 'contact' => ['type' => 'email', 'value' => 'new@x.co'], 'customer_id' => null, 'pending' => null, 'attempts' => 0];
        $cases = [
            ['first_name' => '', 'last_name' => 'Doe', 'email' => 'new@x.co'],
            ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'bad-email'],
            ['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '12'],
            ['first_name' => 'Jane', 'last_name' => 'Doe'],
        ];
        foreach ($cases as $input) {
            [$next, $error] = $svc->handle('signup', 3, 'T1', $signup, $input);
            $this->assertSame('signup', $next['stage']);
            $this->assertNotNull($error);
            $this->assertSame($input['first_name'], $next['pending']['first_name'] ?? null);
        }
    }

    public function testResetStartsOver(): void
    {
        $state = ['stage' => 'code', 'contact' => ['type' => 'email', 'value' => 'a@b.co'], 'customer_id' => 7, 'pending' => null, 'attempts' => 3];
        $this->assertSame([CheckoutIdentityService::fresh(), null], $this->service()->handle('reset', 3, 'T1', $state, []));
    }

    /**
     * The flows exercised here fail validation before any customer lookup, so no
     * dependencies are needed.
     */
    private function service(): CheckoutIdentityService
    {
        return (new \ReflectionClass(CheckoutIdentityService::class))->newInstanceWithoutConstructor();
    }
}
