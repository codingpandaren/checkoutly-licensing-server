<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RecoverySchedule;
use App\Repository\RecoveryScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The managed-cron booster tick. Pings the recoverycron endpoint of every due,
 * entitled shop so low-traffic stores still fire their abandoned-cart reminders.
 * Meant to run from the host crontab every minute; the module's own web-cron is
 * the primary trigger, so a blocked or timed-out ping is non-fatal - we just
 * back off and try again.
 */
#[AsCommand(name: 'app:recovery:tick', description: 'Ping due shops to run their abandoned-cart recovery')]
class RecoveryTickCommand extends Command
{
    private const BATCH = 20;

    private const MAX_PER_TICK = 500;

    private const TIMEOUT = 15;

    private const BACKOFF_CAP = 8;

    public function __construct(
        private readonly RecoveryScheduleRepository $schedules,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $due = $this->schedules->findDue($now, self::MAX_PER_TICK);
        if ($due === []) {
            return Command::SUCCESS;
        }

        $pinged = 0;
        $skipped = 0;
        $failed = 0;

        foreach (array_chunk($due, self::BATCH) as $chunk) {
            $pending = [];

            foreach ($chunk as $schedule) {
                if (!$schedule->getLicense()->isEntitled() || $schedule->getCallbackUrl() === '') {
                    $this->defer($schedule, $now);
                    ++$skipped;
                    continue;
                }

                try {
                    $response = $this->http->request('GET', $schedule->getCallbackUrl(), [
                        'headers' => [
                            'X-Checkoutly-Cron-Token' => $schedule->getCallbackToken(),
                            'User-Agent' => 'Checkoutly-Scheduler/1.0',
                        ],
                        'timeout' => self::TIMEOUT,
                        'max_redirects' => 3,
                    ]);
                    $pending[] = [$schedule, $response];
                } catch (\Throwable $e) {
                    $this->markFailure($schedule, 'error', null, $now);
                    ++$failed;
                }
            }

            foreach ($pending as [$schedule, $response]) {
                try {
                    $code = $response->getStatusCode();
                    if ($code >= 200 && $code < 300) {
                        $this->markSuccess($schedule, $code, $now);
                        ++$pinged;
                    } else {
                        $this->markFailure($schedule, 'http_' . $code, $code, $now);
                        ++$failed;
                    }
                } catch (\Throwable $e) {
                    $this->markFailure($schedule, 'timeout', null, $now);
                    ++$failed;
                }
            }

            $this->em->flush();
        }

        $io->writeln(sprintf('recovery tick: pinged=%d skipped=%d failed=%d', $pinged, $skipped, $failed));

        return Command::SUCCESS;
    }

    private function markSuccess(RecoverySchedule $schedule, int $code, \DateTimeImmutable $now): void
    {
        $schedule->setLastRunAt($now);
        $schedule->setLastStatus('ok');
        $schedule->setLastHttpCode($code);
        $schedule->setConsecutiveFailures(0);
        $schedule->setNextDueAt($now->modify('+' . $schedule->getIntervalMinutes() . ' minutes'));
        $this->schedules->save($schedule, false);
    }

    private function markFailure(RecoverySchedule $schedule, string $status, ?int $code, \DateTimeImmutable $now): void
    {
        $failures = $schedule->getConsecutiveFailures() + 1;
        $factor = min(2 ** ($failures - 1), self::BACKOFF_CAP);
        $delay = $schedule->getIntervalMinutes() * $factor;

        $schedule->setConsecutiveFailures($failures);
        $schedule->setLastStatus($status);
        $schedule->setLastHttpCode($code);
        $schedule->setNextDueAt($now->modify('+' . $delay . ' minutes'));
        $this->schedules->save($schedule, false);
    }

    private function defer(RecoverySchedule $schedule, \DateTimeImmutable $now): void
    {
        $schedule->setNextDueAt($now->modify('+' . $schedule->getIntervalMinutes() . ' minutes'));
        $this->schedules->save($schedule, false);
    }
}
