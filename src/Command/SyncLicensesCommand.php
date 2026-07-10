<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LicenseRepository;
use App\Service\StripeService;
use App\Service\SubscriptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-fetch every Stripe-backed subscription and re-sync its License row. Useful
 * to backfill fields added after issuance (e.g. current_period_end) or to
 * reconcile state after missed webhooks.
 */
#[AsCommand(name: 'app:license:resync', description: 'Re-fetch subscriptions from Stripe and sync license state')]
class SyncLicensesCommand extends Command
{
    public function __construct(
        private readonly LicenseRepository $licenses,
        private readonly StripeService $stripe,
        private readonly SubscriptionService $subscriptions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $synced = 0;

        foreach ($this->licenses->findAll() as $license) {
            $subId = $license->getStripeSubscriptionId();
            if ($subId === null || $subId === '') {
                continue;
            }

            try {
                $this->subscriptions->syncFromSubscription($this->stripe->retrieveSubscription($subId));
                ++$synced;
                $io->writeln(sprintf('Synced %s (%s)', $license->getLicenseId(), $subId));
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: %s', $license->getLicenseId(), $e->getMessage()));
            }
        }

        $io->success(sprintf('Re-synced %d license(s).', $synced));

        return Command::SUCCESS;
    }
}
