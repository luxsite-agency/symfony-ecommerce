<?php

declare(strict_types=1);

namespace App\Tests\Functional\Demo;

use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductMedia;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Content\Entity\Post;
use App\Core\Demo\DemoCatalog;
use App\Core\Demo\DemoPhotos;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * app:demo:seed зі справжніми фото (DemoPhotos): без теки — плейсхолдери; з текою — слоти товару, зображення категорії
 * й обкладинка статті беруться з файлів; повторний запуск міняє лише плейсхолдери на фото, не дублює медіа й прибирає
 * старі файли; третій запуск нічого не змінює. Перебудова індексу фільтрів — DDL, тож без DAMA-відкату: tearDown робить --purge.
 */
#[SkipDatabaseRollback]
final class DemoPhotosSeedTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/demo-images';

    private EntityManagerInterface $em;
    private Connection $db;
    private string $emptyDir;
    private string $mediaDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        $this->mediaDir = static::getContainer()->getParameter('kernel.project_dir').'/public/media';
        $this->emptyDir = sys_get_temp_dir().'/demo-photos-empty-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->emptyDir);
        self::assertSame(0, $this->demoMediaCount(), 'БД тестів без демо-даних з попередніх прогонів');
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->command()->execute(['--purge' => true]);
        (new Filesystem())->remove($this->emptyDir);
        parent::tearDown();
    }

    public function testPhotosReplacePlaceholdersIdempotently(): void
    {
        // 1. тека без файлів → усе плейсхолдери (contentHash порожній)
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-orders' => true, '--images-dir' => $this->emptyDir]), $tester->getDisplay());
        $total = $this->demoMediaCount();
        self::assertGreaterThanOrEqual(49, $total);
        $door = $this->demoProduct('DEMO-DV-P01');
        $links = $this->links($door);
        self::assertCount(3, $links);
        foreach ($links as $link) {
            self::assertNull($link->getMedia()->getContentHash());
        }
        $placeholderPaths = array_map(static fn (ProductMedia $l): string => $l->getMedia()->getPath(), $links);
        self::assertFileExists($this->mediaDir.'/'.$placeholderPaths[0]);
        self::assertNull($this->categoryImageHash('dveri'));
        $postCover = $this->postCoverHash('post1');
        self::assertSame('', $postCover, 'обкладинка-плейсхолдер є, але без хешу');

        // 2. тека з фікстурами → слоти 1 і 2 товару, категорія й обкладинка стають фото; слот 3 лишається плейсхолдером
        $this->em->clear();
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-orders' => true, '--images-dir' => self::FIXTURES]), $tester->getDisplay());
        self::assertStringContainsString('media_replaced', $tester->getDisplay());
        self::assertSame($total + 1, $this->demoMediaCount(), 'плюс лише зображення категорії; замінені слоти не дублюються');

        $door = $this->demoProduct('DEMO-DV-P01');
        $links = $this->links($door);
        self::assertCount(3, $links);
        self::assertSame(DemoPhotos::hash(self::FIXTURES.'/DV-P01-1.jpg'), $links[0]->getMedia()->getContentHash());
        self::assertSame('image/jpeg', $links[0]->getMedia()->getMimeType());
        self::assertNull($links[0]->getVariant(), 'слот 1 — спільне фото картки');
        self::assertSame(DemoPhotos::hash(self::FIXTURES.'/DV-P01-2.jpg'), $links[1]->getMedia()->getContentHash());
        self::assertNotNull($links[1]->getVariant(), 'слот 2 — фото першого кольорового варіанта');
        self::assertNull($links[2]->getMedia()->getContentHash(), 'слот 3 без файлу — плейсхолдер');
        self::assertSame([0, 1, 2], array_map(static fn (ProductMedia $l): int => $l->getPosition(), $links));
        self::assertStringStartsWith('demo-dv-p01-1', $links[0]->getMedia()->getPath());
        self::assertFileExists($this->mediaDir.'/'.$links[0]->getMedia()->getPath());
        self::assertFileDoesNotExist($this->mediaDir.'/'.$placeholderPaths[0], 'файл плейсхолдера прибрано');
        self::assertFileExists($this->mediaDir.'/'.$placeholderPaths[2], 'незамінений плейсхолдер лишився');

        self::assertSame(DemoPhotos::hash(self::FIXTURES.'/category-dveri.jpg'), $this->categoryImageHash('dveri'));
        self::assertSame(DemoPhotos::hash(self::FIXTURES.'/post-post1.jpg'), $this->postCoverHash('post1'));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_media WHERE path LIKE 'demo-%' AND id NOT IN (SELECT media_id FROM catalog_product_media) AND id NOT IN (SELECT cover_id FROM content_post WHERE cover_id IS NOT NULL) AND id NOT IN (SELECT image_id FROM catalog_category WHERE image_id IS NOT NULL)"), 'сиріт у медіатеці нема');

        // 3. той самий запуск ще раз → нічого не змінюється
        $ids = array_map(static fn (ProductMedia $l): ?int => $l->getMedia()->getId(), $links);
        $this->em->clear();
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-orders' => true, '--images-dir' => self::FIXTURES]), $tester->getDisplay());
        self::assertStringNotContainsString('media_replaced', $tester->getDisplay());
        self::assertSame($total + 1, $this->demoMediaCount());
        self::assertSame($ids, array_map(static fn (ProductMedia $l): ?int => $l->getMedia()->getId(), $this->links($this->demoProduct('DEMO-DV-P01'))));

        // 4. --purge прибирає й зображення категорії
        $this->em->clear();
        self::assertSame(0, $this->command()->execute(['--purge' => true]));
        self::assertSame(0, $this->demoMediaCount());
        self::assertFileDoesNotExist($this->mediaDir.'/'.$links[0]->getMedia()->getPath());
    }

    public function testSeedWithoutDirectoryKeepsPlaceholders(): void
    {
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-orders' => true, '--images-dir' => '/nonexistent/demo-images']), $tester->getDisplay());
        $links = $this->links($this->demoProduct('DEMO-DV-P01'));
        self::assertCount(3, $links);
        self::assertNull($links[0]->getMedia()->getContentHash());
        self::assertSame('image/webp', $links[0]->getMedia()->getMimeType());
    }

    /** M1: слот товару й обкладинку, які власник замінив своїм медіа (не demo-…), seed з паком не знімає і не видаляє. */
    public function testOwnerMediaIsNotReplaced(): void
    {
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-orders' => true, '--images-dir' => $this->emptyDir]), $tester->getDisplay());

        $door = $this->demoProduct('DEMO-DV-P01');
        $placeholder = $this->links($door)[0]->getMedia();
        $ownPhoto = $this->ownerMedia();
        $door->removeMedia($placeholder);
        $this->em->flush();
        $door->addMedia($ownPhoto)->setPosition(0);
        $post = $this->em->getRepository(Post::class)->find($this->demoPostId());
        self::assertInstanceOf(Post::class, $post);
        $ownCover = $this->ownerMedia();
        $post->setCover($ownCover);
        $this->em->flush();
        $ownIds = [$ownPhoto->getId(), $ownCover->getId()];
        $ownPaths = [$ownPhoto->getPath(), $ownCover->getPath()];

        try {
            $this->em->clear();
            $tester = $this->command();
            self::assertSame(0, $tester->execute(['--no-orders' => true, '--images-dir' => self::FIXTURES]), $tester->getDisplay());

            $links = $this->links($this->demoProduct('DEMO-DV-P01'));
            self::assertSame($ownIds[0], $links[0]->getMedia()->getId(), 'слот 1 власника лишився');
            self::assertSame(DemoPhotos::hash(self::FIXTURES.'/DV-P01-2.jpg'), $links[1]->getMedia()->getContentHash(), 'демо-слот 2 замінено');
            self::assertSame($ownIds[1], (int) $this->db->fetchOne('SELECT cover_id FROM content_post WHERE id = ?', [$this->demoPostId()]), 'обкладинка власника лишилася');
            foreach ($ownPaths as $path) {
                self::assertFileExists($this->mediaDir.'/'.$path);
            }
        } finally {
            $this->em->clear();
            $this->command()->execute(['--purge' => true]);
            $this->db->executeStatement('DELETE FROM catalog_media WHERE id IN (?, ?)', $ownIds);
            (new Filesystem())->remove(array_map(fn (string $p): string => $this->mediaDir.'/'.$p, $ownPaths));
        }
    }

    /** L1: битий файл паку (не зображення) — попередження, плейсхолдер замість фото, seed доходить до кінця. */
    public function testBrokenPhotoFallsBackToPlaceholder(): void
    {
        file_put_contents($this->emptyDir.'/DV-P01-1.jpg', 'not an image');
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-orders' => true, '--images-dir' => $this->emptyDir]), $tester->getDisplay());
        self::assertStringContainsString('фото пропущено', $tester->getDisplay());
        $links = $this->links($this->demoProduct('DEMO-DV-P01'));
        self::assertCount(3, $links);
        self::assertNull($links[0]->getMedia()->getContentHash());
        self::assertGreaterThan(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_post_translation WHERE slug LIKE 'demo-%'"), 'seed дійшов до блогу');
    }

    /** L4: --no-images разом із текою фото не створює жодного медіа. */
    public function testNoImagesIgnoresPhotoDirectory(): void
    {
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-orders' => true, '--no-images' => true, '--images-dir' => self::FIXTURES]), $tester->getDisplay());
        self::assertSame(0, $this->demoMediaCount());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_media WHERE content_hash IS NOT NULL'));
    }

    /** Медіа «від власника»: файл не з префіксом demo-. */
    private function ownerMedia(): Media
    {
        $path = 'owner-'.bin2hex(random_bytes(4)).'.jpg';
        (new Filesystem())->copy(self::FIXTURES.'/post-post1.jpg', $this->mediaDir.'/'.$path);
        $media = new Media($path, 'image/jpeg', (int) filesize($this->mediaDir.'/'.$path));
        $this->em->persist($media);

        return $media;
    }

    private function demoPostId(): int
    {
        return (int) $this->db->fetchOne("SELECT p.id FROM content_post p JOIN content_post_translation t ON t.post_id = p.id AND t.locale = 'uk' WHERE t.slug LIKE 'demo-%' ORDER BY p.id LIMIT 1");
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(static::$kernel ?? self::bootKernel()))->find('app:demo:seed'));
    }

    /** @return list<ProductMedia> за позицією */
    private function links(Product $product): array
    {
        $links = $product->getMedia()->toArray();
        usort($links, static fn (ProductMedia $a, ProductMedia $b): int => $a->getPosition() <=> $b->getPosition());

        return $links;
    }

    private function demoProduct(string $sku): Product
    {
        $variant = $this->em->createQueryBuilder()->select('v')->from(ProductVariant::class, 'v')->where('v.sku LIKE :p')
            ->setParameter('p', $sku.'%')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(ProductVariant::class, $variant, $sku);

        return $variant->getProduct();
    }

    private function demoMediaCount(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_media WHERE path LIKE 'demo-%'");
    }

    private function categoryImageHash(string $key): ?string
    {
        $slug = DemoCatalog::SLUG_PREFIX.$key;
        $translation = $this->em->getRepository(CategoryTranslation::class)->findOneBy(['locale' => 'uk', 'slug' => $slug]);
        self::assertNotNull($translation, $slug);

        return $translation->getCategory()->getImage()?->getContentHash();
    }

    /** '' — обкладинка є без хешу (плейсхолдер) */
    private function postCoverHash(string $key): string
    {
        $row = $this->db->fetchAssociative("SELECT m.content_hash FROM content_post p JOIN content_post_translation t ON t.post_id = p.id AND t.locale = 'uk' LEFT JOIN catalog_media m ON m.id = p.cover_id WHERE t.slug LIKE 'demo-%' ORDER BY p.id LIMIT 1");
        self::assertNotFalse($row, 'демо-стаття є');

        return $row['content_hash'] ?? '';
    }
}
