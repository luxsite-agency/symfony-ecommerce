<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Order\OrderHistoryLine;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderStatusHistory;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\OrderStatus;
use App\Core\Order\Enum\PaymentStatus;
use App\Core\Order\OrderTransitionService;
use App\Core\Security\Entity\User;
use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Translation\ExceptionMessage;
use App\Core\Shared\Translation\TranslatableException;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use function Symfony\Component\Translation\t;

use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Contracts\Translation\TranslatorInterface;
use ValueError;

/**
 * Замовлення в адмінці (ADR D10): список з фільтрами, перегляд з рядками та історією, переходи станів кнопками
 * (лише дозволені Workflow, через OrderTransitionService), статус оплати і коментар менеджера — у формі редагування.
 * Створення/видалення замовлень з адмінки нема (джерело — сайт).
 *
 * @extends AbstractCrudController<Order>
 */
final class OrderCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    /** Переходи Workflow «order», для кожного — кнопка на картці з підписом order.action.<перехід>. */
    private const array TRANSITIONS = ['confirm', 'process', 'ship', 'complete', 'cancel'];

    public function __construct(private readonly OrderTransitionService $transitions, private readonly TranslatorInterface $translator)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('order.label.singular')
            ->setEntityLabelInPlural('order.label.plural')
            ->setSearchFields(['number', 'customerName', 'phone', 'email', 'items.sku'])
            ->setDefaultSort(['placedAt' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setDefaultRowAction(Action::DETAIL) // клік по рядку — картка замовлення, не форма
            ->overrideTemplate('crud/detail', 'admin/order/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->configureFormActions($actions->add(Crud::PAGE_INDEX, Action::DETAIL))
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->setPermission(Action::EDIT, 'ROLE_MANAGER');
        foreach (self::TRANSITIONS as $transition) {
            $action = Action::new('transition_'.$transition, 'order.action.'.$transition, 'cancel' === $transition ? 'fa fa-ban' : 'fa fa-arrow-right')
                ->linkToCrudAction('transition')
                ->setHtmlAttributes(['data-transition' => $transition])
                ->displayIf(fn (Order $order): bool => $this->transitions->can($order, $transition));
            // наступний крок — основна кнопка поруч із «Редагувати»; скасування — окремо ліворуч, контурне, з підтвердженням (аудит UX A5)
            $action->setCssClass('cancel' === $transition ? 'btn btn-outline-danger' : 'btn btn-primary');
            $actions->add(Crud::PAGE_DETAIL, $action);
        }

        return $actions->update(Crud::PAGE_DETAIL, Action::EDIT, static fn (Action $a): Action => $a->setCssClass('btn btn-secondary'));
    }

    /** Картка замовлення: історія людськими словами (templates/admin/order/detail.html.twig, змінна history). */
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $order = $responseParameters->get('entity')?->getInstance();
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName') && $order instanceof Order) {
            $responseParameters->set('history', array_map(fn (OrderStatusHistory $h): OrderHistoryLine => OrderHistoryLine::of($h, $this->translator), $order->getHistory()->toArray()));
        }

        return $responseParameters;
    }

    public function configureFilters(Filters $filters): Filters
    {
        // підписи варіантів — ключі enum.* у домені admin (ChoiceFilter типово перекладає в EasyAdminBundle)
        return $filters
            ->add(ChoiceFilter::new('status', 'order.filter.status')->setChoices(self::statusChoices())->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'))
            ->add(ChoiceFilter::new('paymentStatus', 'order.filter.payment_status')->setChoices(array_combine(array_map(static fn (PaymentStatus $s): string => $s->transKey(), PaymentStatus::cases()), PaymentStatus::cases()))
                ->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'))
            ->add(ChoiceFilter::new('source', 'order.filter.source')->setChoices(array_combine(array_map(static fn (OrderSource $s): string => $s->transKey(), OrderSource::cases()), OrderSource::cases()))
                ->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'))
            ->add(DateTimeFilter::new('placedAt', 'order.filter.placed_at'));
    }

    public function configureFields(string $pageName): iterable
    {
        $ui = $this->getContext()?->getRequest()->getLocale() ?? 'uk';

        // список: без ID (дублює номер), номер, сума й дата в один рядок, сума праворуч (аудит UX A3)
        yield TextField::new('number', 'order.field.number')->setDisabled()->addCssClass('text-nowrap');
        // у формі — текстом у форматі адмінки: нативне datetime-local показує дату в локалі браузера («10/17/2026, 11:07 AM»)
        yield DateTimeField::new('placedAt', 'order.field.placed_at')->setFormat('dd.MM.yyyy HH:mm')->setDisabled()->addCssClass('text-nowrap')
            ->renderAsText()->setFormTypeOptions(['html5' => false, 'format' => 'dd.MM.yyyy HH:mm']);
        // status — рядок у БД (не enumType): підписи — ключі enum.order_status.*, EasyAdmin перекладає їх у домені admin
        yield ChoiceField::new('status', 'order.field.status')
            ->setChoices(self::statusChoices())
            ->renderAsBadges(['new' => 'info', 'confirmed' => 'primary', 'processing' => 'warning', 'shipped' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'])
            ->setDisabled();
        // enum-поля без setChoices: варіанти — з enumType Doctrine, підпис — trans() через TranslatableInterface; бейджі — за назвою case
        yield ChoiceField::new('paymentStatus', 'order.field.payment_status')
            ->renderAsBadges([PaymentStatus::Pending->name => 'warning', PaymentStatus::PartiallyPaid->name => 'info', PaymentStatus::Paid->name => 'success', PaymentStatus::Refunded->name => 'secondary']);
        yield ChoiceField::new('source', 'order.field.source')->setDisabled()->hideOnIndex();
        yield TextField::new('customerName', 'order.field.customer_name')->setDisabled();
        yield TelephoneField::new('phone', 'order.field.phone')->setDisabled();
        yield EmailField::new('email', 'order.field.email')->setDisabled()->hideOnIndex();
        yield TextField::new('company', 'order.field.company')->setDisabled()->hideOnIndex();
        yield TextField::new('total', 'order.field.total')->setSortable(false)->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')->onlyOnIndex()->setTextAlign('right')->addCssClass('text-nowrap')
            ->formatValue(static fn ($v, ?Order $o): string => null === $o ? '' : DisplayFormat::money($o->getTotal(), $ui));
        yield ChoiceField::new('shippingMethod', 'order.field.shipping_method')->hideOnIndex()->setDisabled();
        yield TextField::new('shippingCity', 'order.field.shipping_city')->hideOnIndex()->setDisabled();
        yield TextField::new('shippingAddress', 'order.field.shipping_address')->hideOnIndex()->setDisabled();
        yield TextField::new('shippingPointSummary', 'order.field.shipping_point')->onlyOnDetail()->setSortable(false);
        yield ChoiceField::new('paymentMethod', 'order.field.payment_method')->hideOnIndex()->setDisabled();
        yield TextareaField::new('comment', 'order.field.comment')->hideOnIndex()->setRequired(false);
        yield TextField::new('locale', 'order.field.locale')->onlyOnDetail();
        yield TextField::new('ip', 'order.field.ip')->onlyOnDetail();
    }

    /**
     * Перехід стану з кнопки на сторінці перегляду: POST transition=confirm|process|ship|complete|cancel + CSRF-токен.
     *
     * @param AdminContext<Order> $context
     */
    #[AdminRoute(path: '/{entityId}/transition', name: 'transition', options: ['methods' => ['POST']])]
    public function transition(AdminContext $context, Request $request): Response
    {
        $order = $context->getEntity()->getInstance();
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }
        $transition = (string) $request->request->get('transition', '');
        if (!$this->isCsrfTokenValid('order-transition-'.$order->getId(), (string) $request->request->get('token', ''))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
        $user = $this->getUser();
        try {
            $this->transitions->apply($order, $transition, $user instanceof User ? $user->getEmail() : 'admin', (string) $request->request->get('comment', '') ?: null);
            // флеші EasyAdmin перекладаються |trans без домену — тому TranslatableMessage з доменом admin
            $this->addFlash('success', t('order.flash.transitioned', ['number' => $order->getNumber(), 'status' => $order->getStatusEnum()], 'admin'));
        } catch (LogicException|ValueError $e) {
            // текст винятку Workflow містить коди переходу й стану — показуємо їх словами мовою адмінки, а не getMessage()
            $this->addFlash('danger', $e instanceof TranslatableException
                ? t('order.flash.transition_failed', ['error' => htmlspecialchars(ExceptionMessage::trans($e, $this->translator), \ENT_QUOTES)], 'admin')
                : t('order.flash.transition_unavailable', [
                    'transition' => \in_array($transition, self::TRANSITIONS, true) ? t('order.action.'.$transition, [], 'admin') : htmlspecialchars($transition, \ENT_QUOTES),
                    'status' => OrderStatus::tryFrom($order->getStatus()) ?? htmlspecialchars($order->getStatus(), \ENT_QUOTES),
                ], 'admin'));
        }

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->setController(self::class)->setAction(Action::DETAIL)->setEntityId($order->getId())->generateUrl());
    }

    /** @return array<string, string> ключ підпису (enum.order_status.*) => код стану */
    private static function statusChoices(): array
    {
        return array_combine(array_map(static fn (OrderStatus $s): string => $s->transKey(), OrderStatus::cases()), array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()));
    }
}
