<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\OptionValueKind;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use Symfony\Component\DomCrawler\Crawler;

/** Товар через CRUD: створення з варіантом, осями, характеристиками, опціями; помилки інваріантів як помилки полів. */
final class ProductCrudTest extends AdminWebTestCase
{
    private Category $category;
    private Brand $brand;
    private Attribute $color;
    private Attribute $height;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = new Category();
        $this->category->translate('uk')->setName('Двері')->setSlug('dveri');
        $this->brand = new Brand('Solo', 'solo');
        $this->color = new Attribute('color', AttributeType::Select);
        $this->color->setVariantAxis(true);
        $this->color->translate('uk')->setName('Колір');
        $this->color->addValue('white')->translate('uk')->setValue('Білий');
        $this->color->addValue('black')->translate('uk')->setValue('Чорний');
        $this->height = new Attribute('height_mm', AttributeType::Number);
        $this->height->setUnit('мм');
        foreach ([$this->category, $this->brand, $this->color, $this->height] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->loginAs(UserRole::Manager);
    }

    public function testProductListAndFormsOpen(): void
    {
        $this->client->request('GET', $this->adminUrl('/product'));
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', $this->adminUrl('/product/new'));
        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $crawler->filter('input[name="Product[variants][0][sku]"]')->attr('value'), 'SKU-заглушка першого варіанта не показується');
        self::assertCount(1, $crawler->filter('select[name="Product[variants][0][axes][axis_color]"]'));
        self::assertCount(1, $crawler->filter('input[name="Product[attributesForm][attr_height_mm]"]'));
    }

    public function testCreateProductWithVariantAxisAttributesAndOptions(): void
    {
        $white = $this->color->findValue('white');
        self::assertNotNull($white);

        $crawler = $this->client->request('GET', $this->adminUrl('/product/new'));
        $crawler = $this->submit($crawler, [
            'Product[kind]' => 'goods',
            'Product[priceMode]' => 'fixed',
            'Product[brand]' => (string) $this->brand->getId(),
            'Product[categories]' => [(string) $this->category->getId()],
            'Product[translationsForm][uk][name]' => 'Двері ML 14',
            'Product[translationsForm][ru][name]' => 'Двери ML 14',
            'Product[variants][0][sku]' => 'ML-14-WHITE',
            'Product[variants][0][price]' => '4262,00',
            'Product[variants][0][oldPrice]' => '5000',
            'Product[variants][0][stock]' => '3',
            'Product[variants][0][minQuantity]' => '1',
            'Product[variants][0][quantityStep]' => '1',
            'Product[variants][0][axes][axis_color]' => (string) $white->getId(),
            'Product[attributesForm][attr_height_mm]' => '2000',
        ], [
            'Product[variants][0][priceTiers][0][minQuantity]' => '5',
            'Product[variants][0][priceTiers][0][price]' => '4000',
            'Product[optionGroups][0][code]' => 'size',
            'Product[optionGroups][0][selection]' => 'single',
            'Product[optionGroups][0][translationsForm][uk][name]' => 'Полотно',
            'Product[optionGroups][0][values][0][code]' => 's2000x910',
            'Product[optionGroups][0][values][0][kind]' => 'modifier',
            'Product[optionGroups][0][values][0][modifierType]' => 'absolute',
            'Product[optionGroups][0][values][0][amount]' => '515',
            'Product[optionGroups][0][values][0][translationsForm][uk][label]' => '2000×910',
            'Product[optionGroups][0][values][1][code]' => 'custom',
            'Product[optionGroups][0][values][1][kind]' => 'modifier',
            'Product[optionGroups][0][values][1][modifierType]' => 'percent',
            'Product[optionGroups][0][values][1][percent]' => '15',
            'Product[optionGroups][0][values][1][translationsForm][uk][label]' => 'Нестандарт',
        ]);
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $product = $this->findBySku('ML-14-WHITE');
        self::assertSame('Двері ML 14', $product->translate('uk')->getName());
        self::assertSame('dveri-ml-14', $product->translate('uk')->getSlug());
        self::assertSame(['ru', 'uk'], $product->getTranslatedLocales());
        self::assertSame('solo', $product->getBrand()?->getSlug());
        self::assertSame('dveri', $product->getMainCategory()?->translate('uk')->getSlug());

        $variant = $product->getDefaultVariant();
        self::assertTrue($variant->getPrice()->equals(Money::of(426_200, 'UAH')));
        self::assertSame(500_000, $variant->getOldPrice()?->amount);
        self::assertSame('3.000', $variant->getStock());
        $color = $this->em()->find(Attribute::class, $this->color->getId());
        $height = $this->em()->find(Attribute::class, $this->height->getId());
        self::assertNotNull($color);
        self::assertNotNull($height);
        self::assertSame('white', $variant->getAxisValue($color)?->getCode());
        $tier = $variant->getPriceTiers()->first();
        self::assertNotFalse($tier);
        self::assertSame('5.000', $tier->getMinQuantity());
        self::assertSame(400_000, $tier->getPrice()->amount);

        self::assertSame('2000.0000', $product->getAttributeValues($height)[0]->getNumberValue());

        $size = $product->findOptionGroup('size');
        self::assertNotNull($size);
        self::assertSame('Полотно', $size->translate('uk')->getName());
        $wide = $size->findValue('s2000x910');
        $custom = $size->findValue('custom');
        self::assertNotNull($wide);
        self::assertNotNull($custom);
        self::assertSame(OptionValueKind::Modifier, $wide->getKind());
        self::assertSame(51_500, $wide->getAmount()?->amount);
        self::assertSame(1500, $custom->getBasisPoints());
    }

    /** Опис із адмінки виводиться |raw: при збереженні лишається лише білий список (консиліум безпеки 10.10 п. 2). */
    public function testDescriptionHtmlIsSanitizedOnSave(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/product/new'));
        $crawler = $this->submit($crawler, [
            'Product[kind]' => 'goods',
            'Product[priceMode]' => 'fixed',
            'Product[categories]' => [(string) $this->category->getId()],
            'Product[translationsForm][uk][name]' => 'Двері XSS',
            'Product[translationsForm][uk][shortDescription]' => 'Коротко <img src="x" onerror="alert(1)"> і <b>жирно</b>',
            'Product[translationsForm][uk][description]' => '<p>Опис <strong>МДФ</strong></p><script>alert("xss")</script><a href="javascript:alert(2)" onclick="x()">зле</a><p style="color:red">без style</p>',
            'Product[variants][0][sku]' => 'XSS-ADMIN',
            'Product[variants][0][price]' => '100',
            'Product[variants][0][stock]' => '1',
            'Product[variants][0][minQuantity]' => '1',
            'Product[variants][0][quantityStep]' => '1',
        ]);
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $translation = $this->findBySku('XSS-ADMIN')->translate('uk');
        $description = (string) $translation->getDescription();
        self::assertStringContainsString('<p>Опис <strong>МДФ</strong></p>', $description);
        self::assertStringContainsString('<p>без style</p>', $description);
        self::assertStringNotContainsString('<script', $description);
        self::assertStringNotContainsString('alert(', $description);
        self::assertStringNotContainsString('javascript:', $description);
        self::assertStringNotContainsString('onclick', $description);
        $short = (string) $translation->getShortDescription();
        self::assertStringContainsString('<b>жирно</b>', $short);
        self::assertStringNotContainsString('onerror', $short);
    }

    public function testDuplicateAxisCombinationIsAFieldError(): void
    {
        $black = $this->color->findValue('black');
        self::assertNotNull($black);

        $crawler = $this->client->request('GET', $this->adminUrl('/product/new'));
        $crawler = $this->submit($crawler, [
            'Product[translationsForm][uk][name]' => 'Двері',
            'Product[variants][0][sku]' => 'A-1',
            'Product[variants][0][price]' => '100',
            'Product[variants][0][minQuantity]' => '1',
            'Product[variants][0][quantityStep]' => '1',
            'Product[variants][0][axes][axis_color]' => (string) $black->getId(),
        ], [
            'Product[variants][1][sku]' => 'A-2',
            'Product[variants][1][price]' => '100',
            'Product[variants][1][stock]' => '0',
            'Product[variants][1][stockPolicy]' => 'in_stock',
            'Product[variants][1][unit]' => 'pc',
            'Product[variants][1][minQuantity]' => '1',
            'Product[variants][1][quantityStep]' => '1',
            'Product[variants][1][position]' => '1',
            'Product[variants][1][axes][axis_color]' => (string) $black->getId(),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('набором осей уже є', self::formErrors($crawler));
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testMissingPriceAndNameAreFieldErrors(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/product/new'));
        $crawler = $this->submit($crawler, [
            'Product[translationsForm][uk][name]' => '',
            'Product[variants][0][sku]' => 'A-1',
            'Product[variants][0][price]' => '',
            'Product[variants][0][minQuantity]' => '1',
            'Product[variants][0][quantityStep]' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        $errors = self::formErrors($crawler);
        self::assertStringContainsString('Вкажіть назву товару', $errors);
        self::assertStringContainsString('Вкажіть ціну', $errors);
    }

    public function testEditExistingProductKeepsDataAndAddsVariant(): void
    {
        $black = $this->color->findValue('black');
        self::assertNotNull($black);
        $product = new Product('ML-14-WHITE', 'UAH');
        $product->translate('uk')->setName('Двері ML 14');
        $product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $product->getDefaultVariant()->addPriceTier('5', Money::of(400_000, 'UAH'));
        $casing = new Product('LYSHTVA', 'UAH');
        $group = $product->addOptionGroup('casing');
        $group->translate('uk')->setName('Лиштва');
        $group->addComponent('both_sides', $casing->getDefaultVariant(), '5')->translate('uk')->setLabel('З двох боків');
        $group->addAbsoluteModifier('none', Money::zero('UAH'))->translate('uk')->setLabel('Без лиштви');
        $this->em()->persist($casing);
        $this->em()->persist($product);
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/product/%d/edit', $product->getId())));
        self::assertResponseIsSuccessful();
        self::assertSame('4262.00', $crawler->filter('input[name="Product[variants][0][price]"]')->attr('value'));
        self::assertSame('component', $crawler->filter('select[name="Product[optionGroups][0][values][0][kind]"] option[selected]')->attr('value'));
        self::assertNotNull($crawler->filter('select[name="Product[optionGroups][0][values][0][kind]"]')->attr('disabled'), 'Вид значення опції незмінний');
        self::assertSame('5.000', $crawler->filter('input[name="Product[variants][0][priceTiers][0][minQuantity]"]')->attr('value'));

        $form = $crawler->filter('form[name="Product"]')->form();
        $form->setValues(['Product[variants][0][stock]' => '7']);
        $payload = $form->getPhpValues();
        parse_str('Product[variants][1][sku]=ML-14-BLACK&Product[variants][1][price]=4562&Product[variants][1][stock]=0&Product[variants][1][stockPolicy]=made_to_order&Product[variants][1][unit]=pc&Product[variants][1][minQuantity]=1&Product[variants][1][quantityStep]=1&Product[variants][1][position]=1&Product[variants][1][axes][axis_color]='.$black->getId(), $extra);
        $payload = array_replace_recursive($payload, $extra);
        $payload['ea']['editForm']['btn'] = 'saveAndReturn';
        $crawler = $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $reloaded = $this->findBySku('ML-14-WHITE');
        self::assertCount(2, $reloaded->getVariants());
        self::assertSame('7.000', $reloaded->getDefaultVariant()->getStock());
        self::assertSame(426_200, $reloaded->getDefaultVariant()->getPrice()->amount, 'Ціна не змінилась');
        self::assertCount(1, $reloaded->getDefaultVariant()->getPriceTiers(), 'Існуючий поріг збережено');
        $blackVariant = $reloaded->findVariantBySku('ML-14-BLACK');
        self::assertNotNull($blackVariant);
        self::assertSame(456_200, $blackVariant->getPrice()->amount);
        $axis = $blackVariant->getAxisValues()->first();
        self::assertNotFalse($axis);
        self::assertSame('black', $axis->getValue()->getCode());
        self::assertSame(OptionValueKind::Component, $reloaded->findOptionGroup('casing')?->findValue('both_sides')?->getKind());
    }

    /** SKU — за префіксом (розумний пошук, 20.10.2026); назви (FULLTEXT, потребує закомічених рядків) — AdminSmartSearchTest. */
    public function testSearchBySkuPrefix(): void
    {
        $a = new Product('SKU-AAA', 'UAH');
        $a->translate('uk')->setName('Двері білі');
        $b = new Product('SKU-BBB', 'UAH');
        $b->translate('uk')->setName('Вікно');
        $this->em()->persist($a);
        $this->em()->persist($b);
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->adminUrl('/product?query=sku-b'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SKU-BBB', $crawler->filter('table tbody')->text());
        self::assertStringNotContainsString('SKU-AAA', $crawler->filter('table tbody')->text());

        $crawler = $this->client->request('GET', $this->adminUrl('/product?query=SKU'));
        self::assertStringContainsString('SKU-AAA', $crawler->filter('table tbody')->text());
        self::assertStringContainsString('SKU-BBB', $crawler->filter('table tbody')->text());
    }

    private static function formErrors(Crawler $crawler): string
    {
        return implode(' | ', $crawler->filter('.invalid-feedback, .form-error-message, .global-invalid-feedback')->each(static fn (Crawler $n): string => trim($n->text())));
    }

    private function findBySku(string $sku): Product
    {
        $product = $this->em()->createQuery('SELECT p FROM '.Product::class.' p JOIN p.variants v WHERE v.sku = :sku')->setParameter('sku', $sku)->getOneOrNullResult();
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    /**
     * @param array<string, string|list<string>> $values
     * @param array<string, string>              $extra
     */
    private function submit(Crawler $crawler, array $values, array $extra = []): Crawler
    {
        $form = $crawler->filter('form[name="Product"]')->form();
        $form->setValues($values);
        $payload = $form->getPhpValues();
        foreach ($extra as $name => $value) {
            parse_str(\sprintf('%s=%s', $name, urlencode($value)), $parsed);
            $payload = array_replace_recursive($payload, $parsed);
        }
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';

        return $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
    }
}
