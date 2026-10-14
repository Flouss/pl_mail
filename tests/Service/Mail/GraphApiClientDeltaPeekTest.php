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
 * GraphApiClient::pendingChanges() and inboxFolderId(): how the probe hears of
 * mail read, unread, starred, moved or deleted somewhere else.
 *
 * newestReceivedAt() cannot. A read on a phone changes no message's received
 * time, and neither does it change lastModifiedDateTime — a message marked
 * unread in Outlook kept its September stamp — so the one thing that does
 * report it is the folder's delta, and the delta link is the real sync's
 * cursor. The comment on newestReceivedAt() is right that moving it would be
 * wrong. It is not moved here: a GET of a stored link answers with what is
 * pending since that link and leaves the chain exactly where it was, so the
 * same GET a few seconds later says the same thing until the sync has run and
 * stored the link it was handed. Nothing in this class stores anything.
 *
 * What comes back is a SIGNAL, not the changes: a string that is empty when
 * nothing is pending and otherwise stable for as long as the same things are
 * pending. InboxProbe announces a signal once, so a sync that is queued but has
 * not run yet is not asked for again on the next tick.
 *
 * GraphApiClient is final, so it is built for real against a MockHttpClient.
 */
final class GraphApiClientDeltaPeekTest extends TestCase
{
    private const string LINK = 'https://graph.microsoft.com/v1.0/me/mailFolders/INBOX/messages/delta?$deltatoken=t1';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testItReadsTheStoredLinkAsItIsAndOnlyOnce(): void
    {
        $client = $this->client($this->page([]));

        $client->pendingChanges($this->account(), self::LINK);

        self::assertCount(1, $this->requests);
        self::assertSame('GET', $this->requests[0]['method']);
        // The link carries every parameter it needs, and the $deltatoken in it
        // is the cursor: asked for as stored, with nothing added to the query.
        self::assertSame(self::LINK, $this->requests[0]['url']);
    }

    public function testItNeverWaitsLongerThanTheSchedulerCanSpare(): void
    {
        $client = $this->client($this->page([]));

        $client->pendingChanges($this->account(), self::LINK);

        $timeout = $this->requests[0]['options']['timeout'] ?? null;

        self::assertIsFloat($timeout, 'Without a timeout a stalled connection holds the scheduler for the socket default.');
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(15.0, $timeout);
    }

    public function testItAsksForASmallPageBecauseOnlyWhetherThereIsAnythingMatters(): void
    {
        $client = $this->client($this->page([]));

        $client->pendingChanges($this->account(), self::LINK);

        $prefer = (string) ($this->requests[0]['options']['normalized_headers']['prefer'][0] ?? '');

        // Both preferences in the one header — a second would replace the
        // immutable-id one, which the ids in the link's own answers depend on.
        self::assertStringContainsString('IdType="ImmutableId"', $prefer);
        self::assertMatchesRegularExpression('/odata\.maxpagesize=\d+/', $prefer);
    }

    public function testNothingPendingIsAnEmptySignal(): void
    {
        $client = $this->client($this->page([]));

        self::assertSame('', $client->pendingChanges($this->account(), self::LINK));
    }

    public function testAMessageReadElsewhereIsASignal(): void
    {
        $client = $this->client($this->page([['id' => 'm1', 'isRead' => true]]));

        self::assertNotSame('', $client->pendingChanges($this->account(), self::LINK));
    }

    /**
     * The point of reading the state and not just the ids: a message toggled
     * back and forth is the same id each time, and each is a different thing to
     * announce.
     */
    public function testTheSameMessageReadAndUnreadAreDifferentSignals(): void
    {
        $account = $this->account();

        $read   = $this->client($this->page([['id' => 'm1', 'isRead' => true]]))->pendingChanges($account, self::LINK);
        $unread = $this->client($this->page([['id' => 'm1', 'isRead' => false]]))->pendingChanges($account, self::LINK);

        self::assertNotSame('', $read);
        self::assertNotSame($read, $unread);
    }

    /**
     * What stops the signal from flickering: Graph does not promise the same
     * order twice, and a signal that changed with it would be announced as often
     * as it reordered — the loop InboxProbe exists not to cause.
     */
    public function testTheSignalDoesNotDependOnTheOrderGraphListsThingsIn(): void
    {
        $account = $this->account();
        $a       = ['id' => 'm1', 'isRead' => false];
        $b       = ['id' => 'm2', 'isRead' => true];

        $one = $this->client($this->page([$a, $b]))->pendingChanges($account, self::LINK);
        $two = $this->client($this->page([$b, $a]))->pendingChanges($account, self::LINK);

        self::assertNotSame('', $one);
        self::assertSame($one, $two);
    }

