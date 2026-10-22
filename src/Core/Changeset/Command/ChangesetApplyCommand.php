<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Command;

use App\Core\Changeset\ChangesetApplier;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Exception\ChangesetException;
use App\Core\Security\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Застосувати запропонований пакет від імені користувача адмінки (сценарії з CSV, тести). Ті самі правила, що й кнопка
 * в адмінці (PR-4): ризикові прапорці потребують --confirm-risk, конфлікт expect — відмова з «хто/коли».
 */
#[AsCommand(name: 'changeset:apply', description: 'Застосувати запропонований пакет змін від імені користувача (--confirm-risk для ризикових)')]
final class ChangesetApplyCommand extends Command
{
    public function __construct(private readonly ChangesetApplier $applier, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'id пакета')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Email користувача адмінки, що застосовує')
            ->addOption('confirm-risk', null, InputOption::VALUE_NONE, 'Явне підтвердження ризикових прапорців')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Відхилити замість застосувати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changeset = $this->em->find(Changeset::class, (int) $input->getArgument('id'));
        if (null === $changeset) {
            $output->writeln('<error>Пакет не знайдено</error>');

            return Command::INVALID;
        }
        $email = (string) $input->getOption('user');
        $user = '' === $email ? null : $this->em->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);
        if (null === $user) {
            $output->writeln('<error>Потрібен --user=<email> наявного користувача адмінки</error>');

            return Command::INVALID;
        }
        try {
            $changeset = $input->getOption('reject')
                ? $this->applier->reject($changeset, $user)
                : $this->applier->apply($changeset, $user, (bool) $input->getOption('confirm-risk'));
        } catch (ChangesetException $e) {
            $output->writeln((string) json_encode($e->payload(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));

            return 2;
        }
        $output->writeln(\sprintf('Пакет #%d: %s', (int) $changeset->getId(), $changeset->getStatus()->label()));

        return Command::SUCCESS;
    }
}
