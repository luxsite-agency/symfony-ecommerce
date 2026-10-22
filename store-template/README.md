# Пак магазину storetemplate — Symfony ecommerce LuxSite

Шаблон паку (`store-template/` у репо рушія). Новий магазин: `bin/store new <key> <домен>` — копія цієї теки з підставленими
ключем, доменом і назвою. Формат — `docs/store-pack.md` рушія; перевірка — `bin/store validate <тека паку>`.

| Шлях | Що | Куди при деплої |
|---|---|---|
| `store.yaml` | ключ, назва, мови, домени, версія рушія | читає `bin/store` |
| `config/parameters.yaml` | тема, назва, параметри рушія (без хостів і секретів) | `config/store/parameters.local.yaml` образу (+ хости з реєстру) |
| `themes/storetemplate/` | лого, шрифти, банер, `theme.css` | `public/themes/storetemplate/` образу |
| `catalog/*.csv` | початковий каталог | `bin/console catalog:import:feed` (один раз, далі правда — БД) |
| `content/` | сторінки, тексти категорій | вносяться в адмінці/імпортом при запуску |
| `seo/` | ключі, мета, карта перелінковки | вносяться при запуску |
| `studio/` | персона й промпти зовнішньої контент-панелі | налаштування сайту у зовнішній контент-панелі |
| `docs/brief.md`, `docs/CLAUDE.md`, `docs/launch-checklist.md` | бриф, правила агента магазину, чекліст запуску | — |

Після першого імпорту правдою для каталогу й текстів є БД магазину (адмінка, CRM), не цей репо: деплой БД не чіпає.

## Свій дизайн (необов'язково)

Типовий вигляд — шаблони рушія з кольорами й лого з `config/parameters.yaml` (`app.theme`) і `themes/<key>/theme.css`.
Інша верстка — Twig у `themes/<key>/templates/` паку: `bin/store theme new <key>` у рушії створює заготовки
`site/base.html.twig`, `site/home.html.twig` (`{% extends '@engine/…' %}`, усі блоки = `parent()`) і маніфест `theme.yaml`;
`bin/store theme list` — що ще можна перекрити; `--copy <шаблон>` — повна копія; після оновлення рушія `bin/store theme diff <key>`.
Лише `site/**` і `*.twig`; кабінет, schema.org, sitemap, листи, адмінку пак не перекриває. Деталі — `docs/themes.md` рушія.
