<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Studio;

use Dom\HTMLDocument;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * HTML статті від зовнішньої контент-панелі перед записом у PostTranslation::body.
 *
 * Білий список: заголовки h2–h4, абзаци, списки, strong/em, посилання, таблиці, цитати й картинки в тексті
 * (<figure><img><figcaption> — панель вставляє їх з абсолютними адресами). Невідомі теги (div, span, h1) знімаються
 * з текстом усередині, script/style/iframe/форми — разом із вмістом, атрибути поза списком (style, on*) — теж.
 * Посилання лише http(s)/mailto/tel або відносні. Зовнішні посилання отримують rel="nofollow noopener" і target=_blank,
 * внутрішні (відносні або на хости магазину) лишаються як є.
 */
final class StudioHtml
{
    private readonly HtmlSanitizerInterface $sanitizer;

    public function __construct(private readonly TranslatorInterface $translator)
    {
        $config = (new HtmlSanitizerConfig())
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->withMaxInputLength(1_000_000);
        foreach (['h2', 'h3', 'h4'] as $heading) {
            $config = $config->allowElement($heading, ['id']);
        }
        foreach (['p', 'br', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'figcaption'] as $element) {
            $config = $config->allowElement($element);
        }
        $config = $config
            ->allowElement('a', ['href', 'title', 'rel', 'target'])
            ->allowElement('blockquote', ['cite'])
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('figure', ['class'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'loading']);
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'head', 'title', 'meta', 'link'] as $element) {
            $config = $config->dropElement($element);
        }
        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * @param list<string> $ownHosts хости магазину (запиту й канонічний): посилання на них — внутрішні
     */
    public function body(string $html, array $ownHosts): string
    {
        $clean = trim($this->sanitizer->sanitize($html));
        if ('' === $clean) {
            return '';
        }

        return $this->markExternalLinks($clean, $ownHosts);
    }

    /**
     * Блок «Джерела» в кінці статті: link — посилання nofollow, text — лише назви, off або порожньо — без блоку.
     *
     * @param array<mixed> $sources list<{title, url}>
     */
    public function sources(array $sources, string $format, string $locale): string
    {
        if ('off' === $format) {
            return '';
        }
        $items = '';
        foreach ($sources as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = self::text($row['url'] ?? null);
            // не filter_var(FILTER_VALIDATE_URL): він відкидає кириличні шляхи (uk.wikipedia.org/wiki/Шеврон)
            $isUrl = \in_array(strtolower((string) parse_url($url, \PHP_URL_SCHEME)), ['http', 'https'], true)
                && '' !== (string) parse_url($url, \PHP_URL_HOST) && !preg_match('/[\s"<>]/', $url);
            $title = self::text($row['title'] ?? null) ?: ($isUrl ? (string) parse_url($url, \PHP_URL_HOST) : '');
            if ('' === $title) {
                continue;
            }
            $items .= 'link' === $format && $isUrl
                ? \sprintf("<li><a href=\"%s\" rel=\"nofollow noopener\" target=\"_blank\">%s</a></li>\n", self::escape($url), self::escape($title))
                : \sprintf("<li>%s</li>\n", self::escape($title));
        }

        return '' === $items ? '' : \sprintf("\n<section class=\"post-sources\">\n<h2>%s</h2>\n<ul>\n%s</ul>\n</section>\n", self::escape($this->translator->trans('site.blog.sources', [], 'messages', $locale)), $items);
    }

    /** @param list<string> $ownHosts */
    public static function isInternal(string $href, array $ownHosts): bool
    {
        $href = trim($href);
        if ('' === $href || str_starts_with($href, '#') || (str_starts_with($href, '/') && !str_starts_with($href, '//'))) {
            return true;
        }
        $scheme = strtolower((string) parse_url($href, \PHP_URL_SCHEME));
        if (\in_array($scheme, ['mailto', 'tel'], true)) {
            return true;
        }
        $host = strtolower((string) parse_url(str_starts_with($href, '//') ? 'https:'.$href : $href, \PHP_URL_HOST));
        if ('' === $host) {
            return '' === $scheme; // відносне посилання без слеша (page/x)
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        foreach ($ownHosts as $own) {
            $own = preg_replace('/^www\./', '', strtolower($own)) ?? $own;
            if ('' !== $own && ($host === $own || str_ends_with($host, '.'.$own))) {
                return true;
            }
        }

        return false;
    }

    /** Текст без тегів і крайніх пробілів (FAQ, підписи, назви джерел приходять рядками). */
    public static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim(html_entity_decode(strip_tags((string) $value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')) : '';
    }

    /** @param list<string> $ownHosts */
    private function markExternalLinks(string $html, array $ownHosts): string
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', \LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;
        if (null === $body) {
            return $html;
        }
        foreach ($body->querySelectorAll('a[href]') as $link) {
            if (self::isInternal((string) $link->getAttribute('href'), $ownHosts)) {
                continue;
            }
            $rel = array_filter(preg_split('/\s+/', strtolower((string) $link->getAttribute('rel'))) ?: []);
            foreach (['nofollow', 'noopener'] as $value) {
                if (!\in_array($value, $rel, true)) {
                    $rel[] = $value;
                }
            }
            $link->setAttribute('rel', implode(' ', $rel));
            $link->setAttribute('target', '_blank');
        }

        return $body->innerHTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
