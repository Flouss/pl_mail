<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Scheduler;

use App\Infrastructure\Scheduler\MaintenanceSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * What the scheduler is told to run and how often, for the entries whose
 * cadence is the feature.
 *
 * Nothing else exercises the schedule: a command that is written, tested and
 * never scheduled is a command that never runs, with every test green.
 */
final class MaintenanceScheduleTest extends TestCase
{
    /**
     * Mail from an OAuth account reaches plMail within a minute of the probe
     * seeing it, and the probe sees it within 30 s of its arriving, so the
     * probe is asked every 30 s. The quarter-hourly sync stays beside it as
     * the backstop for everything the probe cannot see (spam, mail filtered
     * past the inbox, flag changes).
     */
    public function testTheInboxProbeRunsEveryThirtySecondsAndTheFullSyncStaysQuarterHourly(): void
    {
        $triggers = $this->triggersByCommand();

        self::assertSame('every 30 seconds', $triggers['app:mail:probe'] ?? null);
        self::assertSame('*/15 * * * *', $triggers['app:mail:sync'] ?? null);
    }

    /**
     * @return array<string, string> command line => the trigger's own description (a cron expression, or "every N seconds")
     */
    private function triggersByCommand(): array
    {
        $schedule = (new MaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        $triggers = [];

        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());

            foreach ($recurring->getMessages($context) as $message) {
                if ($message instanceof RunCommandMessage) {
                    $triggers[$message->input] = (string) $recurring->getTrigger();
                }
            }
        }

        return $triggers;
    }
}
