<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grant or revoke ROLE_ADMIN (access to the /admin operator console) for a user
 * by email. Roles are persisted on the user row, so this is how the first admin
 * is created — the user must have logged in via OAuth at least once.
 */
#[AsCommand(name: 'app:user:promote', description: 'Grant or revoke ROLE_ADMIN for a user by email')]
class PromoteUserCommand extends Command
{
    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Remove ROLE_ADMIN instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->users->findOneByEmail($email);
        if (!$user instanceof User) {
            $io->error(sprintf('No user with email %s. They must sign in once before being promoted.', $email));

            return Command::FAILURE;
        }

        $roles = array_values(array_filter($user->getRoles(), static fn (string $r): bool => $r !== 'ROLE_USER'));

        if ($input->getOption('revoke')) {
            $roles = array_values(array_filter($roles, static fn (string $r): bool => $r !== 'ROLE_ADMIN'));
            $user->setRoles($roles);
            $this->users->save($user);
            $io->success(sprintf('Removed ROLE_ADMIN from %s.', $email));

            return Command::SUCCESS;
        }

        if (!in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
        }
        $user->setRoles($roles);
        $this->users->save($user);
        $io->success(sprintf('Granted ROLE_ADMIN to %s.', $email));

        return Command::SUCCESS;
    }
}
