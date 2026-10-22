# Symfony ecommerce LuxSite

Рушій інтернет-магазинів з відкритим кодом на Symfony. Один рушій обслуговує багато магазинів: кожен магазин описаний
окремим паком (параметри, тема, переклади, за потреби свої шаблони), а `bin/store` розгортає, оновлює й бекапить їх.

Symfony 8.1, PHP 8.4, MySQL 8.0, EasyAdmin, Messenger. Мови інтерфейсу: uk, en, ru.

## Що вміє
- **Каталог:** товари з варіантами, характеристики й фільтри, опції й набори, ціни за запитом, імпорт фідів CSV/XML.
- **Ціни:** акції, оптові ціни й заявки оптовиків, прайс-лист.
- **Покупець:** кошик, оформлення з Новою поштою, кабінет, вхід через Google, обране й переглянуте, відгуки.
- **Інтеграції:** KeyCRM (замовлення, залишки, вебхук), фіди Google Merchant і Meta, GA4 ecommerce і Meta Pixel.
- **SEO:** перекладені URL, hreflang, schema.org, sitemap, редиректи зі старих адрес.
- **Адмінка й API:** EasyAdmin, персональні токени, REST і MCP для Claude Code, AI-функції на ключі OpenRouter магазину.
- **Теми:** пак перекриває шаблони рушія, контракт теми і `bin/store theme update` для оновлень.

## Швидкий старт
```bash
composer install
cp .env .env.local                      # DATABASE_URL, APP_SECRET, MAILER_DSN
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console app:demo:seed           # демо-каталог
symfony serve -d
```

Документація: [`docs/store-pack.md`](docs/store-pack.md) (пак магазину, параметри, реєстр), [`docs/themes.md`](docs/themes.md)
(теми), [`docs/admin-api.md`](docs/admin-api.md) (токени, REST, MCP), [`docs/feeds.md`](docs/feeds.md), [`docs/analytics.md`](docs/analytics.md).

## Розробка
Зміни через pull request у `main` із зеленим CI: lint, PHPStan, php-cs-fixer, міграції проти схеми, PHPUnit, тести `bin/store`.
Як долучитися: [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Ліцензія
Apache License 2.0, повний текст у [`LICENSE`](LICENSE). Рушій можна вільно використовувати, змінювати й запускати, зокрема в
комерційних проєктах. У копіях і похідних роботах зберігається файл [`NOTICE`](NOTICE) зі згадкою про розробників платформи.
Сторонні бібліотеки поширюються на умовах своїх ліцензій: [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).

## Розробники платформи
**[LuxSite](https://luxsite.agency/).** Назва й логотип LuxSite є торговельною маркою, ліцензія на код права на них не дає.
Запуск магазину під ключ, наповнення, аналітика й підтримка: [luxsite.agency](https://luxsite.agency/).

---

*Symfony ecommerce LuxSite is an open-source multi-store e-commerce engine built on Symfony. Licensed under Apache 2.0;
platform developed by LuxSite. Keep the NOTICE file in copies and derivative works.*
