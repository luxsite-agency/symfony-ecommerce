<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Provider;

use App\Core\Analytics\AnalyticsTagProviderInterface;
use App\Core\Analytics\ConsentState;
use App\Core\Analytics\TagContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consent Mode v2: gtag('consent','default') ДО GTM/gtag (найвищий пріоритет) і простий банер згоди, коли
 * за замовчуванням denied і користувач ще не обрав. Вибір — cookie shop_consent на 180 днів + consent update.
 */
final class ConsentProvider implements AnalyticsTagProviderInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public static function getPriority(): int
    {
        return 100;
    }

    public function head(TagContext $context): string
    {
        $default = json_encode($context->consent->toGtagDefault() + ['wait_for_update' => 500], \JSON_THROW_ON_ERROR);

        return <<<HTML
            <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
            gtag('consent','default',{$default});</script>
            HTML;
    }

    public function bodyStart(TagContext $context): string
    {
        return '';
    }

    public function bodyEnd(TagContext $context): string
    {
        if (!$context->consent->needsBanner) {
            return '';
        }
        $text = htmlspecialchars($this->translator->trans('site.consent.text', [], null, $context->locale), \ENT_QUOTES);
        $accept = htmlspecialchars($this->translator->trans('site.consent.accept', [], null, $context->locale), \ENT_QUOTES);
        $decline = htmlspecialchars($this->translator->trans('site.consent.decline', [], null, $context->locale), \ENT_QUOTES);
        $cookie = ConsentState::COOKIE;

        return <<<HTML
            <div id="consent-banner" role="dialog" aria-live="polite" style="position:fixed;left:0;right:0;bottom:0;z-index:9999;background:#1a1f24;color:#fff;padding:14px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:center;font-size:14px">
              <span>{$text}</span>
              <button type="button" data-consent="granted" style="padding:8px 16px;border:0;border-radius:4px;background:#fff;color:#1a1f24;cursor:pointer">{$accept}</button>
              <button type="button" data-consent="denied" style="padding:8px 16px;border:1px solid #fff;border-radius:4px;background:transparent;color:#fff;cursor:pointer">{$decline}</button>
            </div>
            <script>(function(){var b=document.getElementById('consent-banner');if(!b)return;b.addEventListener('click',function(e){var t=e.target.closest('[data-consent]');if(!t)return;var v=t.getAttribute('data-consent');var g=v==='granted'?'granted':'denied';
            document.cookie='{$cookie}='+v+';path=/;max-age=15552000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'');
            window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
            gtag('consent','update',{ad_storage:g,ad_user_data:g,ad_personalization:g,analytics_storage:g});
            dataLayer.push({event:'consent_update',consent:v});b.remove();});})();</script>
            HTML;
    }
}
