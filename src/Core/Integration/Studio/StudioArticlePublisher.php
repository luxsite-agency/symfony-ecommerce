<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Studio;

use App\Core\Catalog\Import\Media\MediaImporter;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostCategory;
use App\Core\Content\Entity\PostCategoryTranslation;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Shared\Text\MachineCode;
use App\Site\SiteUrlGenerator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/**
 * Upsert статті від зовнішньої контент-панелі у блог (за зразком приймачів інших сайтів LuxSite).
 *
 * Одна стаття панелі однією мовою = один PostTranslation; переклади з тим самим group_id — переклади того самого Post
 * (Post::studioGroupId). Слаг унікальний у межах мови: зайнятий іншим записом → 409. FAQ і «Читайте також» — окремі
 * поля перекладу (шаблон виводить блоки й FAQPage), «Джерела» — секцією в кінці тіла. Обкладинка — через MediaImporter
 * (http(s), без приватних мереж, ліміти, MIME) у медіатеку; обкладинку, поставлену вручну, панель не замінює, а збій
 * завантаження не зриває публікацію (повертається попередженням). Рубрика — за category_slug (новина без рубрики →
 * «news»), якої нема — створюється.
 */
final class StudioArticlePublisher
{
    private const int MAX_FAQ = 30;
    private const int MAX_RELATED = 12;

