<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Command;

use App\Core\Audit\AuditSource;
use App\Core\Changeset\ChangesetAuthor;
use App\Core\Changeset\ChangesetPlanner;
use App\Core\Changeset\Exception\ChangesetException;
use App\Core\Security\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dry-run пакета з JSON-файлу (або stdin через «-»): план з diff, попередженнями, ризиками й помилками у JSON.
 * --save --user=<email> — зберегти як proposed (джерело cli) для сценаріїв з CSV і тестів; застосовує людина в адмінці
 * або changeset:apply. Код виходу 2 — пакет не пройшов перевірку.
 */
#[AsCommand(name: 'changeset:dry-run', description: 'Перевірити пакет змін із JSON (diff, попередження, ризики); --save — зберегти як запропонований')]
final class ChangesetDryRunCommand extends Command
{
    public function __construct(private readonly ChangesetPlanner $planner, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'JSON-файл пакета {"title", "operations": [...]} або - для stdin')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Зберегти пакет як запропонований (потрібен --user)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Email користувача адмінки — автор пакета');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        $json = '-' === $file ? stream_get_contents(\STDIN) : @file_get_contents($file);
        if (false === $json) {
            $output->writeln(\sprintf('<error>Не вдалося прочитати %s</error>', $file));

            return Command::INVALID;
        }
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $output->writeln(\sprintf('<error>Не JSON: %s</error>', $e->getMessage()));

            return Command::INVALID;
        }
        if (!\is_array($data)) {
            $output->writeln('<error>Очікується об\'єкт {"title", "operations"}</error>');

            return Command::INVALID;
        }

        try {
            if ($input->getOption('save')) {
                $email = (string) $input->getOption('user');
                $user = '' === $email ? null : $this->em->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);
                if (null === $user) {
                    $output->writeln('<error>--save потребує --user=<email> наявного користувача адмінки</error>');

                    return Command::INVALID;
                }
                $changeset = $this->planner->propose($data, ChangesetAuthor::of($user, AuditSource::Cli));
                $output->writeln(self::json(['id' => $changeset->getId(), 'status' => $changeset->getStatus()->value, 'diff' => $changeset->getDiff(), 'warnings' => $changeset->getWarnings(), 'risk_flags' => $changeset->getRiskFlags(), 'expires_at' => $changeset->getExpiresAt()->format(\DATE_ATOM)]));

                return Command::SUCCESS;
            }
            $plan = $this->planner->dryRun($data);
        } catch (ChangesetException $e) {
            $output->writeln(self::json($e->payload()));

            return 2;
        }
        $output->writeln(self::json($plan->toArray()));

        return $plan->hasErrors() ? 2 : Command::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return (string) json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    }
}
