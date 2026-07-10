<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LicenseSigner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:license:sign',
    description: 'Sign a Checkoutly license key (for manual issuance and testing)',
)]
class SignLicenseCommand extends Command
{
    public function __construct(private readonly LicenseSigner $signer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Licensee email')
            ->addOption('tier', null, InputOption::VALUE_REQUIRED, 'License tier', 'pro')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Validity in days (0 = perpetual)', '0')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Bind to a shop domain (empty = any)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->signer->issue(
            (string) $input->getArgument('email'),
            (string) $input->getOption('tier'),
            (int) $input->getOption('days'),
            (string) $input->getOption('domain'),
        );

        $io->section('Payload');
        $io->writeln((string) json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $io->section('License key');
        $io->writeln($result['key']);
        $io->newLine();
        $io->success('License id: ' . $result['id']);

        return Command::SUCCESS;
    }
}
