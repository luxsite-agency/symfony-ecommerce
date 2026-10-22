<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Command;

use App\Core\Content\Entity\Post;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** catalog:html:sanitize: dry-run нічого не пише; звичайний запис чиститься; стаття зовнішньої контент-панелі (studio_group_id) не чіпається. */
final class SanitizeHtmlCommandTest extends KernelTestCase
{
    public function testCleansManualPostsAndSkipsStudioArticles(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));

        $studioBody = "<p>Текст статті</p>\n<section class=\"post-sources\">\n<h2>Джерела</h2>\n<ul>\n<li><a href=\"https://example.com/\" rel=\"nofollow noopener\" target=\"_blank\">Джерело</a></li>\n</ul>\n</section>\n<script>alert('studio')</script>";
        $studio = new Post();
        $studio->setStudioGroupId('studio-'.$suffix);
        $studio->translate('uk')->setTitle('Стаття студії')->setSlug('studio-'.$suffix)->setBody($studioBody);
        $manual = new Post();
        $manual->translate('uk')->setTitle('Ручний запис')->setSlug('manual-'.$suffix)->setBody('<p>Абзац</p><script>alert("manual")</script><p onclick="x()">ще</p>');
        $em->persist($studio);
        $em->persist($manual);
        $em->flush();
        $studioId = $studio->getId();
        $manualId = $manual->getId();

        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:html:sanitize'));
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
        $em->clear();
        self::assertStringContainsString('<script>', (string) $em->find(Post::class, $manualId)?->translate('uk')->getBody(), 'dry-run нічого не пише');

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $em->clear();
        $manualClean = (string) $em->find(Post::class, $manualId)?->translate('uk')->getBody();
        self::assertStringContainsString('<p>Абзац</p>', $manualClean);
        self::assertStringContainsString('<p>ще</p>', $manualClean);
        self::assertStringNotContainsString('<script', $manualClean);
        self::assertStringNotContainsString('onclick', $manualClean);
        self::assertSame($studioBody, $em->find(Post::class, $studioId)?->translate('uk')->getBody(), 'стаття студії — як була, її чистить StudioHtml');
    }
}
