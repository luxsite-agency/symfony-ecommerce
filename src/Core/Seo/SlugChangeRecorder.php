<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo;

/**
 * Авто-301 для змін адрес, зроблених повз ORM (масові SQL-оновлення слагів). Викликати в тій самій транзакції, що й
 * UPDATE слагів, — тоді нова адреса без правила зі старої не з'явиться навіть на мить. Зміни через ORM (адмінка,
 * імпорт) і шляхи категорій (CategoryPaths) записуються самі — для них викликати не треба.
 * Приклад: catalog:slugs:regenerate (RegenerateSlugsCommand) — товари в транзакції пакета UPDATE.
 */
interface SlugChangeRecorder
{
    /**
     * @param iterable<SlugChange> $changes
     *
     * @return int записаних правил
     */
    public function record(iterable $changes): int;
}
