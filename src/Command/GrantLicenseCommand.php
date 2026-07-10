<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\License;
use App\Entity\User;
use App\Repository\LicenseRepository;
use App\Repository\UserRepository;
use App\Service\DomainNormalizer;
use App\Service\LicenseSigner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grant a license to a user (find-or-create by email), mint the signed key and
 * persist an active License. Used for manual/comp grants and for testing the
 * portal before Stripe issuance exists. The key is signed WITHOUT a domain — the
 * registered domain lives on the License row so it can be transferred in the
 * portal without re-issuing the key.
 */
#[AsCommand(name: 'app:license:grant', description: 'Grant an active license to a user by email')]
class GrantLicenseCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LicenseRepository $licenses,
        private readonly LicenseSigner $signer,
        private readonly DomainNormalizer $normalizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addOption('tier', null, InputOption::VALUE_REQUIRED, 'License tier', 'pro')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Validity in days (0 = perpetual)', '0')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Pre-register this store domain', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $tier = (string) $input->getOption('tier');
        $days = (int) $input->getOption('days');
        $domain = $this->normalizer->normalize((string) $input->getOption('domain'));

        $user = $this->users->findOneByEmail($email);
        if (!$user instanceof User) {
            $user = (new User())
                ->setEmail($email)
                ->setOauthProvider('manual')
                ->setOauthId('manual:' . $email);
            $this->users->save($user);
            $io->text('Created user ' . $email);
        }

        $issued = $this->signer->issue($email, $tier, $days, '');

        $license = (new License())
            ->setLicenseId($issued['id'])
            ->setUser($user)
            ->setTier($tier)
            ->setLicenseKey($issued['key'])
            ->setStatus(License::STATUS_ACTIVE)
            ->setRevoked(false)
            ->setExpiresAt($issued['payload']['exp']);
        if ($domain !== '') {
            $license->setRegisteredDomain($domain);
        }
        $this->licenses->save($license);

        $io->success(sprintf('Granted %s license to %s (license id %s%s)', $tier, $email, $issued['id'], $domain !== '' ? ', domain ' . $domain : ''));
        $io->section('License key');
        $io->writeln($issued['key']);

        return Command::SUCCESS;
    }
}
