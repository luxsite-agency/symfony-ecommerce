<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Theme;

use App\Site\Theme\Live\LiveTheme;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\AndBinary;
use Twig\Node\Expression\Binary\OrBinary;
use Twig\Node\Expression\BlockReferenceExpression;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\Expression\Unary\NotUnary;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\IfNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Source;
use Twig\Template;

/**
 * Перевірка живого шаблону ДО запису (рішення власника 20.10.2026 «без Twig sandbox-обходів», рев'ю C PR #108 M1–M2 «явний
 * allowlist»): синтаксис (lint) і прохід по AST, у якому дозволене лише те, що перелічено в LiveThemePolicy:
 *   - теги, фільтри, функції, тести — зі списків; невідоме → відмова (render/controller/include_only/attribute/source/dump/
 *     invoke/constant довільних класів сюди не потрапляють);
 *   - глобальна app — лише точним ланцюжком зі списку (app.request.locale, app.request.query.get(…), app.user, app.flashes…);
 *     app, app.request, app.session, app.request.server у будь-якому іншому місці (set, аргумент, with) — відмова, бо через
 *     Request::server живий шаблон читав би DATABASE_URL і ключі з $_SERVER (M1); саме app.request — лише там, де значення
 *     зводиться до bool і нікуди не потрапляє ({% if app.request %}, not, and/or, ?:, is defined/null/empty); _context — відмова;
 *   - властивості й методи (x.name, x.name()) — лише імена зі списку; attribute() заборонено, тож імена завжди літерали;
 *   - extends / include / embed / import / use / block(name, template) — лише літерал site/**.twig або @engine/site/**.twig;
 *   - constant / enum / enum_cases — лише літерал App\…\Enum\…::Case; filter / map / reduce / sort / find — лише зі стрілочною
 *     функцією (рядок-callable 'system' Twig 3 ще приймає).
 * Імена, які шаблон має право ПЕРЕКРИВАТИ, перевіряє LiveTheme::normalize; тут — що він може вживати і на що посилатися.
 */
final class ThemeTemplateGuard
{
    private const array ENUM_FUNCTIONS = ['constant', 'enum', 'enum_cases'];
    private const array ARROW_FILTERS = ['filter', 'map', 'reduce', 'sort', 'find'];
    private const string ENUM_RE = '#^App\\\\[A-Za-z0-9_\\\\]*\\\\Enum\\\\[A-Za-z0-9_]+(::[A-Za-z0-9_]+)?$#';

    public function __construct(private readonly Environment $twig)
    {
    }

    /** @return list<string> проблеми (порожньо — шаблон можна писати) */
    public function check(string $name, string $content): array
    {
        try {
            $module = $this->twig->parse($this->twig->tokenize(new Source($content, $name)));
        } catch (SyntaxError $e) {
            return ['Twig: '.$e->getMessage()];
        }
        $problems = [];
        $this->walk($module, $problems);

        return array_values(array_unique($problems));
    }

    /** Посилання на шаблон, дозволене живій темі: site/**.twig або @engine/site/**.twig. */
    public static function isAllowedReference(string $ref): bool
    {
        $rel = str_starts_with($ref, '@engine/') ? substr($ref, 8) : $ref;

        return 1 === preg_match(LiveTheme::TEMPLATE_RE, $rel) && !str_contains($rel, '..') && !str_contains($rel, '//');
    }

    /** @param list<string> $problems */
    private function walk(Node $node, array &$problems): void
    {
        $tag = $node->getNodeTag();
        if (null !== $tag && !\in_array($tag, LiveThemePolicy::TAGS, true)) {
            $problems[] = \sprintf('{%% %s %%} (рядок %d): тег у живій темі не дозволено', $tag, $node->getTemplateLine());
        }
        if ($node instanceof ModuleNode) {
            if ($node->hasNode('parent')) {
                $this->reference($node->getNode('parent'), 'extends', $problems);
            }
            foreach ($node->getNode('traits') as $trait) {
                $this->reference($trait->getNode('template'), 'use', $problems);
            }
            foreach ($node->getAttribute('embedded_templates') as $embedded) {
                $this->walk($embedded, $problems);
            }
        } elseif ($node instanceof IncludeNode && !$node instanceof EmbedNode) {
            // embed: шаблон-батько — у вкладеному ModuleNode (embedded_templates), його expr тут службовий
            $this->reference($node->getNode('expr'), 'include', $problems);
        } elseif ($node instanceof ImportNode) {
            $expr = $node->getNode('expr');
            if ($expr instanceof ContextVariable && '_self' === $expr->getAttribute('name')) {
                $this->walk($node->getNode('var'), $problems); // {% import _self as m %} — єдине законне місце для _self

                return;
            }
            $this->reference($expr, 'import', $problems);
        } elseif ($node instanceof BlockReferenceExpression && $node->hasNode('template')) {
            $this->reference($node->getNode('template'), 'block()', $problems);
        } elseif ($node instanceof GetAttrExpression) {
            if ($this->attribute($node, $problems)) {
                return; // дозволений ланцюжок app.…: усередину не йдемо (там сама app), аргументи вже перевірено
            }
        } elseif ($node instanceof ContextVariable) {
            $this->variable($node, $problems);
        } elseif ($node instanceof FunctionExpression) {
            $this->function($node, $problems);
        } elseif ($node instanceof FilterExpression) {
            $this->filter($node, $problems);
        } elseif ($node instanceof TestExpression) {
            $name = (string) $node->getAttribute('name');
            if (!\in_array($name, LiveThemePolicy::TESTS, true)) {
                $problems[] = \sprintf('is %s (рядок %d): тест у живій темі не дозволено', $name, $node->getTemplateLine());
            }
        }
        $this->children($node, $problems);
    }

