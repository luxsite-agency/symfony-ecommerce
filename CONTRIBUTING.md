# Як долучитися

Рушій розвиває LuxSite разом із запрошеними розробниками. Гілка `main` захищена: зміни потрапляють туди лише через pull request,
після зеленого CI і рев'ю мейнтейнера (`.github/CODEOWNERS`). Теги й релізи ставлять мейнтейнери.

## Порядок
1. Форк репозиторію (або гілка тут, для команди) від актуального `main`: `feat/…`, `fix/…`, `chore/…`.
2. Один PR — одна тема. В описі: навіщо, що змінено, як перевірено. Нова поведінка магазину вмикається параметром паку або
   міграцією, магазини без цього параметра виглядають і працюють як раніше.
3. CI має бути зеленим: `composer audit`, `lint:yaml`, `lint:twig`, `lint:container`, шапки SPDX (`python3 tools/spdx-headers.py --check`),
   `php-cs-fixer` (`@Symfony`), PHPStan, міграції на порожній БД і `doctrine:schema:validate`, PHPUnit, `bash tests/store/run.sh`.
4. Схема БД змінюється лише міграціями (`bin/console make:migration`), назва `Version<дата><час>` з реальним часом.
   Одну міграцію двоє не редагують.
5. Новий файл коду в `src/` чи `bin/` починається з шапки:
   ```php
   // SPDX-License-Identifier: Apache-2.0
   // Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
   ```
   `python3 tools/spdx-headers.py` допише її сам.
6. Секрети, дампи, реальні домени й дані клієнтів у репозиторій не потрапляють.

## Підпис внеску (DCO)
Кожен коміт підписуйте `git commit -s`. Рядок `Signed-off-by: Ім'я <email>` означає, що ви маєте право передати цей код під
Apache License 2.0 ([Developer Certificate of Origin 1.1](https://developercertificate.org/)). PR без підпису не зливається.

## Рев'ю
Кожен PR переглядає мейнтейнер з `.github/CODEOWNERS`, без його схвалення злиття неможливе. Зауваження рев'ю виправляються
новими комітами в ту саму гілку, без переписування історії після початку рев'ю.

## Стиль
PHP 8.4, `declare(strict_types=1)`, Symfony 8.1 за [best practices](https://symfony.com/doc/current/best_practices.html):
атрибути замість YAML, autowiring, тонкі контролери. Гроші — `Core\Shared\Money` у мінорних одиницях, без float.
Коментарі пояснюють «навіщо», а не «що». Правила для агентів — `AGENTS.md`.
