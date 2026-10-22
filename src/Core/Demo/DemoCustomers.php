<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\B2b\Entity\Company;
use App\Core\B2b\Entity\CompanyMember;
use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Catalog\Review\ProductRatings;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAddress;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Customer\Entity\ViewedProduct;
use App\Core\Customer\Entity\WishlistItem;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Order\Enum\ShippingMethod;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Демо-покупці (DemoAccounts) для публічного демо: роздрібний (підтверджений email, телефон-заглушка, обране,
 * переглянуті, кошик з двома позиціями, 8 замовлень у різних станах), оптовий (компанія з реквізитами-заглушками,
 * член компанії, група DemoCatalog::CUSTOMER_GROUP, 5 замовлень за оптовими цінами) і заявник на опт з заявкою, що
 * чекає менеджера. У кожного — згода на ПД (журнал згод) і адреси доставки (роздрібний дві, основна — курʼєр). Усе лише
 * коли задано app.demo.customer_password; ідемпотентно (покупець за email, замовлення за позначкою).
 *
 * purge() прибирає все своє: усі замовлення цих покупців з поверненням резерву залишків, кошики, покупців (обране, переглянуті, членство, заявка, токени —
 * каскадом FK) і демо-компанію без членів. Нічне скидання (ResetDemoCustomers) = purge() + seed().
 *
 * @phpstan-import-type OrderPlan from DemoOrderFactory
 */
final class DemoCustomers
{
    public const string COMPANY_TAX_ID = '00000001';
    public const string RETAIL_PHONE = '+380000000101';
    public const string WHOLESALE_PHONE = '+380000000102';
    public const string APPLICANT_PHONE = '+380000000103';

