<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Http;

/**
 * Шляхи кабінету покупця: /account… і /<мова>/account… (як access_control у security.yaml). Під ними адреси листів
 * несуть токени (/account/verify/{token}, /account/password/{token}, login-link/check?…&hash=), тож такі шляхи й
 * referer з них не йдуть ні в аналітику, ні в журнал 404 (рев'ю безпеки #45). Перевірка за шляхом, а не за маршрутом:
 * 404 з обрізаним токеном маршруту не має, а сторінка помилки рендериться підзапитом без атрибутів.
 */
final class AccountPath
{
    public const string PATTERN = '#^/(?:[a-z]{2,3}(?:_[A-Z]{2})?/)?account(?:/|$)#i';

    public static function matches(string $pathInfo): bool
    {
        return 1 === preg_match(self::PATTERN, rawurldecode($pathInfo));
    }
}
