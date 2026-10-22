<?php

declare(strict_types=1);

namespace App\Tests\Site\Seo;

use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Site\SiteName;
use App\Site\Seo\ProductSeo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/** Мета товару за шаблонами магазину (seo-tech-notes п. 8, приклад Сокіл 77). */
final class ProductSeoTest extends TestCase
{
    private ProductSeo $seo;

    protected function setUp(): void
    {
        $this->seo = new ProductSeo([
            'title_max' => 60,
            'brand' => ['uk' => 'Сокіл 77', 'en' => 'Sokil Workshop'],
            'product' => [
                'title' => ['uk' => '{name}: ціна {price} грн', 'en' => '{name}'],
                'title_on_request' => ['uk' => '{name}: ціна за запитом'],
                'title_from' => ['uk' => '{name}: від {price} грн'],
                'description' => ['uk' => '{name} з символікою Сокіл 77 за {price} грн. Доставка Новою поштою, оплата при отриманні. Кошти від продажу спрямовуються на потреби команди.', 'en' => '{name} with Sokil Workshop insignia.'],
                'description_on_request' => ['uk' => '{name} з символікою Сокіл 77. Ціну назве менеджер.'],
                'description_from' => ['uk' => '{name}: від {price} грн залежно від опцій.'],
            ],
        ], new SiteName(['uk' => 'Мерч Сокіл 77', 'en' => 'Sokil Store'], 'uk', new RequestStack()));
    }

    public function testTemplatesWithPriceBrandSuffixLimitAndSentenceCut(): void
    {
        $product = new Product('P', 'UAH');
        $tr = $product->translate('uk')->setName('Шеврон Сокіл 77 мультикам');
        $meta = $this->seo->meta($product, $tr, Money::of(29_000, 'UAH'), 'uk');
        self::assertSame('Шеврон Сокіл 77 мультикам: ціна 290 грн', $meta['title'], 'назву магазину дописує base');
        self::assertSame('Шеврон Сокіл 77 мультикам з символікою Сокіл 77 за 290 грн. Доставка Новою поштою, оплата при отриманні.', $meta['description'], 'до 155 знаків цілими реченнями');
        self::assertLessThanOrEqual(ProductSeo::DESCRIPTION_MAX, mb_strlen($meta['description']));

        $long = $product->translate('uk')->setName('Великий випел з емблемою та назвою команди 140×90');
        self::assertSame('Великий випел з емблемою та назвою команди 140×90', $this->seo->meta($product, $long, Money::of(240_050, 'UAH'), 'uk')['title'], 'з « | Мерч Сокіл 77» довше 60 — лише назва');
        self::assertStringContainsString('2 400,5', $this->seo->meta($product, $long, Money::of(240_050, 'UAH'), 'uk')['description']);

        $en = $product->translate('en')->setName('sokil team patch');
        self::assertSame(['title' => 'sokil team patch', 'description' => 'sokil team patch with Sokil Workshop insignia.'], $this->seo->meta($product, $en, Money::of(29_000, 'UAH'), 'en'));
    }

    public function testWordCutKeepsValidUtf8(): void
    {
        $product = new Product('U', 'UAH');
        $tr = $product->translate('uk')->setName(trim(str_repeat('набір ', 40)));
        $description = $this->seo->meta($product, $tr, Money::of(29_000, 'UAH'), 'uk')['description'];
        self::assertTrue(mb_check_encoding($description, 'UTF-8'), 'обрізання по слову не рве UTF-8 («р» = D1 80)');
        self::assertStringEndsWith('набір…', $description);
        self::assertLessThanOrEqual(ProductSeo::DESCRIPTION_MAX, mb_strlen($description));
    }

    public function testPriceFromUsesOwnTemplatesNeverExactPrice(): void
    {
        $product = new Product('F', 'UAH');
        $product->setPriceMode(PriceMode::From);
        $tr = $product->translate('uk')->setName('Прапор');
        self::assertSame(['title' => 'Прапор: від 450 грн', 'description' => 'Прапор: від 450 грн залежно від опцій.'], $this->seo->meta($product, $tr, Money::of(45_000, 'UAH'), 'uk'));
        $en = $product->translate('en')->setName('Flag');
        self::assertSame(['title' => 'Flag', 'description' => ''], $this->seo->meta($product, $en, Money::of(45_000, 'UAH'), 'en'), 'шаблону from мовою нема — не «ціна X» з шаблону fixed');
    }

    public function testOnRequestFieldsWinAndBrand(): void
    {
        $product = new Product('Q', 'UAH');
        $product->setPriceMode(PriceMode::OnRequest);
        $tr = $product->translate('uk')->setName('Годинник');
        self::assertSame(['title' => 'Годинник: ціна за запитом', 'description' => 'Годинник з символікою Сокіл 77. Ціну назве менеджер.'], $this->seo->meta($product, $tr, null, 'uk'));
        self::assertSame('Годинник', $this->seo->meta($product, $product->translate('en')->setName('Годинник'), null, 'en')['title'], 'шаблону мови нема — назва');

        $tr->setMetaTitle('Годинник з гербом')->setMetaDescription('Ручна робота.');
        self::assertSame(['title' => 'Годинник з гербом', 'description' => 'Ручна робота.'], $this->seo->meta($product, $tr, null, 'uk'), 'заповнені поля перекладу — без шаблону');

        self::assertSame('Сокіл 77', $this->seo->brand($product, 'uk'));
        $product->setBrand(new Brand('M-Tac', 'm-tac'));
        self::assertSame('M-Tac', $this->seo->brand($product, 'uk'), 'бренд товару важливіший за параметр');
    }
}
