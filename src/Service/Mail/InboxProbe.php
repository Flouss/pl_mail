<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailThrottledException;
use App\Domain\Exception\GraphThrottledException;
use App\Entity\Mail\Account;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Whether one OAuth account has new mail worth a sync, in any folder, found by asking
 * the provider rather than waiting to be told.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Gmail push needs Google to reach plMail through Pub/Sub, and Outlook push
 * needs Microsoft to reach a public HTTPS address. An install that cannot be
 * reached from the internet has neither, and an account without push is synced
 * on the quarter-hourly poll: mail that is on the phone at once appears here up
 * to fifteen minutes later. This asks every InboxProbeInterval::seconds() instead, one cheap
 * question per account, and the real sync is queued only when the answer is yes.
 *
 * THE ONE WAY THIS GOES WRONG, AND WHAT STOPS IT
 * ──────────────────────────────────────────────
 * Gmail's answer stays "yes" until the sync it triggers has moved the cursor,
 * and that sync waits on a queue with one consumer, which may be minutes deep.
 * A probe that simply reported the answer would queue another full sync on the
 * next tick, and the next: ImapIdleCommand::SYNC_MIN_GAP is the record of what
 * that looked like the last time. So each account remembers WHAT it last
 * announced, not when, and announces a thing once. Mail that arrives meanwhile
 * is a different thing and is announced in its own right, which keeps the number
 * of syncs bounded by the number of arrivals rather than by the number of ticks.
 *
 * That only holds if the thing is STABLE: a signal that can change without
 * anything arriving is announced as often as it flickers. Outlook's is the
 * newest message's timestamp for exactly that reason — see
 * GraphApiClient::newestReceivedAt().
 *
 * WHAT IT COVERS: mail arriving in any folder — the inbox, spam, and wherever
 * a filter or rule files it. It used to be the inbox alone, because that is all
 * push announces; but "my phone has it and plMail doesn't" is as true of spam,
 * and the wait for the quarter-hourly poll was the same fifteen minutes.
 * Gmail's own drafts and sent copies are not arrivals — see
 * GmailApiClient::probeArrivals(). Gmail push still announces the inbox alone
 * (the watch is registered on that label, GmailWatchService::LABEL), so for a
 * Gmail account that has push as well, only this probe sees the rest.
 *
 * WHAT IT DOES NOT COVER, on purpose: every non-arrival change (read state,
 * labels, deletions made elsewhere). They are still picked up by the
 * quarter-hourly poll, which this leaves exactly as it was. The signal memory
 * is in the cache, so losing it is one redundant sync, never a missed one.
 */
