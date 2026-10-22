# Необов'язкові пакети (аддони)

Рушій ставиться, працює і проходить тести без них (CI — без аддонів). Аддон — composer-пакет, який магазин підключає за
бажанням; у `composer.json` рушія він лише в `suggest`, у `composer.lock` і в переліку компонентів `/license` його нема.
Реєстр аддонів — `config/addons/addons.yaml`.

| Ключ | Пакет | Що дає | Параметр паку |
|---|---|---|---|
| `gmb-reviews` | `luxsite/gmb-reviews-bundle` (+ `-core`) | оцінка й відгуки магазину з gbp-manager (платний сервіс LuxSite, docs/reviews.md) | `app.gbp_reviews.token` |

## Як магазин підключає аддон
У паку — два рядки:
```yaml
# store.yaml
addons: [gmb-reviews]
# config/parameters.yaml
parameters:
    app.gbp_reviews.token: '<публічний токен віджета з gbp-manager>'
```
- `bin/store validate`: невідомий ключ або аддон, якого нема в цій копії рушія (публічна копія — без приватних пакетів), —
  помилка; аддон без параметра або параметр без аддона — попередження (блок не покажеться).
- `bin/store deploy` пише в контекст збірки `packages/addons.require` (аргументи `composer require` з реєстру),
  `docker/php/Dockerfile.prod` після `composer install` ставить їх поверх `composer.lock` (`--no-plugins`: Flex не чіпає
  `config/bundles.php`). Решта залежностей лишається зафіксованою; нові залежності аддона (`psr/simple-cache`) — за його
  `composer.json`. `store.yaml` входить у хеш паку: увімкнення аддона перезбирає образ.

## Як рушій вмикає аддон
- `Kernel::ADDON_BUNDLES` — бандл ⇒ конфіг у `config/addons/`. Бандл і конфіг реєструються, лише коли клас бандла є у
  `vendor/` (`class_exists`), окремого прапорця нема. У `config/bundles.php` аддонів нема.
- Без `luxsite/gmb-reviews-bundle` Kernel реєструє `App\Site\Twig\GmbReviewsFallbackExtension`: Twig-функції `gmb_*`
  (`gmb_reviews`, `gmb_rating_badge`, `gmb_aggregate_rating`, …) віддають порожньо/`null`, тож шаблони рушія й тем
  компілюються однаково, а блоки оцінки на головній і в картці товару просто не показуються. `OrganizationSchema` без
  `Luxsite\GmbReviews\Client` не додає `aggregateRating`.
- Тести: `GmbReviewsOptionalTest` — в обох станах; `GbpRatingTest` — лише з пакетом (без нього пропускається). phpstan
  ігнорує `Luxsite\GmbReviews*` у кількох файлах (`reportUnmatched: false`).

## Dev
```bash
bash local/addon.sh gmb-reviews on  <worktree>   # пакет у vendor/ worktree, composer.json/lock лишаються як у git
bash local/wt.sh exec <worktree> bin/phpunit tests/Functional/Site/GbpRatingTest.php
bash local/addon.sh gmb-reviews off <worktree>   # composer install за lock — пакет зникає
```
Наступний `composer install` у worktree теж прибирає аддон. Код пакета — копія з репо gmb-manager у `packages/`
(`local/sync-gmb-reviews.sh`); у публічній копії рушія (`tools/export-public`) її нема.

## Новий аддон
Пакет у `packages/<vendor>/<name>` (або приватний composer-репозиторій), рядок у `config/addons/addons.yaml`, бандл у
`Kernel::ADDON_BUNDLES` і конфіг у `config/addons/`, `suggest` у `composer.json`; шаблони рушія не мають викликати функції
аддона без заглушки. Тест — в обох станах.
