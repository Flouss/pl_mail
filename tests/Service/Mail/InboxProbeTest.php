<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\OAuthGrantRevokedException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\InboxProbe;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Whether the periodic look at an OAuth account has found mail worth a
 * sync — and, as much, when it must say "no" or must not look at all.
 *
 * With no public address there is no push, so this stands in for it. A stand-in
 * that runs every 30 seconds has one way to do real damage, and it is the one this
 * codebase has already paid for once (ImapIdleCommand::SYNC_MIN_GAP): a signal
 * that stays true until the sync it triggered has finished queues another sync
 * on the next tick, and the next. Gmail's cursor only moves when the real sync
 * runs, which sits behind a single shared queue worker, and an Outlook signal
 * that can flicker is announced as often as it does. So most of the tests below
 * are about the same arrival being announced once.
 *
 * Both API clients are final and are built for real against one MockHttpClient
 * that routes by host; the clock is a MockClock so a back-off can be waited out
 * without waiting.
 */
final class InboxProbeTest extends TestCase
{
    private const string GMAIL_HOST = 'gmail.googleapis.com';
    private const string GRAPH_HOST = 'graph.microsoft.com';

    /** Two moments a second apart, as Graph spells a receivedDateTime. */
    private const string T1 = '2026-10-10T12:00:05Z';
    private const string T2 = '2026-10-10T12:00:06Z';

    /** @var array<string, list<ResponseInterface>> host => answers, in order */
    private array $script = [];

    /** @var list<string> the host and bearer token of every request made, in order */
    private array $requests = [];

    /**
     * Answers to the Gmail probe's SECOND question — has anything been read,
     * unread or starred? — which is the history call with no `historyTypes`.
     * Quiet when empty, so the tests above, which are about arrivals and script
     * one answer per tick, do not have to know there is a second question.
     *
     * @var list<ResponseInterface>
     */
    private array $stateScript = [];

    /** @var list<string> every URL asked for, in order, both questions included */
    private array $urls = [];

    private MockClock $clock;

    private InboxProbe $probe;

    protected function setUp(): void
    {
        $this->script      = [self::GMAIL_HOST => [], self::GRAPH_HOST => []];
        $this->stateScript = [];
        $this->urls        = [];
        $this->requests    = [];
        $this->clock    = new MockClock('2026-10-10 12:00:00');
        $this->probe    = $this->probe();
    }

    // ── Gmail ─────────────────────────────────────────────────────────────

    public function testAGmailAccountWithoutACursorIsLeftToTheScheduledSync(): void
    {
        // No historyId means the first sync has not finished, and that sync is
        // not this class's to start.
        self::assertFalse($this->probe->shouldSync($this->gmail(1, historyId: null)));
        self::assertSame([], $this->requests);
    }

    public function testNothingNewIsNoSync(): void
    {
        $this->script[self::GMAIL_HOST][] = $this->gmailQuiet();

        self::assertFalse($this->probe->shouldSync($this->gmail(1)));
        self::assertCount(1, $this->requests, 'It did look; there was nothing there.');
    }

    public function testNewInboxMailIsASync(): void
    {
        $this->script[self::GMAIL_HOST][] = $this->gmailArrival('705');

        self::assertTrue($this->probe->shouldSync($this->gmail(1)));
    }

    public function testTheSameArrivalIsAnnouncedOnce(): void
    {
        // The cursor only moves when the queued sync has actually run, and the
        // queue can be minutes deep. Until then the next tick sees the very
        // same arrival, and must not queue another sync for it.
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->gmailArrival('705'), $this->gmailArrival('705'), $this->gmailArrival('705')];