final readonly class InboxProbe
{
    /**
     * How long an account is left alone after any failure that said nothing
     * about when to come back. Short enough that a blip costs minutes of
     * latency; long enough that a provider outage, a revoked grant or a bad
     * response is five requests an hour instead of sixty.
     */
    private const int FAILURE_PAUSE_SECONDS = 300;

    /** How long a remembered arrival is kept. Losing it costs one redundant sync. */
    private const int MEMORY_SECONDS = 14 * 86400;

    /** How long an Outlook Inbox's folder id is trusted before it is asked for again. */
    private const int INBOX_FOLDER_SECONDS = 3600;

    public function __construct(
        private GmailApiClient         $gmail,
        private GraphApiClient         $graph,
        private CacheItemPoolInterface $cache,
        private ClockInterface         $clock,
        private LoggerInterface        $logger,
    ) {}

    /**
     * Whether the caller should queue a sync for this account now.
     *
     * Never throws: a probe that failed is "no news", with the account left
     * alone for a while, because the quarter-hourly poll is already the
     * backstop and an exception here would only take down the accounts after
     * this one in the same run.
     */
    public function shouldSync(Account $account): bool
    {
        $id = (int) $account->id;

        if ($this->pausedUntil($id) > $this->clock->now()->getTimestamp()) {
            return false;
        }

        try {
            $arrival = $this->signalOf($account);
            $changes = $this->changeSignalOf($account);
        } catch (GmailThrottledException | GraphThrottledException $throttled) {
            // What the provider asked for, not a guess: its own Retry-After, or
            // the exception's fallback when it sent none.
            $this->pause($id, intdiv($throttled->getRetryDelay(), 1000));

            return false;
        } catch (\Throwable $failure) {
            $this->logger->warning('InboxProbe: could not look at an account; leaving it to the scheduled sync for a while', [
                'accountId' => $id,
                'error'     => $failure->getMessage(),
            ]);

            $this->pause($id, self::FAILURE_PAUSE_SECONDS);

            return false;
        }

        // Both questions are put and both are remembered, whatever the first
        // one answered: a thing that is announced here is announced by one
        // sync, and has to be known as announced next tick whichever question
        // it was found by.
        $arrivalIsNews = $this->arrivalIsNews($account, $arrival);
        $changesAreNews = $this->changesAreNews($id, $changes);

        return $arrivalIsNews || $changesAreNews;
    }

    private function arrivalIsNews(Account $account, ?string $signal): bool
    {
        if (null === $signal) {
            return false;
        }

        $id       = (int) $account->id;
        $previous = $this->remembered($id, 'seen');

        if ($previous === $signal) {
            return false;
        }

        $this->remember($id, 'seen', $signal);

        // A first look at a Microsoft account has nothing to be compared with,
        // so a message being there is not news — only a later change is. A
        // Gmail signal is different in kind: it exists only when the history
        // window holds an arrival, so it is evidence even the first time.
        return null !== $previous || true === $account->isGmail();
    }

    /**
     * Whether what is waiting to be read has changed since it was last
     * announced. The empty signal is remembered too, and is never news: it is
     * what lets the same message being read again later be announced again.
     *
     * Unlike an arrival, a first look is news when something is pending. There
     * is nothing to compare it with, but it does not need comparing: it says
     * that something happened since the cursor was stored.
     */
    private function changesAreNews(int $accountId, ?string $signal): bool
    {
        if (null === $signal) {
            return false;
        }

        if ($this->remembered($accountId, 'changes') === $signal) {
            return false;
        }

        $this->remember($accountId, 'changes', $signal);

        return '' !== $signal;
    }

    /**
     * A token for what the provider reports as the newest arrival, or
     * null when there is nothing to report or nothing to ask about.
     */
    private function signalOf(Account $account): ?string
    {
        if (true === $account->isGmail()) {
            $cursor = $account->gmailHistoryId;

            // No cursor: the first sync has not finished, and starting it is
            // the scheduled sync's job, not a probe's.
            if (null === $cursor) {
                return null;
            }

            try {
                return $this->gmail->probeArrivals($account, $cursor);
            } catch (GmailThrottledException $throttled) {
                throw $throttled;
            } catch (GmailApiException $failure) {
                // The cursor is older than the history Gmail keeps. Recovering
                // from that is the real sync's job (GmailApiSyncer::
                // syncIncremental re-lists the mailbox); the probe's is to
                // start it, once. Named by the cursor so it is announced once
                // per dead cursor and not once per tick until the sync has run.
                if (true === in_array($failure->getStatus(), [404, 410], true)) {
                    return 'expired:' . $cursor;
                }

                throw $failure;
            }
        }

        if (true === $account->isMicrosoft()) {
            // Never synced: no baseline to compare with, and the initial import
            // is the scheduled sync's.
            if (null === $account->lastSyncedAt) {
                return null;
            }

            // The timestamp and not an id: see newestReceivedAt() on why
            // an id can flip between two messages received in the same second.
            return $this->graph->newestReceivedAt($account);
        }

        return null;
    }

    /**
     * A token for what the provider says has been read, unread, starred or moved
     * elsewhere and not yet synced, '' when nothing has, or null when there is
     * nothing to ask about.
     *
     * The second question, put after the arrivals one. It has its own memory
     * because the two are different things: new mail moves the first, a read on
     * the phone moves this, and one sync answers whichever (or both) moved.
     */
    private function changeSignalOf(Account $account): ?string
    {
        if (true === $account->isGmail()) {
            $cursor = $account->gmailHistoryId;

            if (null === $cursor) {
                return null;
            }

            try {
                return $this->gmail->probeStateChanges($account, $cursor) ?? '';
            } catch (GmailThrottledException $throttled) {
                throw $throttled;
            } catch (GmailApiException $failure) {
                // A dead cursor is already announced, once, by the arrivals
                // question (see signalOf()). Announcing it from here as well would
                // be a second reason to sync for one cause, and rethrowing it
                // would pause an account for a fault that has an answer.
                if (true === in_array($failure->getStatus(), [404, 410], true)) {
                    return null;
                }

                throw $failure;
            }
        }

        if (true === $account->isMicrosoft()) {
            // As for arrivals: an account that has never synced has no cursor to
            // ask about. And a folder with no saved link is the import's, whose
            // history is still being read, so there is none either.
            if (null === $account->lastSyncedAt || [] === $account->graphDeltaLinks) {
                return null;
            }

            // The Inbox, and not every folder: one request per account per tick
            // is what this stays at. Mail read in another folder waits for the
            // quarter-hourly sync, as everything did before this question.
            $inbox = $this->inboxFolderId($account);
            $link  = null === $inbox ? null : ($account->graphDeltaLinks[$inbox] ?? null);

            if (null === $link) {
                return null;
            }

            return $this->graph->pendingChanges($account, $link);
        }

        return null;
    }

    /**
     * The Inbox's folder id for an account, asked for at most once an hour.
     *
     * Not forever: Graph does not promise a system folder's id stays put (see
     * GraphApiClient::resolveWellKnownFolders()), and an id that moved would
     * silently stop the probe finding the Inbox's link. An hour bounds that.
     * The moment is kept with the id and measured against the injected clock,
     * not the cache's own expiry, which is why a clock that can be moved can
     * test it.
     */
    private function inboxFolderId(Account $account): ?string
    {
        $accountId = (int) $account->id;
        $item      = $this->cache->getItem($this->key('inbox_folder', $accountId));
        $now       = $this->clock->now()->getTimestamp();

        if (true === $item->isHit()) {
            $held = $item->get();

            if (true === is_array($held) && $now - (int) ($held['at'] ?? 0) < self::INBOX_FOLDER_SECONDS) {
                return '' === (string) ($held['id'] ?? '') ? null : (string) $held['id'];
            }
        }

        $id = $this->graph->inboxFolderId($account);

        $item->set(['id' => $id ?? '', 'at' => $now]);
        $item->expiresAfter(self::MEMORY_SECONDS);

        $this->cache->save($item);

        return $id;
    }

    private function pausedUntil(int $accountId): int
    {
        $item = $this->cache->getItem($this->key('pause', $accountId));

        return true === $item->isHit() ? (int) $item->get() : 0;
    }

    private function pause(int $accountId, int $seconds): void
    {
        $item = $this->cache->getItem($this->key('pause', $accountId));

        $item->set($this->clock->now()->getTimestamp() + max(1, $seconds));
        $item->expiresAfter(max(1, $seconds));

        $this->cache->save($item);
    }

    /** @param 'seen'|'changes' $what */
    private function remembered(int $accountId, string $what): ?string
    {
        $item = $this->cache->getItem($this->key($what, $accountId));

        return true === $item->isHit() ? (string) $item->get() : null;
    }

    /** @param 'seen'|'changes' $what */
    private function remember(int $accountId, string $what, string $signal): void
    {
        $item = $this->cache->getItem($this->key($what, $accountId));

        $item->set($signal);
        $item->expiresAfter(self::MEMORY_SECONDS);

        $this->cache->save($item);
    }

    private function key(string $what, int $accountId): string
    {
        return 'inbox_probe.' . $what . '.' . $accountId;
    }
}
