<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Provider;

use App\Core\Analytics\AnalyticsTagProviderInterface;
use App\Core\Analytics\DataLayerCollector;
use App\Core\Analytics\TagContext;

/**
 * Перед </body>: зібрані серверні події → JSON у <script type="application/json"> + JS: push кожної в dataLayer
 * (GA4 ecommerce вимагає {ecommerce: null} перед подією; meta_pixel чиститься так само — інакше GTM злив би його з
 * попередньою подією), делегований select_item по кліку на [data-analytics-select].
 */
final class DataLayerEventsProvider implements AnalyticsTagProviderInterface
{
    public function __construct(private readonly DataLayerCollector $collector)
    {
    }

    public static function getPriority(): int
    {
        return 10;
    }

    public function head(TagContext $context): string
    {
        return '';
    }

    public function bodyStart(TagContext $context): string
    {
        return '';
    }

    public function bodyEnd(TagContext $context): string
    {
        $events = json_encode($this->collector->all(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP);

        return <<<HTML
            <script type="application/json" data-analytics-events>{$events}</script>
            <script>(function(){window.dataLayer=window.dataLayer||[];function push(e){if(e&&e.ecommerce){dataLayer.push({ecommerce:null,meta_pixel:null});}dataLayer.push(e);}
            var s=document.querySelector('script[data-analytics-events]');if(s){try{JSON.parse(s.textContent).forEach(push);}catch(err){}}
            document.addEventListener('click',function(ev){var a=ev.target.closest('[data-analytics-select]');if(!a)return;try{push(JSON.parse(a.getAttribute('data-analytics-select')));}catch(err){}});})();</script>
            HTML;
    }
}