    /** @var array<string, int> */
    private array $report = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly DemoAccounts $accounts,
        private readonly DemoOrderFactory $orders,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly DemoLocales $locales,
        private readonly Consents $consents,
        private readonly ProductRatings $ratings,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
    ) {
    }

    /** @return array<string, int> що створено */
    public function seed(): array
    {
        $this->report = [];
        if (!$this->accounts->isEnabled()) {
            return [];
        }
        $group = $this->em->getRepository(CustomerGroup::class)->findOneBy(['code' => DemoCatalog::CUSTOMER_GROUP])
            ?? throw new RuntimeException('Спершу група покупців DemoCatalog::CUSTOMER_GROUP (DemoSeeder::seedCustomerGroup)');

        $retail = $this->customer(DemoAccounts::RETAIL, 'Олена Демо', self::RETAIL_PHONE);
        $this->addresses($retail, [
            ['Дім', ShippingMethod::Courier, 'Київ', 'просп. Демонстраційний, 7, кв. 3', true],
            ['Робота', ShippingMethod::NovaPoshta, 'Київ', 'Відділення № 12 (демо)', false],
        ]);
        $this->seedRetailExtras($retail);
        foreach (DemoOrderPlans::retail($retail->getName()) as $n => $plan) {
            $this->order($n, $plan, $retail);
        }

        $wholesale = $this->customer(DemoAccounts::WHOLESALE, 'Андрій Оптовий', self::WHOLESALE_PHONE);
        $company = $this->company($wholesale, $group);
        $this->addresses($wholesale, [['Склад', ShippingMethod::Courier, 'Київ', 'вул. Складська, 3 (демо)', true]]);
        foreach (DemoOrderPlans::wholesale($company->getName()) as $n => $plan) {
            $this->order($n, $plan, $wholesale);
        }

        $applicant = $this->customer(DemoAccounts::APPLICANT, 'Ірина Заявник', self::APPLICANT_PHONE);
        if (null === $this->em->getRepository(WholesaleApplication::class)->findOneBy(['customer' => $applicant])) {
            $this->em->persist(new WholesaleApplication($applicant, 'ФОП Демо Заявник', '0000000002', false, 'Ірина Заявник', self::APPLICANT_PHONE, 'Демо-заявка: чекає на розгляд менеджера. Плануємо закупівлі дверей і фурнітури.'));
            $this->em->flush();
            $this->count('wholesale_applications');
        }

        return $this->report;
    }

    /** @return array<string, int> що видалено */
    public function purge(): array
    {
        // одна транзакція: повернення резерву і DELETE або разом, або ніяк, інакше повторне скидання після збою додало б резерв удруге (рев'ю #66 v2 L2)
        return $this->db->transactional(function (): array {
            $report = $this->purgeStatements();
            $this->em->clear();

            return array_filter($report, static fn (int $n): bool => $n > 0);
        });
    }

    /** @return array<string, int> */
    private function purgeStatements(): array
    {
        $report = [];
        $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM customer WHERE email IN (?)', [DemoAccounts::EMAILS], [ArrayParameterType::STRING]));
        if ([] !== $ids) {
            // усі замовлення демо-покупців (і ті, що оформили відвідувачі демо: інакше після DELETE покупця вони стають «гостьовими»
            // і новий демо-покупець забирає їх через claim; рев'ю #66 L1), спершу повернувши резерв залишків (M2: інакше щоночі
            // seed списує склад демо-товарів знову; скасовані вже повернули резерв, у них reserved_stock = 0)
            foreach ($this->db->fetchAllAssociative(
                'SELECT i.variant_id, SUM(i.reserved_stock) AS reserved FROM sales_order_item i JOIN sales_order o ON o.id = i.order_id
                 WHERE o.customer_id IN (?) AND i.variant_id IS NOT NULL GROUP BY i.variant_id',
                [$ids],
                [ArrayParameterType::INTEGER],
            ) as $row) {
                $this->db->executeStatement('UPDATE catalog_product_variant SET stock = stock + ? WHERE id = ?', [(string) $row['reserved'], (int) $row['variant_id']]);
            }
            $report['customer_orders'] = $this->db->executeStatement('DELETE FROM sales_order WHERE customer_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $report['carts'] = $this->db->executeStatement('DELETE FROM cart WHERE customer_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            // обране, переглянуті, членство, заявки, токени — каскадом FK
            // відгуки демо-покупців зникають каскадом з покупцем; агрегати оцінок їхніх товарів — перерахувати
            $reviewed = array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT product_id FROM catalog_product_review WHERE customer_id IN (?)', [$ids], [ArrayParameterType::INTEGER]));
            $report['customers'] = $this->db->executeStatement('DELETE FROM customer WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $this->ratings->refreshMany($reviewed);
        }
        $report['companies'] = $this->db->executeStatement(
            'DELETE FROM company WHERE tax_id = ? AND NOT EXISTS (SELECT 1 FROM company_member m WHERE m.company_id = company.id)',
            [self::COMPANY_TAX_ID],
        );

        return $report;
    }

    private function customer(string $email, string $name, string $phone): Customer
    {
        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
        if (null === $customer) {
            $customer = new Customer($email, $name, $this->locales->default());
            $customer->verifyEmail($this->hasher->hashPassword($customer, $this->accounts->password()));
            $customer->setPhone($phone);
            $this->em->persist($customer);
            $this->em->flush();
            $this->consents->record($customer, ConsentPurpose::PdProcessing, true, 'register', $this->locales->default());
            $this->em->flush();
            $this->count('customers');
        }

        return $customer;
    }

    /** @param list<array{string, ShippingMethod, string, string, bool}> $specs підпис, спосіб, місто, адреса, основна */
    private function addresses(Customer $customer, array $specs): void
    {
        if (0 !== $this->em->getRepository(CustomerAddress::class)->count(['customer' => $customer])) {
            return;
        }
        foreach ($specs as [$label, $method, $city, $address, $default]) {
            $entry = new CustomerAddress($customer);
            $entry->fill($label, $customer->getName(), (string) $customer->getPhone(), $method, $city, $address, null);
            $entry->setDefault($default);
            $this->em->persist($entry);
            $this->count('addresses');
        }
        $this->em->flush();
    }

    private function seedRetailExtras(Customer $customer): void
    {
        if (0 === $this->em->getRepository(WishlistItem::class)->count(['customer' => $customer])) {
            foreach (['DV-P03', 'TL-G02', 'FT-002'] as $key) {
                $variant = $this->orders->variant($key);
                if (null !== $variant) {
                    $this->em->persist(new WishlistItem($customer, $variant->getProduct()));
                    $this->count('wishlist_items');
                }
            }
        }
        if (0 === $this->em->getRepository(ViewedProduct::class)->count(['customer' => $customer])) {
            foreach (['DV-P01', 'DV-V01', 'TL-D01', 'RK-S02', 'MT-002'] as $key) {
                $variant = $this->orders->variant($key);
                if (null !== $variant) {
                    $this->em->persist(new ViewedProduct($customer, $variant->getProduct()));
                    $this->count('viewed_products');
                }
            }
        }
        // демо-відгуки (рішення власника 13.10.2026): не вигадані «справжні» — з позначкою demo (на сторінці «Демо-відгук», у schema.org не йдуть)
        if (0 === $this->em->getRepository(ProductReview::class)->count(['customer' => $customer])) {
            $productIds = [];
            // текст зберігається мовою за замовчуванням; іншими мовами вітрина показує DemoTexts::reviewText()
            foreach (DemoTexts::reviews() as $key => ['rating' => $rating, 'text' => $texts]) {
                $variant = $this->orders->variant($key);
                if (null === $variant) {
                    continue;
                }
                $review = new ProductReview($variant->getProduct(), $customer, $rating, $this->locales->text($texts, $this->locales->default()), $this->locales->default());
                $review->markDemo();
                $review->moderate(ReviewStatus::Approved, null);
                $this->em->persist($review);
                $productIds[] = (int) $variant->getProduct()->getId();
                $this->count('reviews');
            }
            $this->em->flush();
            $this->ratings->refreshMany($productIds);
        }
        if (null === $this->em->getRepository(Cart::class)->findOneBy(['customer' => $customer])) {
            $cart = new Cart(hash('sha256', 'demo-cart-'.$customer->getEmail()), $this->currency);
            $cart->assignTo($customer);
            foreach ([['FT-001', '2'], ['TL-H03', '1']] as [$key, $quantity]) {
                $variant = $this->orders->variant($key);
                if (null !== $variant) {
                    $cart->addItem($variant, $quantity);
                }
            }
            $this->em->persist($cart);
            $this->count('carts');
        }
        $this->em->flush();
    }

    private function company(Customer $customer, CustomerGroup $group): Company
    {
        $company = $this->em->getRepository(Company::class)->findOneBy(['taxId' => self::COMPANY_TAX_ID]);
        if (null === $company) {
            $company = new Company('ТОВ «Демо Опт»', self::COMPANY_TAX_ID, true, 'Андрій Оптовий', self::WHOLESALE_PHONE);
            $this->em->persist($company);
            $this->count('companies');
        }
        if (null === $this->em->getRepository(CompanyMember::class)->findOneBy(['customer' => $customer])) {
            $this->em->persist(new CompanyMember($company, $customer));
            $this->count('company_members');
        }
        if (null === $customer->getCustomerGroup()) {
            $customer->setCustomerGroup($group);
        }
        $this->em->flush();

        return $company;
    }

    /**
     * @param OrderPlan $plan
     */
    private function order(int $n, array $plan, Customer $customer): void
    {
        if ($this->orders->exists($n)) {
            return;
        }
        if (null !== $this->orders->place($n, $plan, $customer)) {
            $this->count('customer_orders');
        }
    }

    private function count(string $what, int $n = 1): void
    {
        $this->report[$what] = ($this->report[$what] ?? 0) + $n;
    }
}