    /**
     * Діти вузла; саме app.request (без ланки далі) пропускається лише там, де воно зводиться до bool: умова if, операнд
     * not/and/or, умова ?:, операнд is defined|null|empty (Twig 3.30 сам загортає такі операнди в is true) — base рушія так
     * перевіряє, чи є запит. a ?: b expr1 і expr2 — один
     * вузол, тож як значення (expr2) воно все одно перевіриться і відмовиться.
     *
     * @param list<string> $problems
     */
    private function children(Node $node, array &$problems): void
    {
        if ($node instanceof IfNode) {
            foreach ($node->getNode('tests') as $i => $child) {
                if (0 === (int) $i % 2 && self::isBareAppRequest($child)) {
                    continue;
                }
                $this->walk($child, $problems);
            }
            if ($node->hasNode('else')) {
                $this->walk($node->getNode('else'), $problems);
            }

            return;
        }
        $boolean = match (true) {
            $node instanceof AndBinary, $node instanceof OrBinary => ['left', 'right'],
            $node instanceof NotUnary => ['node'],
            $node instanceof ConditionalExpression => ['expr1'],
            $node instanceof TestExpression => \in_array($node->getAttribute('name'), ['true', 'defined', 'null', 'empty'], true) ? ['node'] : [],
            default => [],
        };
        foreach ($node as $key => $child) {
            if (\in_array((string) $key, $boolean, true) && self::isBareAppRequest($child)) {
                continue;
            }
            $this->walk($child, $problems);
        }
    }

    private static function isBareAppRequest(Node $node): bool
    {
        if (!$node instanceof GetAttrExpression || Template::ARRAY_CALL === $node->getAttribute('type')) {
            return false;
        }
        $attr = $node->getNode('attribute');
        $root = $node->getNode('node');

        return $attr instanceof ConstantExpression && 'request' === $attr->getAttribute('value') && $root instanceof ContextVariable && 'app' === $root->getAttribute('name');
    }

    /**
     * x.name / x.name(): ім'я — літерал зі списку; ланцюжок від app — точний зі списку (аргументи його викликів перевіряються
     * тут, сам ланцюжок далі не обходиться). x['name'] (масив) імені не перевіряє: на об'єкті без ArrayAccess Twig метод не
     * викличе. Імена інших ланок ланцюжка (не app) перевіряє рекурсія walk — кожна ланка сама є GetAttrExpression.
     *
     * @param list<string> $problems
     *
     * @return bool true — ланцюжок app оброблено цілком, у walk далі не йти
     */
    private function attribute(GetAttrExpression $node, array &$problems): bool
    {
        $line = $node->getTemplateLine();
        $chain = [];
        $arguments = [];
        $dynamic = false;
        $root = $node;
        while ($root instanceof GetAttrExpression) {
            $attr = $root->getNode('attribute');
            $name = $attr instanceof ConstantExpression ? $attr->getAttribute('value') : null;
            if (!\is_string($name)) {
                $dynamic = true;
                $name = '?';
            }
            array_unshift($chain, [$name, (string) $root->getAttribute('type')]);
            if ($root->hasNode('arguments')) {
                $arguments[] = $root->getNode('arguments');
            }
            $root = $root->getNode('node');
        }
        [$own, $ownType] = $chain[\count($chain) - 1];
        $isApp = $root instanceof ContextVariable && 'app' === $root->getAttribute('name');
        if (!$isApp) {
            if (Template::ARRAY_CALL === $ownType) {
                return false; // x[key] — масив будь-яким ключем, і динамічним теж: метод чи властивість так не дістати
            }
            if ('?' === $own) {
                $problems[] = \sprintf('attribute() або динамічне ім\'я властивості (рядок %d): у живій темі лише x.name літералом', $line);
            } elseif (!\in_array($own, LiveThemePolicy::ATTRIBUTES, true)) {
                $problems[] = \sprintf('.%s (рядок %d): властивість чи метод у живій темі не дозволено', $own, $line);
            }

            return false;
        }
        if ($dynamic) {
            $problems[] = \sprintf('attribute() на app (рядок %d): у живій темі лише app.name літералом', $line);
        }
        // app.…: лише точний ланцюжок зі списку (request, query… у ATTRIBUTES нема навмисно) або app.user.<властивість зі списку>
        $names = array_column($chain, 0);
        $path = implode('.', $names);
        if (\in_array($path, LiveThemePolicy::APP_CHAINS, true)) {
            // ок
        } elseif ('user' === $names[0]) {
            foreach (\array_slice($names, 1) as $name) {
                if (!\in_array($name, LiveThemePolicy::ATTRIBUTES, true)) {
                    $problems[] = \sprintf('app.%s (рядок %d): властивість користувача у живій темі не дозволено', $path, $line);
                }
            }
        } else {
            $problems[] = \sprintf('app.%s (рядок %d): з app у живій темі дозволено лише %s', $path, $line, implode(', ', array_map(static fn (string $c): string => 'app.'.$c, LiveThemePolicy::APP_CHAINS)));
        }
        foreach ($arguments as $args) {
            $this->walk($args, $problems);
        }

        return true;
    }