    /** @param list<string> $locales */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StudioHtml $html,
        private readonly MediaImporter $media,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%site_host%')] private readonly string $siteHost,
    ) {
    }

    /**
     * @param string $requestHost хост, на який прийшов запит: посилання на нього теж внутрішні
     *
     * @throws StudioPayloadException
     */
    public function publish(StudioArticlePayload $payload, string $requestHost): StudioPublishResult
    {
        $lang = strtolower(trim($payload->lang));
        if (!\in_array($lang, $this->locales, true)) {
            throw new StudioPayloadException('Invalid data.', 422, ['lang' => 'Supported languages: '.implode(', ', $this->locales)]);
        }
        $ownHosts = array_values(array_unique([$this->siteHost, $requestHost]));
        $body = $this->html->body($payload->bodyHtml, $ownHosts);
        if ('' === trim(strip_tags($body))) {
            throw new StudioPayloadException('Invalid data.', 422, ['body_html' => 'Empty body after sanitizing.']);
        }
        $groupId = strtolower($payload->groupId);

        // дві мови однієї статті панель може слати паралельно: без замка обидві створили б Post
        $lock = $this->locks->createLock('studio-post-'.$groupId, 30);
        $lock->acquire(true);
        try {
            return $this->upsert($payload, $lang, $groupId, $body, $ownHosts);
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $ownHosts */
    private function upsert(StudioArticlePayload $payload, string $lang, string $groupId, string $body, array $ownHosts): StudioPublishResult
    {
        $post = $this->em->getRepository(Post::class)->findOneBy(['studioGroupId' => $groupId]);
        $clash = $this->em->getRepository(PostTranslation::class)->findOneBy(['locale' => $lang, 'slug' => $payload->slug]);
        if (null !== $clash && $clash->getPost() !== $post) {
            throw new StudioPayloadException(\sprintf('Slug "%s" is already used by post #%d.', $payload->slug, $clash->getPost()->getId()), 409, ['slug' => 'Already used.']);
        }

        $created = null === $post;
        if (null === $post) {
            $post = new Post();
            $post->setStudioGroupId($groupId);
        }

        $caption = StudioHtml::text($payload->coverCaption) ?: StudioHtml::text($payload->coverCredit);
        $translation = $post->translate($lang);
        $translation->setTitle($payload->title)
            ->setSlug($payload->slug)
            ->setExcerpt(StudioHtml::text($payload->lead))
            ->setBody($body.$this->html->sources($payload->sources, $payload->sourcesFormat, $lang))
            ->setMetaTitle(mb_substr(StudioHtml::text($payload->metaTitle), 0, 255))
            ->setMetaDescription(mb_substr(StudioHtml::text($payload->metaDesc), 0, 500))
            ->setFaq(self::faq($payload->faq))
            ->setRelated(self::related($payload->related, $ownHosts))
            ->setCoverCaption(mb_substr($caption, 0, 500));

        if (null !== $payload->author && '' !== trim($payload->author)) {
            $post->setAuthorName($payload->author);
        }
        $category = $this->category($payload->categorySlug ?: ('news' === $payload->kind ? 'news' : null), $lang);
        if (null !== $category) {
            $post->setCategory($category);
        }

        $warnings = [];
        $coverUrl = trim((string) $payload->coverUrl);
        if ('' !== $coverUrl && !$post->hasManualCover()) {
            try {
                $post->setStudioCover($this->media->importUrl($coverUrl, StudioHtml::text($payload->coverAlt) ?: $payload->title));
            } catch (Throwable $e) {
                if (!$this->em->isOpen()) {
                    throw $e;
                }
                $warnings[] = 'cover: '.$e->getMessage();
                $this->logger->warning('зовнішня контент-панель: обкладинку не завантажено', ['group' => $groupId, 'url' => $coverUrl, 'error' => $e->getMessage()]);
            }
        }

        // панель шле ATOM зі своїм зсувом: зводимо до таймзони сайту (інакше свіжий запис «із майбутнього» ховається)
        $publishedAt = $payload->publishedAt?->setTimezone(new DateTimeZone(date_default_timezone_get()));
        if (null === $post->getPublishedAt() || ($created && null !== $publishedAt)) {
            $post->setPublishedAt($publishedAt ?? new DateTimeImmutable());
        }
        $post->markStudioSynced();

        $this->em->persist($post);
        $this->em->flush();

        $slug = (string) $translation->getSlug();

        return new StudioPublishResult(
            (int) $post->getId(),
            $this->siteUrls->absolute('site_post', ['slug' => $slug, '_locale' => $lang]),
            $created,
            $warnings,
        );
    }

    /**
     * Рубрика за слагом панелі: слаг перекладу рубрики будь-якою мовою або код (kebab → snake); нема — нова рубрика
     * з назвою зі слага (перейменувати можна в адмінці). Перекладу мовою статті бракує — додається.
     */
    private function category(?string $slug, string $lang): ?PostCategory
    {
        if (null === $slug || '' === $slug) {
            return null;
        }
        $slug = strtolower(str_replace('_', '-', $slug));
        $code = str_replace('-', '_', $slug);
        $code = preg_match('/^[a-z]/', $code) ? $code : 'c_'.$code;
        $category = $this->em->getRepository(PostCategoryTranslation::class)->findOneBy(['slug' => $slug])?->getCategory()
            ?? $this->em->getRepository(PostCategory::class)->findOneBy(['code' => $code]);
        if (null === $category) {
            $category = new PostCategory(MachineCode::assertValid(substr($code, 0, 64)));
            $this->em->persist($category);
        }
        if (null === $category->getTranslation($lang)) {
            $taken = $this->em->getRepository(PostCategoryTranslation::class)->findOneBy(['locale' => $lang, 'slug' => $slug]);
            $category->translate($lang)->setName(mb_convert_case(str_replace('-', ' ', $slug), \MB_CASE_TITLE))->setSlug(null === $taken ? $slug : null);
        }

        return $category;
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<array{q: string, a: string}>
     */
    private static function faq(array $rows): array
    {
        $faq = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $q = mb_substr(StudioHtml::text($row['q'] ?? $row['question'] ?? null), 0, 500);
            $a = mb_substr(StudioHtml::text($row['a'] ?? $row['answer'] ?? null), 0, 5000);
            if ('' !== $q && '' !== $a) {
                $faq[] = ['q' => $q, 'a' => $a];
            }
        }

        return \array_slice($faq, 0, self::MAX_FAQ);
    }

    /**
     * «Читайте також»: лише посилання на цей магазин, зберігаються шляхом (без хоста) — робочі на будь-якому домені.
     *
     * @param array<mixed> $rows
     * @param list<string> $ownHosts
     *
     * @return list<array{title: string, url: string}>
     */
    private static function related(array $rows, array $ownHosts): array
    {
        $related = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = StudioHtml::text($row['url'] ?? null);
            $title = mb_substr(StudioHtml::text($row['title'] ?? null), 0, 255);
            if ('' === $url || '' === $title || !str_contains($url, '/') || !StudioHtml::isInternal($url, $ownHosts)) {
                continue;
            }
            $parts = parse_url($url);
            if (false === $parts) {
                continue;
            }
            $path = '/'.ltrim($parts['path'] ?? '/', '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            if (!isset($related[$path])) {
                $related[$path] = ['title' => $title, 'url' => $path];
            }
        }

        return \array_slice(array_values($related), 0, self::MAX_RELATED);
    }
}
