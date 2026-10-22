<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Url;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Url\CatalogSlugs;
use App\Core\Catalog\Url\ReservedPathSegments;
use App\Tests\Integration\Core\Catalog\FilterIndex\CatalogIndexFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Адреси каталогу без префіксів: шляхи категорій за деревом (і їх перерахунок), спільний простір «товар ↔ коренева
 * категорія», зарезервовані сегменти, генерація з -2, валідатор адмінки.
 */
final class CatalogUrlsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CatalogIndexFixture $fx;
    private string $s;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->s = bin2hex(random_bytes(3));
        $this->fx = new CatalogIndexFixture($this->em, $this->s);
    }

    public function testNestedPathsAreBuiltAndFollowSlugAndParentChanges(): void
    {
        $s = $this->s;
        self::assertSame("shoes-$s/sneakers-$s/running-$s", $this->path($this->fx->running));

        // зміна слага кореня — перераховується все піддерево (нащадки — RebuildCategoryPaths, у тестах sync)
        $this->fx->shoes->translate('uk')->setSlug("vzuttia-$s");
        $this->em->flush();
        self::assertSame("vzuttia-$s", $this->path($this->fx->shoes));
        self::assertSame("vzuttia-$s/sneakers-$s/running-$s", $this->path($this->fx->running), 'онука — теж, і сутність у пам\'яті вже знає новий шлях');

        // перенесення гілки під інший корінь
        $this->fx->sneakers->setParent($this->fx->bags);
        $this->em->flush();
        $this->em->clear();
        $running = $this->em->find(Category::class, $this->fx->running->getId());
        self::assertNotNull($running);
        self::assertSame("bags-$s/sneakers-$s/running-$s", $this->path($running));

        // нема слага цією мовою — нема й адреси (у дітей теж)
        self::assertNull($running->getTranslation('ru')?->getPath());
    }

    public function testSameChildSlugUnderDifferentParentsIsAllowedButNotAmongSiblings(): void
    {
        $s = $this->s;
        $slugs = self::getContainer()->get(CatalogSlugs::class);
        self::assertNull($slugs->categoryConflict("sneakers-$s", 'uk', "bags-$s"), 'інший батько — вільно');
        self::assertNotNull($slugs->categoryConflict("sneakers-$s", 'uk', "shoes-$s"), 'сестра з таким слагом уже є');
        self::assertSame("sneakers-$s-2", $slugs->uniqueCategorySlug("sneakers-$s", 'uk', "shoes-$s"));

        $second = new Category($this->fx->bags);
        $second->translate('uk')->setName('Кросівки')->setSlug("sneakers-$s");
        $this->em->persist($second);
        $this->em->flush();
        self::assertSame("bags-$s/sneakers-$s", $this->path($second));
    }

    public function testProductsAndRootCategoriesShareTheFirstSegment(): void
    {
        $s = $this->s;
        $slugs = self::getContainer()->get(CatalogSlugs::class);
        $p1 = strtolower('p1').'-'.$s; // слаг P1 з фікстури
        self::assertStringContainsString('коренева категорія', (string) $slugs->productConflict("shoes-$s", 'uk'));
        self::assertStringContainsString('товар', (string) $slugs->categoryConflict($p1, 'uk', null));
        self::assertNull($slugs->categoryConflict($p1, 'uk', "shoes-$s"), 'підкатегорія з таким слагом — інша адреса (/shoes/p1/)');
        self::assertNull($slugs->productConflict($p1, 'uk', $this->fx->id('P1')), 'свій слаг — не конфлікт');
        self::assertSame("shoes-$s-2", $slugs->uniqueProductSlug("shoes-$s", 'uk'));
    }

    public function testReservedSegmentsAndValidator(): void
    {
        $reserved = self::getContainer()->get(ReservedPathSegments::class);
        foreach (['cart', 'checkout', 'order', 'blog', 'page', 'quick-order', 'price-request', 'np', 'sitemap.xml', 'media', 'themes', 'assets', 'bundles', 'admin', 'login', 'uk', 'ru', 'en', 'payment', 'shipping', 'liqpay', 'ukrposhta', 'robots.txt'] as $segment) {
            self::assertTrue($reserved->isReserved($segment), $segment);
        }
        self::assertFalse($reserved->isReserved('odyah'));

        $slugs = self::getContainer()->get(CatalogSlugs::class);
        self::assertSame('cart-2', $slugs->uniqueProductSlug('cart', 'uk'));
        self::assertSame('checkout-2', $slugs->uniqueCategorySlug('checkout', 'uk', null));
        self::assertSame('checkout', $slugs->uniqueCategorySlug('checkout', 'uk', 'odyah'), 'службові слова важать лише для першого сегмента');

        $validator = self::getContainer()->get(ValidatorInterface::class);
        $product = new Product('VAL-'.$this->s, 'UAH');
        $translation = $product->translate('uk')->setName('Кошик')->setSlug('cart');
        $violations = $validator->validate($translation);
        self::assertCount(1, $violations);
        self::assertSame('slug', $violations[0]->getPropertyPath());
        self::assertStringContainsString('службова адреса', (string) $violations[0]->getMessage());

        $translation->setSlug('shoes-'.$this->s);
        self::assertStringContainsString('коренева категорія', (string) $validator->validate($translation)[0]?->getMessage());

        $root = new Category();
        $rootTranslation = $root->translate('uk')->setName('P1')->setSlug('p1-'.$this->s);
        self::assertStringContainsString('товар', (string) $validator->validate($rootTranslation)[0]?->getMessage());
        $child = new Category($this->fx->shoes);
        $childTranslation = $child->translate('uk')->setName('Бігові')->setSlug('sneakers-'.$this->s);
        self::assertStringContainsString('підкатегорія', (string) $validator->validate($childTranslation)[0]?->getMessage());
        self::assertCount(0, $validator->validate($child->translate('uk')->setSlug('cart')), 'cart як підкатегорія — /shoes/cart/, дозволено');
    }

    public function testCommandRebuildsPathsAndReportsLegacyConflicts(): void
    {
        $db = $this->em->getConnection();
        // старі дані в обхід валідатора: шлях зіпсовано, товар зі службовим слагом і товар = коренева категорія
        $db->executeStatement('UPDATE catalog_category_translation SET path = NULL WHERE category_id = ?', [$this->fx->running->getId()]);
        $db->executeStatement('UPDATE catalog_product_translation SET slug = ? WHERE product_id = ?', ['blog', $this->fx->id('P2')]);
        $db->executeStatement('UPDATE catalog_product_translation SET slug = ? WHERE product_id = ?', ['bags-'.$this->s, $this->fx->id('P3')]);

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('catalog:urls:rebuild'));
        self::assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('службова адреса', $display);
        self::assertStringContainsString('кореневою категорією', $display);
        self::assertSame('shoes-'.$this->s.'/sneakers-'.$this->s.'/running-'.$this->s, (string) $db->fetchOne('SELECT path FROM catalog_category_translation WHERE category_id = ? AND locale = ?', [$this->fx->running->getId(), 'uk']));
    }

    private function path(Category $category): ?string
    {
        return $category->getTranslation('uk')?->getPath();
    }
}
