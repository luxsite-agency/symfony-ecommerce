<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Provider;

use App\Core\Analytics\AnalyticsTagProviderInterface;
use App\Core\Analytics\TagContext;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;

/**
 * Meta (Facebook) Pixel — офіційний сніпет + noscript-піксель + стандартні ecommerce-події (ViewContent, AddToCart,
 * InitiateCheckout, Purchase) з ключа meta_pixel подій dataLayer (MetaPixelEventMapper — ті самі дані, що в GA4).
 * Лише за згоди на cookie (ConsentState::granted): без згоди не виводиться нічого — ні fbevents.js, ні noscript, ні
 * подій; після «Прийняти» в банері піксель працює з наступної сторінки. Порожній id (піксель налаштовано в GTM) —
 * нічого, теги Meta в GTM беруть ті самі meta_pixel з dataLayer (docs/analytics.md), без дублювання.
 */
final class FacebookPixelProvider implements AnalyticsTagProviderInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getPriority(): int
    {
        return 70;
    }

    public function head(TagContext $context): string
    {
        $id = $this->id($context);
        if (null === $id) {
            return '';
        }

        return <<<HTML
            <!-- Meta Pixel Code -->
            <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init','{$id}');fbq('track','PageView');</script>
            <!-- End Meta Pixel Code -->
            HTML;
    }

    public function bodyStart(TagContext $context): string
    {
        $id = $this->id($context);
        if (null === $id) {
            return '';
        }

        return <<<HTML
            <noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={$id}&ev=PageView&noscript=1"/></noscript>
            HTML;
    }

    /**
     * Події сторінки — з JSON dataLayer (script[data-analytics-events], його виводить DataLayerEventsProvider нижче,
     * тому читаємо після розбору DOM). eventID — 4-й аргумент fbq для дедуплікації з Conversions API.
     */
    public function bodyEnd(TagContext $context): string
    {
        if (null === $this->id($context)) {
            return '';
        }

        return <<<'HTML'
            <script data-meta-pixel-events>(function(){function run(){var s=document.querySelector('script[data-analytics-events]');if(!s||!window.fbq)return;var list;try{list=JSON.parse(s.textContent);}catch(err){return;}
            list.forEach(function(e){var m=e&&e.meta_pixel;if(m&&m.event){fbq('track',m.event,m.params||{},{eventID:m.event_id});}});}
            if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);}else{run();}})();</script>
            HTML;
    }

    /** id пікселя, якщо він заданий і є згода; інакше null — нічого не виводимо. */
    private function id(TagContext $context): ?string
    {
        if (!$context->consent->granted) {
            return null;
        }

        return SettingKeys::validValue(SettingKeys::FB_PIXEL_ID, $this->settings->get(SettingKeys::FB_PIXEL_ID));
    }
}