    /** @param list<string> $problems */
    private function variable(ContextVariable $node, array &$problems): void
    {
        $name = (string) $node->getAttribute('name');
        if ('app' === $name) {
            $problems[] = \sprintf('app (рядок %d): лише як app.request.locale, app.request.query.get(…), app.user, app.flashes… — не сама змінна (set, аргумент, with)', $node->getTemplateLine());
        } elseif (\in_array($name, LiveThemePolicy::DENIED_VARIABLES, true)) {
            $problems[] = \sprintf('%s (рядок %d): змінна у живій темі не дозволена', $name, $node->getTemplateLine());
        }
    }

    /** @param list<string> $problems */
    private function reference(Node $expr, string $where, array &$problems): void
    {
        $value = $expr instanceof ConstantExpression ? $expr->getAttribute('value') : null;
        if (!\is_string($value)) {
            $problems[] = \sprintf('%s (рядок %d): шаблон лише літералом site/… або @engine/site/…, не виразом', $where, $expr->getTemplateLine());

            return;
        }
        if (!self::isAllowedReference($value)) {
            $problems[] = \sprintf('%s (рядок %d): «%s» — дозволені лише site/**.twig і @engine/site/**.twig', $where, $expr->getTemplateLine(), $value);
        }
    }

    /** @param list<string> $problems */
    private function function(FunctionExpression $node, array &$problems): void
    {
        $name = (string) $node->getAttribute('name');
        $line = $node->getTemplateLine();
        if (!\in_array($name, LiveThemePolicy::FUNCTIONS, true)) {
            $problems[] = \sprintf('%s() (рядок %d): функцію у живій темі не дозволено', $name, $line);

            return;
        }
        $arguments = $node->getNode('arguments');
        if ('include' === $name) {
            $first = $arguments->hasNode('0') ? $arguments->getNode('0') : ($arguments->hasNode('template') ? $arguments->getNode('template') : null);
            if (null === $first) {
                $problems[] = \sprintf('include() (рядок %d): без шаблону', $line);
            } else {
                $this->reference($first, 'include()', $problems);
            }

            return;
        }
        if (\in_array($name, self::ENUM_FUNCTIONS, true)) {
            $first = $arguments->hasNode('0') ? $arguments->getNode('0') : null;
            $value = $first instanceof ConstantExpression ? $first->getAttribute('value') : null;
            if (\count(iterator_to_array($arguments)) !== 1 || !\is_string($value) || 1 !== preg_match(self::ENUM_RE, $value)) {
                $problems[] = \sprintf('%s() (рядок %d): лише літерал App\\…\\Enum\\…::Case (enum-и магазину), не сталі довільних класів', $name, $line);
            }
        }
    }

    /** @param list<string> $problems */
    private function filter(FilterExpression $node, array &$problems): void
    {
        $name = (string) $node->getAttribute('name');
        if (!\in_array($name, LiveThemePolicy::FILTERS, true)) {
            $problems[] = \sprintf('|%s (рядок %d): фільтр у живій темі не дозволено', $name, $node->getTemplateLine());

            return;
        }
        if (!\in_array($name, self::ARROW_FILTERS, true)) {
            return;
        }
        $arguments = $node->getNode('arguments');
        $callable = $arguments->hasNode('0') ? $arguments->getNode('0') : ($arguments->hasNode('arrow') ? $arguments->getNode('arrow') : null);
        if (null === $callable && 'sort' === $name) {
            return;
        }
        if (!$callable instanceof ArrowFunctionExpression) {
            $problems[] = \sprintf('|%s (рядок %d): лише стрілочна функція ((v, k) => …), не рядок-callable', $name, $node->getTemplateLine());
        }
    }
}
