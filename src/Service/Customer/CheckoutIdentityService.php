<?php
declare(strict_types=1);

namespace OwnPay\Service\Customer;

use OwnPay\Core\Database;

/**
 * Identifies the payer on a hosted gateway page before payment.
 *
 * The payer enters an email or phone number. A customer of the paying brand is asked for a
 * verification code; an unknown payer signs up (first name, last name, email and/or phone) and
 * then verifies. Once verified, the customer is created if needed and linked to the transaction.
 *
 * Gateways keep the flow state (an "identity" array) in their own transaction metadata and pass
 * it through identify() / signup() / verify(), which return the next state and an optional error.
 *
 * Codes are not delivered: this is a demo flow and the code is always DEMO_CODE.
 *
 * @phpstan-type Contact array{type: 'email'|'phone', value: string}
 * @phpstan-type Pending array{first_name: string, last_name: string, email: string, phone: string}
 * @phpstan-type Identity array{stage: 'contact'|'signup'|'code'|'verified', contact: Contact|null, customer_id: int|null, pending: Pending|null, attempts: int}
 */
final class CheckoutIdentityService
{
    public const DEMO_CODE = '123456';
    public const MAX_ATTEMPTS = 5;

    /** Form fields read by handle(). */
    public const INPUT_FIELDS = ['contact', 'first_name', 'last_name', 'email', 'phone', 'code'];

    public function __construct(
        private readonly CustomerPiiService $customers,
        private readonly Database $db
    ) {
    }

