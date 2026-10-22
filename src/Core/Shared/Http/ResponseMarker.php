<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Http;

use App\Core\Shared\Runtime\BuildInfo;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Підпис рушія у відповідях сайту й адмінки (власник 19.10.2026) незалежно від теми й налаштувань футера: заголовок X-Powered-By;
 * у HTML (200 і 4xx) — коментар одразу після <!DOCTYPE>, data-атрибут на <html> і <meta name="generator"> на початку <head>, якщо
 * шаблон (тема паку, що перекрила base.html.twig чи блок meta_generator) свого з нашою назвою не вивів; у XML (sitemap) — коментар
 * після <?xml?>. Нічого видимого. Пріоритет -1000: після всіх, хто змінює тіло (тулбар профайлера -128, SecurityResponseHeaders
 * -200), — Content-Length рахує Response::prepare() уже з підписом; ETag рушій не ставить (фід Merchant — файл, підпис у генераторі).
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1000)]
final class ResponseMarker
{
    /** Скільки байтів від початку шукати <html>/<head>: вони завжди на початку документа. */
    private const int HEAD_WINDOW = 4096;

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('X-Powered-By', BuildInfo::NAME);
        if (!headers_sent()) {
            header_remove('X-Powered-By'); // «PHP/8.x» з expose_php (стенди): Response::sendHeaders() його не замінює, а дописує
        }
        $status = $response->getStatusCode();
        if ((200 !== $status && ($status < 400 || $status >= 500))
            || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return;
        }
        $type = strtolower((string) $response->headers->get('Content-Type', ''));
        $content = $response->getContent();
        if (false === $content || '' === $content) {
            return;
        }
        $marked = match (true) {
            str_starts_with($type, 'text/html') => self::html($content),
            str_contains($type, 'xml') => self::xml($content),
            default => $content,
        };
        if ($marked !== $content) {
            $response->setContent($marked);
        }
    }

    public static function html(string $html): string
    {
        $head = substr($html, 0, self::HEAD_WINDOW);
        if (!preg_match('/<html\b[^>]*>/i', $head, $tag, \PREG_OFFSET_CAPTURE)) {
            return $html; // фрагмент (turbo/ajax-відповідь), не документ
        }
        [$htmlTag, $htmlAt] = $tag[0];
        $name = htmlspecialchars(BuildInfo::NAME, \ENT_QUOTES);

        // <meta name="generator"> — одразу після <head …> (і після <meta charset>, щоб кодування лишалось у перших байтах)
        if (!preg_match('/<meta\s[^>]*name=["\']?generator["\']?[^>]*'.preg_quote($name, '/').'/i', $head)
            && preg_match('/<head\b[^>]*>(\s*<meta\s+charset=[^>]*>)?/i', $head, $m, \PREG_OFFSET_CAPTURE, $htmlAt)) {
            $at = $m[0][1] + \strlen($m[0][0]);
            $html = substr_replace($html, "\n".'<meta name="generator" content="'.$name.'">', $at, 0);
        }
        if (!str_contains($htmlTag, ' data-engine=')) {
            $html = substr_replace($html, ' data-engine="'.$name.'"', $htmlAt + 5, 0);
        }
        // коментар — після <!DOCTYPE …> (перед ним — ризик quirks mode), без doctype — перед <html>
        $comment = '<!-- '.BuildInfo::LINE.' -->';
        if (str_contains($head, $comment)) {
            return $html;
        }
        if (preg_match('/^\s*<!doctype[^>]*>/i', $html, $d)) {
            return substr_replace($html, "\n".$comment, \strlen($d[0]), 0);
        }

        return substr_replace($html, $comment."\n", $htmlAt, 0);
    }

    public static function xml(string $xml): string
    {
        $comment = '<!-- '.BuildInfo::LINE.' -->';
        if (!preg_match('/^\s*<\?xml[^>]*\?>/', $xml, $d) || str_contains(substr($xml, 0, 512), $comment)) {
            return $xml;
        }

        return substr_replace($xml, "\n".$comment, \strlen($d[0]), 0);
    }
}
