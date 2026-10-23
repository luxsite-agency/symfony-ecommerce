# Symfony ecommerce LuxSite

An open-source e-commerce engine built on Symfony. One engine serves many stores: each store is described by
its own pack (parameters, theme, translations, and custom templates if needed), and `bin/store` deploys, updates and backs them up.

Symfony 8.1, PHP 8.4, MySQL 8.0, EasyAdmin, Messenger. Interface languages: uk, en, ru.

## Features
- **Catalog:** products with variants, attributes and filters, options and bundles, price on request, CSV/XML feed import.
- **Pricing:** promotions, wholesale prices and wholesale applications, price list.
- **Customers:** cart, checkout with Nova Poshta, customer account, Google sign-in, favorites and recently viewed, reviews.
- **Integrations:** KeyCRM (orders, stock, webhook), Google Merchant and Meta feeds, GA4 ecommerce and Meta Pixel.
- **SEO:** translated URLs, hreflang, schema.org, sitemap, redirects from legacy URLs.
- **Admin and API:** EasyAdmin, personal access tokens, REST and MCP for Claude Code, AI features using the store's OpenRouter key.
- **Themes:** a pack overrides the engine's templates; a theme contract and `bin/store theme update` handle updates.

## Quick start
```bash
composer install
cp .env .env.local                      # DATABASE_URL, APP_SECRET, MAILER_DSN
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console app:demo:seed           # demo catalog
symfony serve -d
```

Documentation: [`docs/store-pack.md`](docs/store-pack.md) (store pack, parameters, registry), [`docs/themes.md`](docs/themes.md)
(themes), [`docs/admin-api.md`](docs/admin-api.md) (tokens, REST, MCP), [`docs/feeds.md`](docs/feeds.md), [`docs/analytics.md`](docs/analytics.md).

## Development
Changes go through a pull request to `main` with green CI: lint, PHPStan, php-cs-fixer, migrations against the schema, PHPUnit, `bin/store` tests.
How to contribute: [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License
Apache License 2.0, full text in [`LICENSE`](LICENSE). You are free to use, modify and run the engine, including in
commercial projects. Copies and derivative works must keep the [`NOTICE`](NOTICE) file crediting the platform developers.
Third-party libraries are distributed under their own licenses: [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).

## Platform developers
**[LuxSite](https://luxsite.agency/).** The LuxSite name and logo are trademarks; the code license grants no rights to them.
Turnkey store launch, content, analytics and support: [luxsite.agency](https://luxsite.agency/).
