<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Exception\GraphThrottledException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\GraphApiClient;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GraphApiClient::newestInboxMessageId(), what an Outlook account is asked once
 * a minute in place of a webhook.
 *
 * Graph has no cheap "anything new?" call that leaves the delta chain alone —
 * reading a delta link would be the obvious one, and is the wrong one, because
 * it is the real sync's cursor. The newest message in the inbox is one
 * projected id, and a change in it is the signal.
 *
 * GraphApiClient is final, so it is built for real against a MockHttpClient.
 */
final class GraphApiClientInboxProbeTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testItAsksForTheNewestInboxMessageAndNothingElse(): void
    {
        $client = $this->client($this->json(['value' => [['id' => 'AAA']]]));

        $client->newestInboxMessageId($this->account());

        self::assertCount(1, $this->requests);

        $request = $this->requests[0];

        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);

        self::assertSame('GET', $request['method']);
        self::assertStringEndsWith('/me/mailFolders/inbox/messages', (string) parse_url($request['url'], PHP_URL_PATH));
        self::assertSame('1', (string) $query['$top']);
        self::assertSame('receivedDateTime desc', $query['$orderby']);
        // The id and no body, which is what keeps a minute-by-minute call
        // from being a minute-by-minute download.
        self::assertSame('id', $query['$select']);
    }

    public function testItNeverWaitsLongerThanTheSchedulerCanSpare(): void
    {
        $client = $this->client($this->json(['value' => []]));

        $client->newestInboxMessageId($this->account());

        $timeout = $this->requests[0]['options']['timeout'] ?? null;

        self::assertIsFloat($timeout, 'Without a timeout a stalled connection holds the scheduler for the socket default.');
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(15.0, $timeout);
    }

    public function testItReturnsTheIdOfTheFirstMessage(): void
    {
        $client = $this->client($this->json(['value' => [['id' => 'AAA'], ['id' => 'BBB']]]));

        self::assertSame('AAA', $client->newestInboxMessageId($this->account()));
    }

    public function testAnEmptyInboxIsNull(): void
    {
        $client = $this->client($this->json(['value' => []]));

        self::assertNull($client->newestInboxMessageId($this->account()));
    }

    public function testThrottlingCarriesWhatGraphSaidToWaitFor(): void
    {
        $client = $this->client(new MockResponse('{"error":{"code":"TooManyRequests"}}', [
            'http_code'        => 429,
            'response_headers' => ['content-type' => 'application/json', 'retry-after' => '90'],
        ]));

        try {
            $client->newestInboxMessageId($this->account());
            self::fail('a 429 must not read as an empty inbox');
        } catch (GraphThrottledException $e) {
            self::assertSame(90, $e->getRetryAfterSeconds());
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function client(MockResponse $response): GraphApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('test-token');

        return new GraphApiClient($http, $tokenManager);
    }

    private function account(): Account
    {
        $account = new Account();
        $account->usr = new User();

        return $account;
    }
}
