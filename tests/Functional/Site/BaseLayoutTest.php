<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/** Базовий layout: блок head для сторінок/Store; порожні alternates = без canonical і hreflang (не головна). */
final class BaseLayoutTest extends KernelTestCase
{
    public function testHeadBlockAndEmptyAlternates(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var string $host */
        $host = $container->getParameter('site_host');
        $request = Request::create('https://'.$host.'/x');
        $request->setLocale($container->getParameter('app.default_locale'));
        $container->get(RequestStack::class)->push($request);
        $twig = $container->get(Environment::class);

        $html = $twig->createTemplate(<<<'TWIG'
            {% extends 'site/base.html.twig' %}
            {% set alternates = {} %}
            {% block head %}<meta name="x-head-block" content="1">{% endblock %}
            {% block body %}content{% endblock %}
            TWIG)->render();

        self::assertStringContainsString('<meta name="x-head-block" content="1">', $html, '{% block head %} рендериться в <head>');
        self::assertStringNotContainsString('rel="canonical"', $html, 'Порожні alternates — без canonical (раніше |default давав головну)');
        self::assertStringNotContainsString('rel="alternate"', $html, 'hreflang-link-и відсутні (перемикач мов лишається)');
        self::assertStringContainsString('content', $html);

        $html = $twig->createTemplate("{% extends 'site/base.html.twig' %}{% block body %}home{% endblock %}")->render();
        self::assertStringContainsString('rel="canonical" href="https://'.$host.'/"', $html, 'Без alternates — за замовчуванням головна');
        if (\in_array('uk', $container->getParameter('app.locales'), true)) {
            self::assertMatchesRegularExpression('#class="lang"[^>]*>.*?>UA</#s', $html, 'Перемикач мов: uk → UA (правило власника 09.10.2026)');
            self::assertDoesNotMatchRegularExpression('#>UK</#', $html);
        }
    }
}
