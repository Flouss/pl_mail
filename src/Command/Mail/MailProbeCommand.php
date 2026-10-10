<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Domain\Enum\Mail\SyncTrigger;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\Demo\DemoMode;
use App\Service\Mail\InboxProbe;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Look for new inbox mail on every Gmail and Microsoft account, and queue a
 * sync for the ones that have some.
 *
 * Run by the scheduler every minute. It stands in for push on an install that
 * cannot be reached from the internet — see InboxProbe, which holds the logic
 * and the reasons. Like app:mail:sync it dispatches and never syncs: the fetch
 * is the ingest worker's, so this returns in seconds whatever the mailbox holds.
 *
 * WHO IT LOOKS AT. Active OAuth accounts only: an IMAP account has IDLE and
 * nothing to ask a provider API, and an account whose grant is dead is skipped
 * because every look would start with a refresh against the identity endpoint
 * — once a minute for ever — and OAuthTokenManager has already raised the
 * "sign in again" card for it.
 *
 * HOW LONG IT MAY TAKE. It runs on the scheduler's one worker, beside every
 * other scheduled job, so a run that outlasts its minute delays itself and them.
 * Each request has its own timeout, and past BUDGET_SECONDS no further account
 * is started; the ones left are looked at next minute, and the quarter-hourly
 * sync is the backstop whatever happens here.
 */
#[AsCommand(
    name: 'app:mail:probe',
    description: 'Look for new inbox mail on Gmail and Microsoft accounts and queue a sync for those that have some',
)]
final class MailProbeCommand extends Command
{
    /**
     * The point past which starting another account risks running into the
     * next minute's run. A slow provider can spend a request's whole ten-second
     * timeout, and several accounts of that is most of a minute.
     */
    private const int BUDGET_SECONDS = 45;

    public function __construct(
        private readonly AccountRepository   $accountRepository,
        private readonly InboxProbe          $probe,
        private readonly MessageBusInterface $bus,
        private readonly DemoMode            $demoMode,
        private readonly ClockInterface      $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // As app:mail:sync, and for the same reason: a demo instance's accounts
        // point at documentation domains.
        if (true === $this->demoMode->isEnabled()) {
            $io->note('Demo mode is on — the inbox probe is disabled.');

            return Command::SUCCESS;
        }

        $startedAt = $this->clock->now()->getTimestamp();
        $queued    = 0;

        foreach ($this->accountRepository->findBy(['isActive' => true]) as $account) {
            if (false === $this->isProbeable($account)) {
                continue;
            }

            if ($this->clock->now()->getTimestamp() - $startedAt >= self::BUDGET_SECONDS) {
                $io->note('Out of time for this minute; the remaining accounts wait for the next run.');

                break;
            }

            if (false === $this->probe->shouldSync($account)) {
                continue;
            }

            $this->bus->dispatch(new SyncAccountMessage((int) $account->id, SyncTrigger::Probe));
            $io->text(sprintf('→ new mail on %s (#%d), sync queued', $account->email, $account->id));
            ++$queued;
        }

        if ($queued > 0) {
            $io->success(sprintf('Queued %d account sync(s).', $queued));
        }

        return Command::SUCCESS;
    }

    private function isProbeable(Account $account): bool
    {
        if (false === $account->isGmail() && false === $account->isMicrosoft()) {
            return false;
        }

        // The grant is dead and the account says so; see the class docblock.
        return null === $account->oauthLastRefreshError;
    }
}
