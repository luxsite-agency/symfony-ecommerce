<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Filter\InStockFilter;
use App\Admin\Form\CollectionSync;
use App\Admin\Form\Product\OptionGroupType;
use App\Admin\Form\Product\ProductAttributesType;
use App\Admin\Form\Product\ProductMediaType;
use App\Admin\Form\Product\ProductVariantType;
use App\Admin\Form\Translation\ProductTranslationType;
use App\Admin\Form\Translation\TranslationsType;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\OptionGroup;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductMedia;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Quantity\Quantity;
use App\Site\SiteUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Товар з варіантами (ADR D10, Rev 2/6/7): переклади, категорії, варіанти з осями й порогами цін,
 * характеристики, групи опцій, галерея, прапорець «На головній» (вітрина). Список без fetch-join, пошук лише за sku і назвою (EXISTS-підзапити).
 *
 * @extends AbstractCrudController<Product>
 */
final class ProductCrudController extends AbstractCrudController
{
    use SaveActionsTrait;
    use ViewOnSiteTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteUrlGenerator $siteUrls,
        #[Autowire('%app.default_currency%')] private readonly string $defaultCurrency,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('product.label.singular')
            ->setEntityLabelInPlural('product.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'product.title.new')
            // h1 форми — назва товару, щоб було видно, що редагуєш (аудит UX A10); рядок як є переклався б як ключ
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Product $p) => t('product.title.edit', ['name' => (string) $p], 'admin'))
            // без підказки (i) біля заголовка: що шукається, каже саме поле пошуку (search.hint.product)
            ->setSearchFields(['id'])
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->setPaginatorPageSize(30);
    }

    public function configureActions(Actions $actions): Actions
    {
        $activate = Action::new('batchActivate', 'product.action.activate', 'fa fa-toggle-on')->linkToCrudAction('batchActivate')->createAsBatchAction();
        $deactivate = Action::new('batchDeactivate', 'product.action.deactivate', 'fa fa-toggle-off')->linkToCrudAction('batchDeactivate')->createAsBatchAction();
        $feature = Action::new('batchFeature', 'product.action.feature', 'fa fa-star')->linkToCrudAction('batchFeature')->createAsBatchAction();
        $unfeature = Action::new('batchUnfeature', 'product.action.unfeature', 'fa-regular fa-star')->linkToCrudAction('batchUnfeature')->createAsBatchAction();

        $actions = $this->addViewOnSiteAction($actions->add(Crud::PAGE_INDEX, Action::DETAIL), fn (object $p): ?string => $p instanceof Product ? $this->siteUrls->product($p) : null);

        return $this->configureFormActions($actions, 'product.action.add')
            ->addBatchAction($activate)
            ->addBatchAction($deactivate)
            ->addBatchAction($feature)
            ->addBatchAction($unfeature);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('categories', 'product.filter.category'))
            ->add(EntityFilter::new('brand', 'product.filter.brand'))
            ->add(BooleanFilter::new('active', 'product.filter.active'))
            ->add(BooleanFilter::new('featured', 'product.filter.featured'))
            ->add(InStockFilter::new());
    }

    /** @param BatchActionDto<Product> $batchActionDto */
    #[AdminRoute(path: '/batch-activate', name: 'batch_activate')]
    public function batchActivate(BatchActionDto $batchActionDto): Response
    {
        return $this->toggleActive($batchActionDto, true);
    }

    /** @param BatchActionDto<Product> $batchActionDto */
    #[AdminRoute(path: '/batch-deactivate', name: 'batch_deactivate')]
    public function batchDeactivate(BatchActionDto $batchActionDto): Response
    {
        return $this->toggleActive($batchActionDto, false);
    }

    /** @param BatchActionDto<Product> $batchActionDto */
    #[AdminRoute(path: '/batch-feature', name: 'batch_feature')]
    public function batchFeature(BatchActionDto $batchActionDto): Response
    {
        return $this->toggleFeatured($batchActionDto, true);
    }

    /** @param BatchActionDto<Product> $batchActionDto */
    #[AdminRoute(path: '/batch-unfeature', name: 'batch_unfeature')]
    public function batchUnfeature(BatchActionDto $batchActionDto): Response
    {
        return $this->toggleFeatured($batchActionDto, false);
    }

    /** @param BatchActionDto<Product> $batchActionDto */
    private function toggleActive(BatchActionDto $batchActionDto, bool $active): Response
    {
        return $this->applyBatch(
            $batchActionDto,
            static fn (Product $p): bool => $p->isActive() !== $active,
            static fn (Product $p) => $p->setActive($active),
            $active ? 'product.flash.activated' : 'product.flash.deactivated',
        );
    }

    /** @param BatchActionDto<Product> $batchActionDto */
    private function toggleFeatured(BatchActionDto $batchActionDto, bool $featured): Response
    {
        return $this->applyBatch(
            $batchActionDto,
            static fn (Product $p): bool => $p->isFeatured() !== $featured,
            static fn (Product $p) => $p->setFeatured($featured),
            $featured ? 'product.flash.featured' : 'product.flash.unfeatured',
        );
    }

    /**
     * Масова зміна прапорця: CSRF масової дії EasyAdmin, зміна лише тих, у кого значення інше, флеш з кількістю
     * ($messageKey — ключ домену admin з параметром {count}).
     *
     * @param BatchActionDto<Product>  $batchActionDto
     * @param callable(Product): bool  $needsChange
     * @param callable(Product): mixed $change
     */
    private function applyBatch(BatchActionDto $batchActionDto, callable $needsChange, callable $change, string $messageKey): Response
    {
        if (!$this->isCsrfTokenValid('ea-batch-action-'.$batchActionDto->getName().'-'.$batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен масової дії');
        }
        $count = 0;
        foreach ($batchActionDto->getEntityIds() as $id) {
            $product = $this->em->find(Product::class, $id);
            if (null !== $product && $needsChange($product)) {
                $change($product);
                ++$count;
            }
        }
        $this->em->flush();
        $this->addFlash('success', t($messageKey, ['count' => $count], 'admin'));

        $url = $this->container->get(AdminUrlGeneratorInterface::class)
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        $locale = $this->defaultLocale;
        $ui = $this->getContext()?->getRequest()->getLocale() ?? 'uk';

        yield IdField::new('id', 'field.id')->onlyOnIndex();
        // Віртуальні колонки: властивості на Product нема, значення дає formatValue; шаблон задано явно, інакше EA покаже «Недоступно».
        yield TextField::new('sku', 'product.field.sku')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?Product $p): string => $p?->getDefaultVariant()->getSku() ?? '');
        yield TextField::new('name', 'product.field.name')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?Product $p): string => $p?->getTranslation($locale)?->getName() ?? '');
        yield TextField::new('price', 'product.field.price')->onlyOnIndex()->setTextAlign('right')->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?Product $p): string => null === $p ? '' : DisplayFormat::money($p->getDefaultVariant()->getPrice(), $ui));
        yield TextField::new('variantsCount', 'product.field.variants_count')->onlyOnIndex()->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static fn ($v, ?Product $p): int => $p?->getVariants()->count() ?? 0);

        yield FormField::addTab('product.tab.main')->onlyOnForms();
        yield ChoiceField::new('kind', 'product.field.kind')
            ->setFormTypeOption('choice_value', static fn (?ProductKind $k): ?string => $k?->value)
            ->hideOnIndex();
        yield ChoiceField::new('priceMode', 'product.field.price_mode')
            ->setFormTypeOption('choice_value', static fn (?PriceMode $m): ?string => $m?->value)
            ->hideOnIndex();
        yield AssociationField::new('brand', 'product.field.brand')->setRequired(false);
        yield AssociationField::new('categories', 'product.field.categories')
            ->hideOnIndex()
            ->setFormTypeOptions([
                'by_reference' => false,
                'choice_label' => static fn (Category $c): string => $c->getPathLabel(),
            ]);
        yield AssociationField::new('mainCategory', 'product.field.main_category')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('product.help.main_category')
            ->setFormTypeOptions([
                'choice_label' => static fn (Category $c): string => $c->getPathLabel(),
                'setter' => static function (Product $p, ?Category $c): void {
                    if (null !== $c) {
                        $p->setMainCategory($c);
                    }
                },
            ]);
        yield BooleanField::new('active', 'product.field.active')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName);
        yield BooleanField::new('featured', 'product.field.featured')->renderAsSwitch(Crud::PAGE_INDEX !== $pageName)
            ->setHelp('product.help.featured');
        yield DateTimeField::new('updatedAt', 'product.field.updated_at')->hideOnForm();
        yield DateTimeField::new('sourceUpdatedAt', 'product.field.source_updated_at')->onlyOnDetail();

        yield FormField::addTab('product.tab.texts')->onlyOnForms();
        yield Field::new('translationsForm', false)
            ->onlyOnForms()
            ->setFormType(TranslationsType::class)
            ->setFormTypeOptions(['entry_type' => ProductTranslationType::class]);

        yield FormField::addTab('product.tab.variants')->onlyOnForms()->setHelp('product.help.variants');
        yield CollectionField::new('variants', false)
            ->onlyOnForms()
            ->setEntryType(ProductVariantType::class)
            ->setEntryIsComplex()
            ->setEntryToStringMethod(fn (?ProductVariant $v): string => self::variantTitle($v, $this->translator, $this->getContext()?->getRequest()->getLocale() ?? 'uk'))
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOptions([
                'row_attr' => ['data-add-label' => $this->translator->trans('product.action.add_variant', [], 'admin')], // підпис кнопки — admin-collection.js
                'by_reference' => false,
                'setter' => CollectionSync::setter(
                    static fn (Product $p) => $p->getVariants(),
                    static fn (Product $p, object $v) => $v instanceof ProductVariant ? $p->removeVariant($v) : null,
                ),
            ]);

        yield FormField::addTab('product.tab.attributes')->onlyOnForms()->setHelp('product.help.attributes');
        yield Field::new('attributesForm', false)
            ->onlyOnForms()
            ->setFormType(ProductAttributesType::class);

        yield FormField::addTab('product.tab.options')->onlyOnForms()->setHelp('product.help.options');
        yield CollectionField::new('optionGroups', false)
            ->onlyOnForms()
            ->setEntryType(OptionGroupType::class)
            ->setEntryIsComplex()
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOptions([
                'by_reference' => false,
                'setter' => CollectionSync::setter(
                    static fn (Product $p) => $p->getOptionGroups(),
                    static fn (Product $p, object $g) => $g instanceof OptionGroup ? $p->removeOptionGroup($g) : null,
                ),
            ]);

        yield FormField::addTab('product.tab.gallery')->onlyOnForms()->setHelp('product.help.gallery');
        yield CollectionField::new('media', false)
            ->onlyOnForms()
            ->setEntryType(ProductMediaType::class)
            ->setEntryIsComplex()
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOptions([
                'by_reference' => false,
                'setter' => CollectionSync::setter(
                    static fn (Product $p) => $p->getMedia(),
                    static fn (Product $p, object $link) => $link instanceof ProductMedia ? $p->removeMedia($link->getMedia()) : null,
                ),
            ]);
    }

    public function createEntity(string $entityFqcn): Product
    {
        // Перший варіант обов'язковий у конструкторі; його SKU-заглушку форма показує порожньою (ProductVariantType).
        return new Product(ProductVariantType::DRAFT_SKU_PREFIX.bin2hex(random_bytes(4)), $this->defaultCurrency);
    }

    /**
     * Заголовок згорнутого варіанта у формі: «SKU · 700×2000 · Білий · 4 200,00 ₴ · 5 шт» — без розгортання кожного (аудит UX A10).
     * Мовою адмінки $locale: «Новий варіант» — product.variant.new, одиниця — catalog_form.unit.*, ціна — у форматі мови.
     */
    public static function variantTitle(?ProductVariant $v, TranslatorInterface $translator, string $locale): string
    {
        if (null === $v) {
            return $translator->trans('product.variant.new', [], 'admin', $locale);
        }
        $parts = [$v->getSku()];
        foreach ($v->getAxisValues() as $axis) {
            $parts[] = (string) $axis->getValue();
        }
        $parts[] = $v->getPrice()->format($locale);
        if (StockPolicy::InStock === $v->getStockPolicy()) {
            $parts[] = Quantity::display($v->getStock()).' '.$translator->trans(ProductVariantType::UNIT_LABEL_PREFIX.$v->getUnit()->value, [], 'admin', $locale);
        }

        return implode(' · ', $parts);
    }
}