    public function testAnotherMessageChangingIsAnotherSignal(): void
    {
        $account = $this->account();

        $one = $this->client($this->page([['id' => 'm1', 'isRead' => false]]))->pendingChanges($account, self::LINK);
        $two = $this->client($this->page([['id' => 'm1', 'isRead' => false], ['id' => 'm2', 'isRead' => false]]))->pendingChanges($account, self::LINK);

        self::assertNotSame($one, $two, 'a change that arrived while the first sync waited is news in its own right');
    }

    public function testAMessageRemovedFromTheFolderIsAChangeToo(): void
    {
        $client = $this->client($this->page([['id' => 'm1', '@removed' => ['reason' => 'deleted']]]));

        self::assertNotSame('', $client->pendingChanges($this->account(), self::LINK));
    }

    public function testOnlyTheFirstPageIsReadAndMoreStillCounts(): void
    {
        // A window of changes longer than a page is read by the sync, which has
        // the time. The probe's question is "is there anything?", and the
        // answer to that is on page one.
        $client = $this->client($this->page([['id' => 'm1', 'isRead' => false]], next: 'https://graph.microsoft.com/v1.0/next'));

        $signal = $client->pendingChanges($this->account(), self::LINK);

        self::assertCount(1, $this->requests, 'a page is not followed');
        self::assertNotSame('', $signal);
    }

    public function testMoreThanAPageIsADifferentSignalFromThePageAlone(): void
    {
        $account = $this->account();
        $items   = [['id' => 'm1', 'isRead' => false]];

        $alone = $this->client($this->page($items))->pendingChanges($account, self::LINK);
        $more  = $this->client($this->page($items, next: 'https://graph.microsoft.com/v1.0/next'))->pendingChanges($account, self::LINK);

        self::assertNotSame($alone, $more);
    }

    public function testALinkTheServerNoLongerHonoursIsAStableSignalNotAnError(): void
    {
        // Recovering is the real sync's job (GraphApiSyncer hands the folder back
        // to the import). The probe's part is to start it — once, so the signal
        // must be the same on the next tick and must not be empty.
        $gone = fn (): MockResponse => new MockResponse('{"error":{"code":"syncStateNotFound"}}', [
            'http_code'        => 410,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $one = $this->client($gone())->pendingChanges($this->account(), self::LINK);
        $two = $this->client($gone())->pendingChanges($this->account(), self::LINK);

        self::assertNotSame('', $one);
        self::assertSame($one, $two);
    }

    public function testThrottlingCarriesWhatGraphSaidToWaitFor(): void
    {
        $client = $this->client(new MockResponse('{"error":{"code":"TooManyRequests"}}', [
            'http_code'        => 429,
            'response_headers' => ['content-type' => 'application/json', 'retry-after' => '90'],
        ]));

        try {
            $client->pendingChanges($this->account(), self::LINK);
            self::fail('a 429 must not read as nothing pending');
        } catch (GraphThrottledException $e) {
            self::assertSame(90, $e->getRetryAfterSeconds());
        }
    }

    // ── the Inbox's folder id ─────────────────────────────────────────────

    public function testTheInboxIsAskedForByItsWellKnownNameAndNothingMore(): void
    {
        $client = $this->client($this->json(['id' => 'INBOX']));

        self::assertSame('INBOX', $client->inboxFolderId($this->account()));

        self::assertCount(1, $this->requests);
        self::assertStringEndsWith('/me/mailFolders/inbox', (string) parse_url($this->requests[0]['url'], PHP_URL_PATH));

        parse_str((string) parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);

        self::assertSame('id', $query['$select']);

        $timeout = $this->requests[0]['options']['timeout'] ?? null;

        self::assertIsFloat($timeout);
        self::assertLessThanOrEqual(15.0, $timeout);
    }

    public function testAnInboxWithNoIdIsNull(): void
    {
        self::assertNull($this->client($this->json([]))->inboxFolderId($this->account()));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * One page of a delta answer.
     *
     * @param list<array<string, mixed>> $items
     */
    private function page(array $items, ?string $next = null): MockResponse
    {
        $body = ['value' => $items];

        if (null !== $next) {
            $body['@odata.nextLink'] = $next;
        } else {
            $body['@odata.deltaLink'] = 'https://graph.microsoft.com/v1.0/me/mailFolders/INBOX/messages/delta?$deltatoken=t2';
        }

        return $this->json($body);
    }

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