        self::assertTrue($this->probe->shouldSync($account), 'the first sight is news');
        self::assertFalse($this->probe->shouldSync($account), 'the second is the same news');
        self::assertFalse($this->probe->shouldSync($account), 'and so is the third');
    }

    public function testLaterMailIsAnotherSync(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->gmailArrival('705'), $this->gmailArrival('712')];

        self::assertTrue($this->probe->shouldSync($account));
        self::assertTrue($this->probe->shouldSync($account), 'mail that arrived while the first sync waited is new');
    }

    public function testSpamIsASyncToo(): void
    {
        // It used to wait for the quarter-hourly sweep: the probe asked about the
        // inbox, and Gmail files spam under SPAM.
        $this->script[self::GMAIL_HOST][] = $this->gmailArrival('705', ['SPAM', 'UNREAD']);

        self::assertTrue($this->probe->shouldSync($this->gmail(1)));
    }

    public function testADraftBeingWrittenIsNeverASync(): void
    {
        // Each save of a Gmail draft is a new message with a new history record.
        // Announced, that is a full sync every few seconds for as long as
        // somebody types.
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [
            $this->gmailArrival('705', ['DRAFT']),
            $this->gmailArrival('706', ['DRAFT']),
            // The presence companion: the same account does announce mail.
            $this->gmailArrival('707', ['SPAM', 'UNREAD']),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'a draft');
        self::assertFalse($this->probe->shouldSync($account), 'and the next save of it');
        self::assertTrue($this->probe->shouldSync($account), 'but mail is mail');
    }

    public function testTheCursorBelongsToTheRealSyncAndIsNeverMoved(): void
    {
        // If the probe advanced it, the sync it just asked for would find the
        // window empty and fetch nothing.
        $account = $this->gmail(1, historyId: '700');

        $this->script[self::GMAIL_HOST][] = $this->gmailArrival('705');

        self::assertTrue($this->probe->shouldSync($account));
        self::assertSame('700', $account->gmailHistoryId);
    }

    public function testAnExpiredCursorIsOneSyncToRecoverItAndThenQuiet(): void
    {
        // Recovery is the real sync's job (GmailApiSyncer::syncIncremental
        // answers 404 by re-listing the mailbox). The probe's only part is to
        // start it — once, and not on every tick until it has run.
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->httpStatus(404), $this->httpStatus(404)];

        self::assertTrue($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
    }

    public function testThrottlingPausesTheAccountForAsLongAsGoogleAsked(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->throttled(retryAfter: 120), $this->gmailArrival('705')];

        self::assertFalse($this->probe->shouldSync($account), 'a refusal is not news');
        self::assertCount(1, $this->requests);

        $this->clock->sleep(119);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(1, $this->requests, 'Asking again inside the window is what a rate limit punishes.');

        $this->clock->sleep(2);
        self::assertTrue($this->probe->shouldSync($account), 'and the window does end');
        self::assertCount(2, $this->requests);
    }

    public function testAnyOtherFailurePausesTheAccountForFiveMinutes(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->httpStatus(500), $this->gmailArrival('705')];

        self::assertFalse($this->probe->shouldSync($account));

        $this->clock->sleep(299);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(1, $this->requests);

        $this->clock->sleep(2);
        self::assertTrue($this->probe->shouldSync($account));
        self::assertCount(2, $this->requests);
    }

    public function testARevokedGrantPausesTheAccountToo(): void
    {
        // The token manager has already recorded the revocation on the account;
        // what is left for the probe is not to try again a minute later, and a
        // minute after that, against an identity endpoint.
        $probe = $this->probe(tokenFailure: new OAuthGrantRevokedException('revoked'));

        self::assertFalse($probe->shouldSync($this->gmail(1)));
        self::assertFalse($probe->shouldSync($this->gmail(1)));
        self::assertSame([], $this->requests, 'There was never a token to send a request with.');
    }

    public function testOneAccountBackingOffDoesNotPauseAnother(): void
    {
        $this->script[self::GMAIL_HOST] = [$this->throttled(retryAfter: 600), $this->gmailArrival('705')];

        self::assertFalse($this->probe->shouldSync($this->gmail(1)));
        self::assertTrue($this->probe->shouldSync($this->gmail(2)));
    }

    public function testTwoAccountsKeepTheirOwnMemoryOfWhatTheyHaveAnnounced(): void
    {
        $this->script[self::GMAIL_HOST] = [$this->gmailArrival('705'), $this->gmailArrival('705')];

        self::assertTrue($this->probe->shouldSync($this->gmail(1)));
        self::assertTrue($this->probe->shouldSync($this->gmail(2)), 'the same id on another account is not the same news');
    }

    // ── Microsoft ─────────────────────────────────────────────────────────

    public function testAGraphAccountThatHasNeverSyncedIsLeftToTheScheduledSync(): void
    {
        self::assertFalse($this->probe->shouldSync($this->graph(10, synced: false)));
        self::assertSame([], $this->requests);
    }

    public function testTheFirstLookOnlyRemembersWhatIsThere(): void
    {
        // Nothing here says the newest message is unfamiliar — there is no
        // earlier look to compare it with — and a sync per account at every
        // restart would be noise.
        $this->script[self::GRAPH_HOST][] = $this->graphNewest(self::T1);

        self::assertFalse($this->probe->shouldSync($this->graph(10)));
    }

    public function testAnUnchangedNewestMessageIsNoSync(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [$this->graphNewest(self::T1), $this->graphNewest(self::T1)];

        self::assertFalse($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(2, $this->requests);
    }

    public function testANewerMessageIsASyncAnnouncedOnce(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1),
            $this->graphNewest(self::T2),
            $this->graphNewest(self::T2),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'remembered');
        self::assertTrue($this->probe->shouldSync($account), 'a later newest message is mail');
        self::assertFalse($this->probe->shouldSync($account), 'and a sync is already queued for it');
    }

    /**
     * The regression this signal was changed for. Graph's receivedDateTime has
     * a resolution of one second, so a burst of mail — three messages at once —
     * puts several under one value, and nothing promises that the query lists
     * them in the same order twice. Keyed by the newest message's id the probe
     * flipped A, B, A, B and queued a full sync per flip until something newer
     * arrived: the sync loop this class exists not to cause.
     */
    public function testTwoMessagesReceivedInOneSecondDoNotMakeTheSignalFlicker(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1, id: 'A'),
            $this->graphNewest(self::T1, id: 'B'),
            $this->graphNewest(self::T1, id: 'A'),
            $this->graphNewest(self::T1, id: 'B'),
            // The presence companion: the same account does announce real mail.
            $this->graphNewest(self::T2, id: 'C'),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'remembered');
        self::assertFalse($this->probe->shouldSync($account), 'same second, different id: not news');
        self::assertFalse($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
        self::assertTrue($this->probe->shouldSync($account), 'a later second is news');
    }

    public function testAnEmptyMailboxIsNoSync(): void
    {
        $this->script[self::GRAPH_HOST][] = $this->graphNewest(null);

        self::assertFalse($this->probe->shouldSync($this->graph(10)));
    }

    public function testGraphThrottlingPausesTheAccountForAsLongAsMicrosoftAsked(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [
            $this->throttled(retryAfter: 90, status: 429),
            $this->graphNewest(self::T1),
        ];

        self::assertFalse($this->probe->shouldSync($account));

        $this->clock->sleep(89);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(1, $this->requests);

        $this->clock->sleep(2);
        self::assertFalse($this->probe->shouldSync($account), 'it looks again (and only remembers: first sight)');
        self::assertCount(2, $this->requests);
    }

    // ── mail read, unread or starred somewhere else ───────────────────────
    //
    // The same discipline as arrivals: each thing is announced ONCE, however many
    // ticks it stays true for. A read on the phone stays pending until the sync
    // it asked for has run and stored a new link, and that sync waits behind a
    // single worker; a probe that said "yes" on every tick until then would be
    // the loop this class exists not to cause.

    public function testAReadElsewhereOnOutlookIsASyncAnnouncedOnce(): void
    {
        $account = $this->graphWithInbox(10);

        $this->script[self::GRAPH_HOST] = [
            // tick 1: nothing pending (and the Inbox's id is asked once)
            $this->graphNewest(self::T1), $this->graphInboxId(), $this->graphPending([]),
            // tick 2: a message was read on the phone
            $this->graphNewest(self::T1), $this->graphPending([['id' => 'm1', 'isRead' => true]]),
            // tick 3: the sync it asked for has not run: the same thing is pending
            $this->graphNewest(self::T1), $this->graphPending([['id' => 'm1', 'isRead' => true]]),
            // tick 4: the sync ran and stored a new link: nothing pending
            $this->graphNewest(self::T1), $this->graphPending([]),
            // tick 5: the same message read again later is NEWS again
            $this->graphNewest(self::T1), $this->graphPending([['id' => 'm1', 'isRead' => true]]),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'nothing is pending');
        self::assertTrue($this->probe->shouldSync($account), 'a read elsewhere is a sync');
        self::assertFalse($this->probe->shouldSync($account), 'and one is already queued for it');
        self::assertFalse($this->probe->shouldSync($account), 'quiet again once the sync has run');
        self::assertTrue($this->probe->shouldSync($account), 'a later change, even to the same message, is another');
    }

    public function testChangesAlreadyPendingOnTheFirstLookAreNews(): void
    {
        // Unlike an arrival, where a first look has nothing to compare the
        // newest message with. A pending change is evidence in itself: there is
        // a link, and it says something happened since it was stored.
        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1), $this->graphInboxId(), $this->graphPending([['id' => 'm1', 'isRead' => false]]),
        ];

        self::assertTrue($this->probe->shouldSync($this->graphWithInbox(10)));
    }

    /**
     * The one thing that must not happen, and the objection to this design that
     * newestReceivedAt() makes in its own comment: the delta link is the real
     * sync's cursor. Reading it must leave it exactly where it was — both the
     * request, which is the stored URL as it is, and the account, which is
     * never given a new link.
     */
    public function testTheProbeReadsTheSavedLinkAndNeverStoresAnother(): void
    {
        $account = $this->graphWithInbox(10);
        $before  = $account->graphDeltaLinks;

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1), $this->graphInboxId(), $this->graphPending([['id' => 'm1', 'isRead' => true]]),
        ];

        self::assertTrue($this->probe->shouldSync($account));

        self::assertSame($before, $account->graphDeltaLinks, 'the cursor belongs to the real sync and is never moved');
        self::assertContains($before['INBOX'], $this->urls, 'what was asked is the stored link, as stored');
    }

    public function testTheInboxFolderIsAskedForOncePerHourAndNotEveryTick(): void
    {
        $account = $this->graphWithInbox(10);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1), $this->graphInboxId(), $this->graphPending([]),
            $this->graphNewest(self::T1), $this->graphPending([]),
            $this->graphNewest(self::T1), $this->graphPending([]),
            // an hour later: asked again, so an id that rotated is found
            $this->graphNewest(self::T1), $this->graphInboxId(), $this->graphPending([]),
        ];

        $this->probe->shouldSync($account);
        $this->probe->shouldSync($account);
        $this->probe->shouldSync($account);

        self::assertCount(1, $this->inboxLookups(), 'asked once for three ticks');

        $this->clock->sleep(3601);
        $this->probe->shouldSync($account);

        self::assertCount(2, $this->inboxLookups(), 'and again after the hour');
    }

    public function testAnAccountWhoseInboxHasNoSavedLinkIsNotPeeked(): void
    {
        // A folder with no link is the import's: its history is still being read
        // and there is no cursor to ask about. Only the arrivals question is
        // put, and the script has no answer for a third.
        $account = $this->graph(10);
        $account->graphDeltaLinks = ['SOME-OTHER-FOLDER' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=t'];

        $this->script[self::GRAPH_HOST] = [$this->graphNewest(self::T1), $this->graphInboxId()];

        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(2, $this->urls, 'newest message, and the Inbox\'s id; no delta link read');
    }

    public function testAnExpiredInboxLinkIsOneSyncToRecoverItAndThenQuiet(): void
    {
        // Recovery is GraphApiSyncer's (it hands the folder back to the import).
        // The probe starts it once, not on every tick until it has run.
        $account = $this->graphWithInbox(10);

        $gone = fn (): MockResponse => new MockResponse('{"error":{"code":"syncStateNotFound"}}', [
            'http_code'        => 410,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1), $this->graphInboxId(), $gone(),
            $this->graphNewest(self::T1), $gone(),
        ];

        self::assertTrue($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
    }

    public function testNewMailAndAChangeInOneTickAreOneSync(): void
    {
        // A new message is in the folder's delta as well as being the newest
        // message. Two signals, one answer: a sync, not two.
        $account = $this->graphWithInbox(10);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1), $this->graphInboxId(), $this->graphPending([]),
            $this->graphNewest(self::T2), $this->graphPending([['id' => 'm2', 'isRead' => false]]),
            $this->graphNewest(self::T2), $this->graphPending([['id' => 'm2', 'isRead' => false]]),
        ];

        self::assertFalse($this->probe->shouldSync($account));
        self::assertTrue($this->probe->shouldSync($account), 'both signals changed');
        self::assertFalse($this->probe->shouldSync($account), 'and both are remembered');
    }

    public function testAThrottledPeekPausesTheAccountForAsLongAsMicrosoftAsked(): void
    {
        $account = $this->graphWithInbox(10);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1), $this->graphInboxId(), $this->throttled(retryAfter: 90, status: 429),
            $this->graphNewest(self::T1), $this->graphPending([['id' => 'm1', 'isRead' => true]]),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'a refusal is not news');

        $this->clock->sleep(89);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(3, $this->urls, 'newest message, the Inbox\'s id and the refused peek; nothing more inside the window');

        $this->clock->sleep(2);
        self::assertTrue($this->probe->shouldSync($account), 'and it does end');
    }

    public function testAReadElsewhereOnGmailIsASyncAnnouncedOnce(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->gmailQuiet(), $this->gmailQuiet(), $this->gmailQuiet(), $this->gmailQuiet()];
        $this->stateScript = [
            $this->gmailStateChange('710'),
            $this->gmailStateChange('710'),
            $this->gmailQuiet(),
            $this->gmailStateChange('720'),
        ];

        self::assertTrue($this->probe->shouldSync($account), 'a read on the phone, with no mail arriving, is a sync (and first sight is news)');
        self::assertFalse($this->probe->shouldSync($account), 'the same record is the same news');
        self::assertFalse($this->probe->shouldSync($account), 'quiet once the cursor has moved');
        self::assertTrue($this->probe->shouldSync($account), 'a later one is another');
    }

    public function testOtherLabelChangesOnGmailAreNoSync(): void
    {
        $this->script[self::GMAIL_HOST] = [$this->gmailQuiet(), $this->gmailQuiet()];
        $this->stateScript = [
            $this->json(['historyId' => '999', 'history' => [
                ['id' => '710', 'labelsRemoved' => [['message' => ['id' => 'm', 'labelIds' => ['UNREAD']], 'labelIds' => ['INBOX']]]],
            ]]),
            // The presence companion: a read is a sync on the same account.
            $this->gmailStateChange('711'),
        ];

        self::assertFalse($this->probe->shouldSync($this->gmail(1)), 'an archive is not a read');
        self::assertTrue($this->probe->shouldSync($this->gmail(1)));
    }

    public function testAnArrivalAndAReadInOneTickAreOneSyncOnGmail(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->gmailArrival('705'), $this->gmailArrival('705')];
        $this->stateScript = [$this->gmailStateChange('706'), $this->gmailStateChange('706')];

        self::assertTrue($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account), 'both are remembered');
    }

    public function testAnExpiredCursorIsAnnouncedOnceNotOncePerQuestion(): void
    {
        // Both questions are answered 404. The arrivals one names the expiry
        // (see testAnExpiredCursorIsOneSyncToRecoverItAndThenQuiet); the state
        // one has nothing to add, and must not be a second reason to sync nor a
        // failure that pauses the account.
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->httpStatus(404), $this->httpStatus(404)];
        $this->stateScript              = [$this->httpStatus(404), $this->httpStatus(404)];

        self::assertTrue($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
    }

    public function testAThrottledStateQuestionOnGmailPausesTheAccount(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->gmailQuiet(), $this->gmailQuiet()];
        $this->stateScript              = [$this->throttled(retryAfter: 120), $this->gmailStateChange('710')];

        self::assertFalse($this->probe->shouldSync($account), 'a refusal is not news');

        $this->clock->sleep(119);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(1, $this->requests, 'nothing was asked inside the window, of either question');

        $this->clock->sleep(2);
        self::assertTrue($this->probe->shouldSync($account));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** An Outlook account whose Inbox is followed by a saved delta link. */
    private function graphWithInbox(int $id): Account
    {
        $account = $this->graph($id);

        $account->graphDeltaLinks = ['INBOX' => 'https://graph.microsoft.com/v1.0/me/mailFolders/INBOX/messages/delta?$deltatoken=saved'];

        return $account;
    }

    private function graphInboxId(): MockResponse
    {
        return $this->json(['id' => 'INBOX']);
    }

    /**
     * What a saved delta link answers with.
     *
     * @param list<array<string, mixed>> $items
     */
    private function graphPending(array $items): MockResponse
    {
        return $this->json(['value' => $items, '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/INBOX/messages/delta?$deltatoken=next']);
    }

    /** @return list<string> */
    private function inboxLookups(): array
    {
        return array_values(array_filter($this->urls, static fn (string $url): bool => str_contains($url, '/mailFolders/inbox')));
    }

    private function gmailStateChange(string $recordId): MockResponse
    {
        return $this->json(['historyId' => '999', 'history' => [
            ['id' => $recordId, 'labelsRemoved' => [['message' => ['id' => 'm-' . $recordId, 'labelIds' => ['INBOX']], 'labelIds' => ['UNREAD']]]],
        ]]);
    }

    private function probe(?\Throwable $tokenFailure = null): InboxProbe
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $host = (string) parse_url($url, PHP_URL_HOST);

            $this->urls[] = $url;

            // The state question, told from the arrivals one by the filter the
            // arrivals one carries. Not counted in $this->requests, which the
            // tests above read as "how many times was the provider asked about
            // arrivals".
            if (self::GMAIL_HOST === $host && false === str_contains($url, 'historyTypes=')) {
                return array_shift($this->stateScript) ?? $this->gmailQuiet();
            }

            // MockHttpClient has already folded auth_bearer into the headers.
            $this->requests[] = $host . ' ' . str_replace('Authorization: Bearer ', '', (string) ($options['normalized_headers']['authorization'][0] ?? ''));

            $next = array_shift($this->script[$host]);

            if (null === $next) {
                self::fail('Unscripted request to ' . $host . ': the probe asked more often than the test allows for.');
            }

            return $next;
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);

        if (null !== $tokenFailure) {
            $tokenManager->method('getValidAccessToken')->willThrowException($tokenFailure);
        } else {
            $tokenManager->method('getValidAccessToken')->willReturnCallback(
                static fn (Account $account): string => 'token-' . $account->id,
            );
        }

        return new InboxProbe(
            new GmailApiClient($http, $tokenManager, new GmailQuotaPacer($this->clock)),
            new GraphApiClient($http, $tokenManager),
            new ArrayAdapter(),
            $this->clock,
            new NullLogger(),
        );
    }

    private function gmail(int $id, ?string $historyId = '700'): Account
    {
        $account = $this->account($id);

        $account->oauthProvider = MailProvider::Google->value;
        $account->gmailHistoryId = $historyId;

        return $account;
    }

    private function graph(int $id, bool $synced = true): Account
    {
        $account = $this->account($id);

        $account->oauthProvider = MailProvider::Microsoft->value;
        $account->lastSyncedAt  = true === $synced ? new DateTimeImmutable('2026-10-10 11:45:00') : null;

        return $account;
    }

    private function account(int $id): Account
    {
        $account = new Account();
        $account->usr      = new User();
        $account->authType = AuthType::OAuth2->value;

        // Doctrine's to assign, and the probe keys everything it remembers by it.
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    /**
     * @param list<string>|null $labelIds null leaves the key out, as an older
     *                                    fixture here did: not ruled out, so an arrival
     */
    private function gmailArrival(string $newestRecordId, ?array $labelIds = null): MockResponse
    {
        $message = ['id' => 'm-' . $newestRecordId];

        if (null !== $labelIds) {
            $message['labelIds'] = $labelIds;
        }

        return $this->json(['historyId' => '999', 'history' => [
            ['id' => $newestRecordId, 'messagesAdded' => [['message' => $message]]],
        ]]);
    }

    private function gmailQuiet(): MockResponse
    {
        return $this->json(['historyId' => '999']);
    }

    /**
     * What Graph answers for "the newest message in the mailbox", with the id it
     * happens to list first: the probe must not care which.
     */
    private function graphNewest(?string $receivedAt, string $id = 'x'): MockResponse
    {
        return $this->json(['value' => null === $receivedAt ? [] : [['id' => $id, 'receivedDateTime' => $receivedAt]]]);
    }

    private function throttled(int $retryAfter, int $status = 403): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => ['code' => $status, 'errors' => [['reason' => 'rateLimitExceeded']]]], JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json', 'retry-after' => (string) $retryAfter]],
        );
    }

    private function httpStatus(int $status): MockResponse
    {
        return new MockResponse('{"error":{"code":' . $status . '}}', [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
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
}
