<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Infrastructure\Payments;

use LemurAse\Infrastructure\Payments\StripeAdapter;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

/** Customer lookup must use the exact email filter, never the Search query language. */
final class StripeCustomerLookupTest extends TestCase
{
    private RecordingStripeClient $client;

    protected function setUp(): void
    {
        Stripe::setApiKey('sk_test_offline');
        $this->client = new RecordingStripeClient();
        ApiRequestor::setHttpClient($this->client);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function testReusesExistingCustomerFoundByExactEmail(): void
    {
        $this->client->responses = [['object' => 'list', 'data' => [['id' => 'cus_existing', 'object' => 'customer']]]];

        $this->assertSame('cus_existing', StripeAdapter::findOrCreateCustomer('ana@example.com', 'Ana', 'user-1'));
        [$method, $url, $params] = $this->client->requests[0];
        $this->assertSame('get', $method);
        $this->assertStringContainsString('/v1/customers', $url);
        $this->assertStringNotContainsString('/search', $url);
        $this->assertSame('ana@example.com', $params['email']);
        $this->assertCount(1, $this->client->requests, 'No customer is created when one exists');
    }

    public function testCraftedEmailIsSentAsALiteralFilterValue(): void
    {
        $email = "x'@example.com";
        $this->client->responses = [
            ['object' => 'list', 'data' => []],
            ['id' => 'cus_new', 'object' => 'customer'],
        ];

        $this->assertSame('cus_new', StripeAdapter::findOrCreateCustomer($email, 'X', 'user-2'));
        $this->assertSame($email, $this->client->requests[0][2]['email']);
        $this->assertStringNotContainsString('query', json_encode($this->client->requests[0]));
        [$method, $url, $params] = $this->client->requests[1];
        $this->assertSame('post', $method);
        $this->assertSame($email, $params['email']);
        $this->assertSame('user-2', $params['metadata']['user_id']);
    }

    public function testRejectsInvalidEmailWithoutCallingStripe(): void
    {
        foreach (['', "a' OR email:'victim@example.com"] as $email) {
            try {
                StripeAdapter::findOrCreateCustomer($email, 'X', 'user-3');
                $this->fail('Invalid email accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->client->requests);
    }
}

final class RecordingStripeClient implements ClientInterface
{
    /** @var list<array{0: string, 1: string, 2: array}> */
    public array $requests = [];
    /** @var list<array> */
    public array $responses = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $query = [];
        parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
        $this->requests[] = [strtolower((string) $method), (string) $absUrl, $params ?: $query];
        $body = array_shift($this->responses) ?? ['object' => 'list', 'data' => []];
        return [json_encode($body), 200, ['request-id' => 'req_test']];
    }
}
