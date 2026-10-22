<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Command;

use App\Core\Integration\Crm\Webhook\WebhookTokens;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ротація токена вебхука CRM: новий URL для налаштувань KeyCRM, старий токен діє ще 24 год. Токен показується лише тут
 * (у БД — хеш); запускати з `./dc exec php bin/console crm:webhook:rotate-token`, вивід не класти в чат і тікети.
 */
#[AsCommand(name: 'crm:webhook:rotate-token', description: 'Новий токен вебхука CRM (старий діє ще 24 год), вивести URL для KeyCRM')]
final class WebhookRotateTokenCommand extends Command
{
    public function __construct(
        private readonly WebhookTokens $tokens,
        #[Autowire('%site_host%')] private readonly string $siteHost,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = $this->tokens->rotate();
        $io->success('Новий токен вебхука чинний. Попередній (і CRM_WEBHOOK_TOKEN з .env.local, якщо це перша ротація) діє ще 24 год.');
        $io->writeln('URL для KeyCRM (Налаштування → Вебхуки, подія «зміна залишків», POST):');
        $io->writeln(\sprintf('  https://%s/webhooks/crm/stock?token=%s', $this->siteHost, $token));
        $io->newLine();
        $io->writeln('Після оновлення URL у KeyCRM більше нічого робити не треба. Токен ніде не зберігається у відкритому вигляді.');

        return Command::SUCCESS;
    }
}
