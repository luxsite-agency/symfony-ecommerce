<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo;

/**
 * Чи відкривається шлях на сайті зараз (не 404). Ручне правило з живої адреси не спрацює (редиректи — лише на 404), а
 * злиття ланцюгів переписало б на його ціль правила, що ведуть на живу сторінку, — тому таке правило не приймається.
 */
interface LiveUrls
{
    /** @param string $path нормалізований шлях (RedirectPath::normalize) */
    public function isLive(string $path): bool;
}
