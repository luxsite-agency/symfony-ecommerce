<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Settings;

use App\Core\Customer\Google\GoogleKeySource;
use App\Core\Customer\Google\GoogleOidcClient;
use App\Core\Customer\Google\GoogleSignInConfig;
use App\Core\Customer\Google\OidcException;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use App\Site\SiteUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Розділ «Вхід через Google» під списком налаштувань (рішення власника 13.10.2026: ключі вводить клієнт в адмінці):
 * стан (звідки ключі, чому вимкнено), готові значення для Cloud Console — Authorized JavaScript origin і redirect URI
 * на канонічному домені магазину (site_host, не хост запиту: адмінка живе на admin_host), і кнопка «Перевірити
 * налаштування»: формат Client ID, стан секрету, перемикач, доступність discovery/JWKS Google. Повний вхід адміністратор
 * перевіряє сам кнопкою на сайті в новій вкладці. Тексти — домен admin (uk/ru/en).
 */
final class GoogleSignInPanel
{
    public const string CSRF = 'settings-google-check';

    public function __construct(
        private readonly GoogleSignInConfig $config,
        private readonly GoogleOidcClient $oidc,
        private readonly Settings $settings,
        private readonly SecretBox $box,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly TranslatorInterface $translator,
        #[Autowire('%site_host%')] private readonly string $siteHost,
    ) {
    }

    /** @return array{source: string, problem: string|null, origin: string, redirectUri: string, loginUrl: string, csrf: string} */
    public function view(): array
    {
        return [
            'source' => $this->config->source()->value,
            'problem' => $this->config->problem(),
            'origin' => 'https://'.$this->siteHost,
            'redirectUri' => $this->siteUrls->absolute('site_account_google_callback.'.$this->siteUrls->defaultLocale()),
            'loginUrl' => $this->siteUrls->absolute('site_account_login.'.$this->siteUrls->defaultLocale()),
            'csrf' => self::CSRF,
        ];
    }

    /**
     * Перевірки для flash: кожна — ok/fail і готовий текст (без значень ключів).
     *
     * @return list<array{ok: bool, text: string}>
     */
    public function check(): array
    {
        $t = fn (string $key, array $params = []): string => $this->translator->trans('settings.google.check_result.'.$key, $params, 'admin');
        $rows = [];

        $raw = trim((string) $this->settings->get(SettingKeys::GOOGLE_CLIENT_ID));
        $idOk = null !== SettingKeys::validValue(SettingKeys::GOOGLE_CLIENT_ID, $raw);
        $rows[] = ['ok' => $idOk, 'text' => $t($idOk ? 'client_id_ok' : ('' === $raw ? 'client_id_missing' : 'client_id_invalid'))];

        $state = $this->settings->secretState(SettingKeys::GOOGLE_CLIENT_SECRET);
        $rows[] = ['ok' => $state->isReady(), 'text' => $t('secret_'.$state->value, ['problem' => $this->box->keyProblem() ?? ''])];

        $enabled = $this->settings->getBool(SettingKeys::GOOGLE_SIGN_IN_ENABLED);
        $source = $this->config->source();
        $rows[] = match (true) {
            GoogleKeySource::Settings === $source => ['ok' => true, 'text' => $t('enabled_settings')],
            GoogleKeySource::Env === $source => ['ok' => false, 'text' => $t('enabled_env')],
            $enabled => ['ok' => false, 'text' => $t('enabled_incomplete')],
            default => ['ok' => false, 'text' => $t('enabled_off')],
        };

        try {
            $probe = $this->oidc->probe();
            $rows[] = ['ok' => true, 'text' => $t('google_ok', ['issuer' => $probe['issuer'], 'keys' => $probe['keys']])];
            if (!$probe['endpointsMatch']) {
                $rows[] = ['ok' => false, 'text' => $t('google_endpoints_changed')];
            }
        } catch (OidcException $e) {
            $rows[] = ['ok' => false, 'text' => $t('google_failed', ['reason' => $e->reason])];
        }

        return $rows;
    }
}
