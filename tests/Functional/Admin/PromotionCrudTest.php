<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Pricing;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Pricing\Promotion\Enum\PromotionType;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * CRUD акцій (Ф3): лише адмін, створення з формою (відсоток, категорія, бренд), список зі знижкою, дія на ціну;
 * зміна типу знижки в обидва боки (тип і значення — атомарно), помилки форми замість 500, «весь каталог» лише явно,
 * видалення цілі не вмикає акцію на весь каталог, кінець пізніше за початок.
 */
final class PromotionCrudTest extends AdminWebTestCase
{
    public function testManagerHasNoAccess(): void
    {
        $this->loginAs(UserRole::Manager);
        $this->client->request('GET', $this->adminUrl('/promotion'));
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertStringNotContainsString('Акції', $crawler->filter('#main-menu, .sidebar, .main-menu')->text());
    }

    public function testAdminCreatesPercentPromotionOnCategoryAndBrand(): void
    {
        $category = new Category();
        $category->translate('uk')->setName('Двері')->setSlug('dveri-promo');
        $child = new Category($category);
        $child->translate('uk')->setName('Вхідні')->setSlug('vhidni-promo');
        $brand = new Brand('Steelguard', 'steelguard');
        $inChild = new Product('PR-1', 'UAH');
        $inChild->addCategory($child);
        $inChild->getDefaultVariant()->setPrice(Money::of(200_000, 'UAH'));
        $byBrand = new Product('PR-2', 'UAH');
        $byBrand->setBrand($brand);
        $byBrand->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $outside = new Product('PR-3', 'UAH');
        $outside->getDefaultVariant()->setPrice(Money::of(50_000, 'UAH'));
        $wholesale = new CustomerGroup('wholesale_promo', 'Опт');
        $dealer = new CustomerGroup('dealer_promo', 'Дилер');
        $inChild->getDefaultVariant()->addPriceTier('1', Money::of(150_000, 'UAH'), $wholesale);
        $inChild->getDefaultVariant()->addPriceTier('1', Money::of(160_000, 'UAH'), $dealer);
        foreach ([$category, $child, $brand, $inChild, $byBrand, $outside, $wholesale, $dealer] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertStringContainsString('Акції', $crawler->filter('#main-menu, .sidebar, .main-menu')->text());
        $crawler = $this->client->request('GET', $this->adminUrl('/promotion/new'));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="Promotion"]')->form();
        $form->setValues(['Promotion[name]' => 'Двері та Steelguard −15 %', 'Promotion[type]' => 'percent', 'Promotion[discount]' => '15', 'Promotion[priority]' => '3']);
        foreach (['Promotion[categories]' => $category->getId(), 'Promotion[brands]' => $brand->getId(), 'Promotion[customerGroups]' => $wholesale->getId()] as $name => $id) {
            $field = $form[$name];
            self::assertInstanceOf(ChoiceFormField::class, $field);
            $field->select([(string) $id]);
        }
        $payload = $form->getPhpValues();
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';
        $crawler = $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $promotion = $this->em()->getRepository(Promotion::class)->findOneBy(['name' => 'Двері та Steelguard −15 %']);
        self::assertNotNull($promotion);
        self::assertSame(1500, $promotion->getValue());
        self::assertSame(3, $promotion->getPriority());
        self::assertCount(1, $promotion->getCategories());
        self::assertCount(1, $promotion->getBrands());
        self::assertCount(1, $promotion->getCustomerGroups());
        self::assertSame('wholesale_promo', $promotion->getCustomerGroups()->first()->getCode());

        $crawler = $this->client->request('GET', $this->adminUrl('/promotion'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('−15 %', $crawler->filter('table tbody')->text());

        $pricing = static::getContainer()->get(Pricing::class);
        $reload = fn (Product $p): Product => $this->em()->find(Product::class, $p->getId()) ?? self::fail();
        self::assertSame(170_000, $pricing->quote($reload($inChild)->getDefaultVariant(), '1', null)->price->amount, 'Підкатегорія обраної категорії');
        self::assertSame(85_000, $pricing->quote($reload($byBrand)->getDefaultVariant(), '1', null)->price->amount, 'Бренд');
        self::assertSame(50_000, $pricing->quote($reload($outside)->getDefaultVariant(), '1', null)->price->amount, 'Поза цілями — без знижки');
        $groups = $this->em()->getRepository(CustomerGroup::class);
        self::assertSame(127_500, $pricing->quote($reload($inChild)->getDefaultVariant(), '1', $groups->findOneBy(['code' => 'wholesale_promo']))->price->amount, 'Дозволена група: −15 % від оптової 1500');
        self::assertSame(160_000, $pricing->quote($reload($inChild)->getDefaultVariant(), '1', $groups->findOneBy(['code' => 'dealer_promo']))->price->amount, 'Група без дозволу: оптова ціна без акції');
    }

    public function testEditSwitchesDiscountTypeBothWays(): void
    {
        $promotion = new Promotion('Знижка', 'UAH');
        $promotion->setAmountDiscount('150.00');
        $promotion->setWholeCatalog(true);
        $this->em()->persist($promotion);
        $this->em()->flush();
        $id = (int) $promotion->getId();
        $this->loginAs(UserRole::Admin, 'admin@example.test');

        $crawler = $this->editPage($id);
        self::assertSame(['amount', '150.00'], $this->typeAndDiscount($crawler), 'Форма показує збережені тип і суму');
        $this->submit($crawler, ['Promotion[type]' => 'percent'], true, true);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback, .form-error-message', 'від 0,01 до 100');
        self::assertSame([PromotionType::Amount, 15000], $this->reloaded($id), 'Лише тип змінено: «150.00» як відсоток не підходить → помилка, а не 150 % і товар за 0');

        $crawler = $this->editPage($id);
        $this->submit($crawler, ['Promotion[type]' => 'percent', 'Promotion[discount]' => '10'], true);
        self::assertSame([PromotionType::Percent, 1000], $this->reloaded($id), 'Сума 150 → 10 %, а не «−150 грн» з префілу');

        $crawler = $this->editPage($id);
        self::assertSame(['percent', '10'], $this->typeAndDiscount($crawler));
        $this->submit($crawler, ['Promotion[type]' => 'amount', 'Promotion[discount]' => '20,50'], true);
        self::assertSame([PromotionType::Amount, 2050], $this->reloaded($id), '10 % → 20.50 грн');
    }

    /** @return iterable<string, array{array<string, string|bool>, string}> */
    public static function invalidDiscounts(): iterable
    {
        yield 'відсоток 0' => [['Promotion[type]' => 'percent', 'Promotion[discount]' => '0'], 'від 0,01 до 100'];
        yield 'відсоток 101' => [['Promotion[type]' => 'percent', 'Promotion[discount]' => '101'], 'від 0,01 до 100'];
        yield 'сума abc' => [['Promotion[type]' => 'amount', 'Promotion[discount]' => 'abc'], 'Сума знижки'];
        yield 'сума 0' => [['Promotion[type]' => 'amount', 'Promotion[discount]' => '0'], 'більшою за нуль'];
        yield 'порожньо' => [['Promotion[type]' => 'percent', 'Promotion[discount]' => ''], 'Вкажіть знижку'];
        yield 'без цілей і без «весь каталог»' => [['Promotion[type]' => 'percent', 'Promotion[discount]' => '5', 'Promotion[wholeCatalog]' => false], 'позначте «на весь каталог»'];
        yield 'кінець раніше за початок' => [['Promotion[type]' => 'percent', 'Promotion[discount]' => '5', 'Promotion[startsAt]' => '2026-10-10T00:00', 'Promotion[endsAt]' => '2026-10-09T23:59'], 'пізніше за початок'];
    }

    /** @param array<string, string|bool> $values */
    #[DataProvider('invalidDiscounts')]
    public function testInvalidInputIsFormErrorNot500(array $values, string $message): void
    {
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/promotion/new'));
        $this->submit($crawler, $values + ['Promotion[name]' => 'Невалідна', 'Promotion[wholeCatalog]' => true], false, true);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->client->getCrawler()->filter('.invalid-feedback, .form-error-message, .global-invalid-feedback')->text(''));
        self::assertNull($this->em()->getRepository(Promotion::class)->findOneBy(['name' => 'Невалідна']));
    }

    public function testDeletedTargetDoesNotTurnPromotionIntoWholeCatalog(): void
    {
        $brand = new Brand('Steelguard', 'steelguard-del');
        $other = new Product('PR-DEL', 'UAH');
        $other->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $promotion = new Promotion('Steelguard −30 %', 'UAH');
        $promotion->setPercentDiscount('30');
        $promotion->addBrand($brand);
        foreach ([$brand, $other, $promotion] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->em()->remove($brand);   // FK ON DELETE CASCADE прибирає рядок зв'язку, як і видалення бренду в адмінці
        $this->em()->flush();
        $this->em()->clear();

        $reloaded = $this->em()->find(Promotion::class, $promotion->getId()) ?? self::fail();
        self::assertFalse($reloaded->hasTargets());
        self::assertFalse($reloaded->isWholeCatalog());
        $variant = ($this->em()->find(Product::class, $other->getId()) ?? self::fail())->getDefaultVariant();
        self::assertFalse($reloaded->appliesTo($variant, null), 'Акція без цілей не діє на інші товари');
    }

    private function editPage(int $id): Crawler
    {
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/promotion/%d/edit', $id)));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array{string, string} */
    private function typeAndDiscount(Crawler $crawler): array
    {
        $form = $crawler->filter('form[name="Promotion"]')->form();

        return [(string) $form['Promotion[type]']->getValue(), (string) $form['Promotion[discount]']->getValue()];
    }

    /** @param array<string, string|bool> $values */
    private function submit(Crawler $crawler, array $values, bool $edit, bool $expectInvalid = false): void
    {
        $form = $crawler->filter('form[name="Promotion"]')->form();
        foreach ($values as $name => $value) {
            $field = $form[$name];
            if (\is_bool($value)) {
                self::assertInstanceOf(ChoiceFormField::class, $field);
                $value ? $field->tick() : $field->untick();
            } else {
                $field->setValue($value);
            }
        }
        $payload = $form->getPhpValues();
        $payload['ea'][$edit ? 'editForm' : 'newForm']['btn'] = 'saveAndReturn';
        $crawler = $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
        if (!$expectInvalid) {
            self::assertFormRedirected($crawler);
        }
    }

    /** @return array{PromotionType, int} */
    private function reloaded(int $id): array
    {
        $this->em()->clear();
        $promotion = $this->em()->find(Promotion::class, $id) ?? self::fail();

        return [$promotion->getType(), $promotion->getValue()];
    }
}
