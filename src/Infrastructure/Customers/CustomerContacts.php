<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Customers;

/**
 * Contact details of the customer behind an order (external_client_id), supplied by the
 * host application. Gateways that require them (Paystack needs an email to start a
 * payment; Razorpay uses them to prefill checkout) read them from here.
 *
 *   CustomerContacts::resolveUsing(fn (string $clientId) => ['email' => ..., 'name' => ...]);
 */
final class CustomerContacts
{
    /** @var (callable(string): ?array)|null */
    private static $resolver = null;

    private function __construct() {}

    /** @param (callable(string): ?array{email?: string, name?: string})|null $resolver */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** @return array{email: string, name: string}|null */
    public static function find(string $externalClientId): ?array
    {
        if (self::$resolver === null || $externalClientId === '') {
            return null;
        }
        $contact = (self::$resolver)($externalClientId);
        if (!is_array($contact)) {
            return null;
        }
        $email = trim((string) ($contact['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return ['email' => $email, 'name' => trim((string) ($contact['name'] ?? ''))];
    }

    /**
     * @return array{email: string, name: string}
     * @throws \RuntimeException when the host cannot provide a valid email.
     */
    public static function require(string $externalClientId, string $gateway): array
    {
        return self::find($externalClientId) ?? throw new \RuntimeException(
            "{$gateway}: no email for customer {$externalClientId}. "
            .'Register CustomerContacts::resolveUsing() in the host application.'
        );
    }
}
