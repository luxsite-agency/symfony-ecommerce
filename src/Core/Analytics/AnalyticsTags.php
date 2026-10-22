<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics;

use App\Core\Customer\Http\AccountPath;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Збирає вивід усіх провайдерів для layout. Теги ввімкнені на prod завжди, на dev/test — лише з налаштуванням
 * «Вмикати теги на dev» (правило analytics-healthcheck: теги вантажаться без взаємодії користувача, без lazy-load).
 * Маршрут з атрибутом NO_TRACKING (кабінет покупця, SiteRouteLoader) не отримує жодного тегу: ні GTM/GA4/Pixel, ні
 * «Код у head/body». Адреси листів (/account/verify/{token}, /account/password/{token}, login-link з hash) інакше пішли б
 * стороннім сервісам як page_location навіть без згоди (Consent Mode шле cookieless-пінги; рев'ю безпеки #45, п. 1).
 * Додатково — за шляхом головного запиту (AccountPath): 404 під /account/… (обрізаний токен) маршруту не має, а сторінку
 * помилки ErrorListener рендерить підзапитом без атрибутів (повторне рев'ю #45, п. C).
 */
final class AnalyticsTags
{
    public const string NO_TRACKING = '_no_tracking';

    /** @var list<AnalyticsTagProviderInterface> */
    private array $providers;
    private ?TagContext $context = null;

    /** @param iterable<AnalyticsTagProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.analytics_tag_provider', defaultPriorityMethod: 'getPriority')] iterable $providers,
        private readonly Settings $settings,
        private readonly ConsentPolicy $consentPolicy,
        private readonly RequestStack $requests,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
        $this->providers = [...$providers];
    }

    public function isEnabled(): bool
    {
        return 'prod' === $this->environment || $this->settings->getBool(SettingKeys::TAGS_ON_DEV);
    }

    public function context(): TagContext
    {
        if (null === $this->context) {
            $request = $this->requests->getCurrentRequest();
            $locale = $request?->getLocale() ?? $this->defaultLocale;
            $enabled = $this->isEnabled() && !self::isPrivate($request) && !self::isPrivate($this->requests->getMainRequest());
            $this->context = new TagContext($locale, $enabled, $this->consentPolicy->resolve($request, $locale));
        }

        return $this->context;
    }

    private static function isPrivate(?Request $request): bool
    {
        return null !== $request && (true === $request->attributes->get(self::NO_TRACKING) || AccountPath::matches($request->getPathInfo()));
    }

    public function head(): string
    {
        return $this->collect('head');
    }

    public function bodyStart(): string
    {
        return $this->collect('bodyStart');
    }

    public function bodyEnd(): string
    {
        return $this->collect('bodyEnd');
    }

    private function collect(string $slot): string
    {
        $context = $this->context();
        if (!$context->enabled) {
            return '';
        }
        $parts = [];
        foreach ($this->providers as $provider) {
            $html = match ($slot) {
                'head' => $provider->head($context),
                'bodyStart' => $provider->bodyStart($context),
                default => $provider->bodyEnd($context),
            };
            if ('' !== trim($html)) {
                $parts[] = $html;
            }
        }

        return implode("\n", $parts);
    }
}
