<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Html;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Білий список HTML для текстів, які сайт виводить |raw: описи товарів і категорій, сторінки, записи блогу
 * (консиліум безпеки 10.10 п. 2). Правила — framework.html_sanitizer.sanitizers.app.content_html
 * (config/packages/html_sanitizer.yaml); форми адмінки беруть той самий санітайзер за назвою через опцію sanitize_html.
 * Статті від зовнішньої контент-панелі чистить StudioHtml (своїм списком, із позначенням зовнішніх посилань).
 */
final class ContentHtml
{
    /** Назва санітайзера для опції форми `sanitizer` */
    public const string SANITIZER = 'app.content_html';
    /** max_input_length у html_sanitizer.yaml: довше санітайзер мовчки обрізає по байтах — імпорт про це попереджає */
    public const int MAX_INPUT_LENGTH = 1_000_000;

    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.app.content_html')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    /** Текст довший за ліміт санітайзера — після clean() він буде обрізаний. */
    public function exceedsLimit(?string $html): bool
    {
        return null !== $html && \strlen($html) > self::MAX_INPUT_LENGTH;
    }

    /** Порожнє (або порожнє після чистки) — null: поле опису в БД без порожніх рядків. */
    public function clean(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }
        // <style> у фідах постачальників (Prom тощо): HTML5-парсер санітайзера лишає CSS як звичайний текст — нешкідливо, але
        // в описі товару зайве; знімається блоком до чистки. script/iframe санітайзер знімає разом із вмістом сам.
        $html = preg_replace('#<style\b[^>]*>.*?</style\s*>#is', '', $html) ?? $html;
        // Обрізання до ліміту — тут, по межі символу UTF-8: санітайзер ріже substr по байтах, розірваний символ ламає
        // HTML5-парсер і текст зникає цілком. Імпорт попереджає про обрізання (exceedsLimit) у звіті запуску.
        if (\strlen($html) > self::MAX_INPUT_LENGTH) {
            $html = mb_strcut($html, 0, self::MAX_INPUT_LENGTH);
        }
        $clean = trim($this->sanitizer->sanitize($html));

        return '' === $clean ? null : $clean;
    }
}