    /**
     * @return Contact|null
     */
    public static function parseContact(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, '@')) {
            $email = strtolower($raw);
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 190
                ? ['type' => 'email', 'value' => $email]
                : null;
        }
        $phone = self::normalizePhone($raw);
        return $phone !== null ? ['type' => 'phone', 'value' => $phone] : null;
    }

    /**
     * Canonical phone format, matching how customer phones are stored and hashed:
     * North American numbers as "+1 868 765 9021", others as "+<digits>".
     * A 7-digit local number is treated as Trinidad and Tobago (+1 868).
     */
    public static function normalizePhone(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^\+?[\d\s().-]+$/', $raw)) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 7) {
            $digits = '1868' . $digits;
        } elseif (strlen($digits) === 10 && !str_starts_with($raw, '+')) {
            $digits = '1' . $digits;
        }
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+1 ' . substr($digits, 1, 3) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
        }
        return '+' . $digits;
    }

    /**
     * @param Contact $contact
     */
    public static function maskContact(array $contact): string
    {
        if ($contact['type'] === 'email') {
            [$local, $domain] = array_pad(explode('@', $contact['value'], 2), 2, '');
            return mb_substr($local, 0, 1) . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
        }
        $digits = (string) preg_replace('/\D/', '', $contact['value']);
        return '*** *** ' . substr($digits, -4);
    }

    public static function checkCode(string $code): bool
    {
        return hash_equals(self::DEMO_CODE, trim($code));
    }

    /**
     * @param Contact $contact
     * @return array<string, mixed>|null
     */
    public function findCustomer(int $merchantId, array $contact): ?array
    {
        return $contact['type'] === 'email'
            ? $this->customers->findByEmail($merchantId, $contact['value'])
            : $this->customers->findByPhone($merchantId, $contact['value']);
    }

    /**
     * @return array<string, mixed>
     */
    public function createCustomer(int $merchantId, string $first, string $last, ?string $email, ?string $phone): array
    {
        $data = [
            'name'     => trim($first . ' ' . $last),
            'metadata' => (string) json_encode(['first_name' => $first, 'last_name' => $last, 'source' => 'checkout']),
        ];
        if ($email !== null && $email !== '') {
            $data['email'] = $email;
        }
        if ($phone !== null && $phone !== '') {
            $data['phone'] = $phone;
        }
        $this->customers->create($merchantId, $data);

        $created = $this->findCustomer($merchantId, isset($data['email'])
            ? ['type' => 'email', 'value' => $data['email']]
            : ['type' => 'phone', 'value' => (string) $phone]);
        if ($created === null) {
            throw new \RuntimeException('Customer could not be created.');
        }
        return $created;
    }

    /**
     * Links the customer to the transaction and records their details in the metadata keys
     * the API checkout flow uses.
     *
     * @param array<string, mixed> $customer Decrypted customer record.
     */
    public function attachToTransaction(string $trxId, int $customerId, array $customer): void
    {
        $sets = [];
        $params = ['trx' => $trxId, 'cid' => $customerId];
        foreach (['name', 'email', 'phone'] as $field) {
            $value = $customer[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $sets[] = "'$.customer_{$field}', :{$field}";
                $params[$field] = $value;
            }
        }
        $metadata = $sets === []
            ? 'COALESCE(metadata, JSON_OBJECT())'
            : 'JSON_SET(COALESCE(metadata, JSON_OBJECT()), ' . implode(', ', $sets) . ')';

        $this->db->update(
            "UPDATE op_transactions SET customer_id = :cid, metadata = {$metadata} WHERE trx_id = :trx",
            $params
        );
    }

    /**
     * Rebuilds a well-formed identity state from stored metadata.
     *
     * @return Identity
     */
    public static function state(mixed $raw): array
    {
        $state = self::fresh();
        if (!is_array($raw)) {
            return $state;
        }
        if (in_array($raw['stage'] ?? null, ['contact', 'signup', 'code', 'verified'], true)) {
            $state['stage'] = $raw['stage'];
        }
        $contact = $raw['contact'] ?? null;
        if (is_array($contact) && in_array($contact['type'] ?? null, ['email', 'phone'], true) && is_string($contact['value'] ?? null)) {
            $state['contact'] = ['type' => $contact['type'], 'value' => $contact['value']];
        }
        $cid = $raw['customer_id'] ?? null;
        $state['customer_id'] = is_int($cid) && $cid > 0 ? $cid : null;
        $pending = $raw['pending'] ?? null;
        if (is_array($pending)) {
            $state['pending'] = [
                'first_name' => is_string($pending['first_name'] ?? null) ? $pending['first_name'] : '',
                'last_name'  => is_string($pending['last_name'] ?? null) ? $pending['last_name'] : '',
                'email'      => is_string($pending['email'] ?? null) ? $pending['email'] : '',
                'phone'      => is_string($pending['phone'] ?? null) ? $pending['phone'] : '',
            ];
        }
        $attempts = $raw['attempts'] ?? 0;
        $state['attempts'] = is_int($attempts) ? $attempts : 0;

        // A state that cannot continue falls back to the start.
        if (($state['stage'] === 'code' || $state['stage'] === 'signup') && $state['contact'] === null) {
            return self::fresh();
        }
        if ($state['stage'] === 'code' && $state['customer_id'] === null && $state['pending'] === null) {
            return self::fresh();
        }
        return $state;
    }

    /**
     * @return Identity
     */
    public static function fresh(): array
    {
        return ['stage' => 'contact', 'contact' => null, 'customer_id' => null, 'pending' => null, 'attempts' => 0];
    }

    /**
     * @param Identity $state
     */
    public static function isVerified(array $state): bool
    {
        return $state['stage'] === 'verified';
    }

    /**
     * Runs one step of the flow.
     *
     * @param 'identify'|'signup'|'verify'|'reset' $action
     * @param Identity $state
     * @param array<string, string> $input Posted form fields.
     * @return array{0: Identity, 1: string|null} The next state and a payer-facing error, if any.
     */
    public function handle(string $action, int $merchantId, string $trxId, array $state, array $input): array
    {
        return match ($action) {
            'identify' => $this->identify($merchantId, $input['contact'] ?? ''),
            'signup'   => $this->signup($merchantId, $state, $input),
            'verify'   => $this->verify($merchantId, $trxId, $state, $input['code'] ?? ''),
            'reset'    => [self::fresh(), null],
        };
    }

    /**
     * @return array{0: Identity, 1: string|null}
     */
    private function identify(int $merchantId, string $raw): array
    {
        $contact = self::parseContact($raw);
        if ($contact === null) {
            return [self::fresh(), 'Enter a valid email address or mobile number.'];
        }

        $customer = $this->findCustomer($merchantId, $contact);
        $id = $customer !== null && is_numeric($customer['id'] ?? null) ? (int) $customer['id'] : null;
        if ($id !== null) {
            return [['stage' => 'code', 'contact' => $contact, 'customer_id' => $id, 'pending' => null, 'attempts' => 0], null];
        }
        return [['stage' => 'signup', 'contact' => $contact, 'customer_id' => null, 'pending' => null, 'attempts' => 0], null];
    }

    /**
     * @param Identity $state
     * @param array<string, string> $input
     * @return array{0: Identity, 1: string|null}
     */
    private function signup(int $merchantId, array $state, array $input): array
    {
        if ($state['stage'] !== 'signup' || $state['contact'] === null) {
            return [self::fresh(), null];
        }

        $first = self::cleanName($input['first_name'] ?? '');
        $last = self::cleanName($input['last_name'] ?? '');
        $emailRaw = trim($input['email'] ?? '');
        $phoneRaw = trim($input['phone'] ?? '');
        $pending = ['first_name' => $first, 'last_name' => $last, 'email' => $emailRaw, 'phone' => $phoneRaw];
        $retry = ['pending' => $pending] + $state;

        if ($first === '' || $last === '') {
            return [$retry, 'Enter your first and last name.'];
        }
        $email = $emailRaw !== '' ? self::parseContact($emailRaw) : null;
        if ($emailRaw !== '' && ($email === null || $email['type'] !== 'email')) {
            return [$retry, 'Enter a valid email address.'];
        }
        $phone = $phoneRaw !== '' ? self::normalizePhone($phoneRaw) : null;
        if ($phoneRaw !== '' && $phone === null) {
            return [$retry, 'Enter a valid mobile number.'];
        }
        if ($email === null && $phone === null) {
            return [$retry, 'Enter an email address or a mobile number.'];
        }

        $contacts = [];
        if ($email !== null) {
            $contacts[] = $email;
        }
        if ($phone !== null) {
            $contacts[] = ['type' => 'phone', 'value' => $phone];
        }
        foreach ($contacts as $contact) {
            $existing = $this->findCustomer($merchantId, $contact);
            if ($existing !== null && is_numeric($existing['id'] ?? null)) {
                return [['stage' => 'code', 'contact' => $contact, 'customer_id' => (int) $existing['id'], 'pending' => null, 'attempts' => 0], null];
            }
        }

        // Send the code to what the payer first entered when it is still on the form.
        $send = $contacts[0];
        foreach ($contacts as $contact) {
            if ($contact['type'] === $state['contact']['type']) {
                $send = $contact;
            }
        }

        $pending = [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email['value'] ?? '',
            'phone'      => $phone ?? '',
        ];
        return [['stage' => 'code', 'contact' => $send, 'customer_id' => null, 'pending' => $pending, 'attempts' => 0], null];
    }

    /**
     * @param Identity $state
     * @return array{0: Identity, 1: string|null}
     */
    private function verify(int $merchantId, string $trxId, array $state, string $code): array
    {
        if ($state['stage'] !== 'code') {
            return [$state, null];
        }
        if (!self::checkCode($code)) {
            $state['attempts']++;
            if ($state['attempts'] >= self::MAX_ATTEMPTS) {
                return [self::fresh(), 'Too many incorrect codes. Enter your email or mobile number to start again.'];
            }
            return [$state, 'That code is not correct. Check the code and try again.'];
        }

        if ($state['customer_id'] !== null) {
            $customer = $this->customers->get($merchantId, $state['customer_id']);
        } else {
            $p = $state['pending'] ?? ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
            try {
                $customer = $this->createCustomer($merchantId, $p['first_name'], $p['last_name'], $p['email'], $p['phone']);
            } catch (\Throwable) {
                // Created by a parallel signup with the same details: use that record.
                $customer = $state['contact'] !== null ? $this->findCustomer($merchantId, $state['contact']) : null;
            }
        }
        $id = $customer !== null && is_numeric($customer['id'] ?? null) ? (int) $customer['id'] : 0;
        if ($customer === null || $id <= 0) {
            return [self::fresh(), 'We could not find your account. Enter your email or mobile number to start again.'];
        }

        $this->attachToTransaction($trxId, $id, $customer);
        return [['stage' => 'verified', 'contact' => $state['contact'], 'customer_id' => $id, 'pending' => null, 'attempts' => 0], null];
    }

    /**
     * Template variables for the identify partial.
     *
     * @param Identity $state
     * @return array<string, mixed>
     */
    public function viewVars(int $merchantId, array $state, ?int $transactionCustomerId = null): array
    {
        $contact = $state['contact'];
        $prefill = $contact['value'] ?? '';
        if ($prefill === '' && $transactionCustomerId !== null && $transactionCustomerId > 0) {
            $known = $this->customers->get($merchantId, $transactionCustomerId);
            $email = is_string($known['email'] ?? null) ? $known['email'] : '';
            $phone = is_string($known['phone'] ?? null) ? $known['phone'] : '';
            $prefill = $email !== '' ? $email : $phone;
        }
        $pending = $state['pending'] ?? ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];

        return [
            'stage'   => $state['stage'],
            'channel' => ($contact['type'] ?? 'email') === 'phone' ? 'sms' : 'email',
            'masked'  => $contact !== null ? self::maskContact($contact) : '',
            'prefill' => $prefill,
            'first_name' => $pending['first_name'],
            'last_name'  => $pending['last_name'],
            'email'   => $pending['email'] !== '' ? $pending['email'] : (($contact['type'] ?? '') === 'email' ? $prefill : ''),
            'phone'   => $pending['phone'] !== '' ? $pending['phone'] : (($contact['type'] ?? '') === 'phone' ? $prefill : ''),
        ];
    }

    private static function cleanName(string $raw): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($name === '' || mb_strlen($name) > 60 || !preg_match("/^[\p{L}][\p{L} '.-]*$/u", $name)) {
            return '';
        }
        return $name;
    }
}
