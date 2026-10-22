<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Security\Enum\UserRole;
use Symfony\Component\DomCrawler\Crawler;

/** CRUD сторінок і записів: створення через форму з WYSIWYG, слаг із заголовка, «Переглянути на сайті» лише для опублікованих. */
final class ContentCrudTest extends AdminWebTestCase
{
    public function testCreatePageThroughFormAndViewOnSiteAppearsWhenPublished(): void
    {
        $this->loginAs(UserRole::Manager);
        foreach (['/page', '/page/new', '/post', '/post/new', '/post-category', '/post-category/new'] as $path) {
            $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseIsSuccessful($path);
        }

        $crawler = $this->client->request('GET', $this->adminUrl('/page/new'));
        $crawler = $this->submit($crawler, 'Page', [
            'Page[position]' => '1',
            'Page[translationsForm][uk][title]' => 'Про компанію',
            'Page[translationsForm][uk][body]' => '<p>Ми робимо двері</p>',
            'Page[translationsForm][ru][title]' => 'О компании',
        ]);
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $page = $this->em()->getRepository(Page::class)->findOneBy(['position' => 1]);
        self::assertNotNull($page);
        self::assertFalse($page->isPublished());
        $slug = $page->translate('uk')->getSlug();
        self::assertNotNull($slug, 'Слаг сформував SlugListener');
        self::assertMatchesRegularExpression('/^pro-kompani/', $slug);
        self::assertNotNull($page->translate('ru')->getSlug());
        self::assertSame(['ru', 'uk'], $page->getTranslatedLocales(), 'Порожній en-переклад не збережено');
        self::assertSame('<p>Ми робимо двері</p>', $page->translate('uk')->getBody());

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/page/%d/edit', $page->getId())));
        self::assertCount(0, $crawler->filter('a:contains("Переглянути на сайті")'), 'Неопублікована — кнопки нема');

        $crawler = $this->submit($crawler, 'Page', ['Page[published]' => '1'], edit: true);
        self::assertFormRedirected($crawler);
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/page/%d/edit', $page->getId())));
        $link = $crawler->filter('a:contains("Переглянути на сайті")');
        self::assertCount(1, $link);
        self::assertStringEndsWith('/'.$slug.'/', $link->attr('href') ?? '');
        self::assertStringStartsWith('https://'.static::getContainer()->getParameter('site_host'), $link->attr('href') ?? '');
        self::assertSame('_blank', $link->attr('target'));
    }

    public function testCreatePostWithPublicationDate(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/post/new'));
        $crawler = $this->submit($crawler, 'Post', [
            'Post[publishedAt]' => '2026-09-01T10:00',
            'Post[authorName]' => 'Редакція',
            'Post[tags]' => 'двері, поради',
            'Post[translationsForm][uk][title]' => 'Як обрати двері',
            'Post[translationsForm][uk][excerpt]' => 'Коротко',
            'Post[translationsForm][uk][body]' => '<p>Текст</p>',
        ]);
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $post = $this->em()->getRepository(Post::class)->findOneBy(['authorName' => 'Редакція']);
        self::assertNotNull($post);
        self::assertTrue($post->isPublished());
        self::assertSame(['двері', 'поради'], $post->getTagList());
        $slug = $post->translate('uk')->getSlug();
        self::assertNotNull($slug);
        self::assertSame(['uk'], $post->getTranslatedLocales(), 'Порожні ru/en-переклади не збережено');

        $crawler = $this->client->request('GET', $this->adminUrl('/post'));
        self::assertStringEndsWith('/blog/'.$slug, $crawler->filter('a:contains("Переглянути на сайті")')->first()->attr('href') ?? '');
    }

    /** @param array<string, string> $values */
    private function submit(Crawler $crawler, string $formName, array $values, bool $edit = false): Crawler
    {
        $form = $crawler->filter(\sprintf('form[name="%s"]', $formName))->form();
        $form->setValues($values);
        $payload = $form->getPhpValues();
        $payload['ea'][$edit ? 'editForm' : 'newForm']['btn'] = 'saveAndReturn';

        return $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
    }
}
