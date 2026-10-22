<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Ai\AiFeature;
use App\Core\Ai\AiGateway;
use App\Core\Ai\AiUsageStats;
use App\Core\Ai\AiUsageStatus;
use App\Core\Ai\Entity\AiUsage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

use function Symfony\Component\Translation\t;

/**
 * Журнал використання AI (E15 BYOK), лише читання: хто, що, модель, токени, вартість у кредитах OpenRouter.
 * Видно менеджерам — вони відповідають за витрати на свої запити; ключ і налаштування — в адміністратора.
 *
 * @extends AbstractCrudController<AiUsage>
 */
final class AiUsageCrudController extends AbstractCrudController
{
    public function __construct(private readonly AiGateway $ai, private readonly AiUsageStats $stats)
    {
    }

    public static function getEntityFqcn(): string
    {
        return AiUsage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $month = $this->stats->monthSummary();
        $budget = $this->ai->monthlyBudget();

        return $crud
            ->setEntityLabelInSingular('ai_usage.label.singular')
            ->setEntityLabelInPlural('ai_usage.label.plural')
            // типовий заголовок — (string) сутності українською; тут — функція мовою адмінки
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (AiUsage $u) => t('ai_usage.title.detail', ['feature' => $u->getFeature(), 'id' => (string) ($u->getId() ?? 0)], 'admin'))
            ->setSearchFields(['actor', 'model', 'error'])
            ->setDefaultSort(['occurredAt' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->hideNullValues()
            // суми — як і раніше, number_format (форматування грошей — окремий PR); числа рядками, щоб ICU не додало розділювач тисяч
            ->setHelp(Crud::PAGE_INDEX, t($budget > 0 ? 'ai_usage.help.index_budget' : 'ai_usage.help.index', [
                'calls' => (string) $month['calls'],
                'ok' => (string) $month['ok'],
                'cost' => number_format($month['cost'], 2, '.', ''),
                'budget' => number_format($budget, 2, '.', ''),
            ], 'admin'))
            ->overrideTemplate('crud/index', 'admin/ai_usage/index.html.twig');
    }

    /** Над журналом — стан ключа: без нього журнал порожній, і власник шукав ключ саме тут (templates/admin/ai_usage). */
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $responseParameters->set('ai', [
                'keyState' => $this->ai->keyState()->value,
                'configured' => $this->ai->isConfigured(),
                'reason' => $this->ai->notConfiguredReason(),
            ]);
        }

        return $responseParameters;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('feature', 'ai_usage.field.feature')->setChoices(self::choices('enum.ai_feature.', AiFeature::cases()))->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'))
            ->add(ChoiceFilter::new('status', 'ai_usage.field.status')->setChoices(self::choices('enum.ai_usage_status.', AiUsageStatus::cases()))->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'))
            ->add(TextFilter::new('actor', 'ai_usage.field.actor'))
            ->add(TextFilter::new('model', 'ai_usage.field.model'))
            ->add(NumericFilter::new('userId', 'ai_usage.field.user'))
            ->add(DateTimeFilter::new('occurredAt', 'ai_usage.field.occurred_at'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('occurredAt', 'ai_usage.field.occurred_at')->setFormat('dd.MM.yyyy HH:mm:ss');
        yield TextField::new('actor', 'ai_usage.field.actor');
        // enum без setChoices: варіанти — з enumType Doctrine, підпис — trans() мовою адмінки
        yield ChoiceField::new('feature', 'ai_usage.field.feature');
        yield ChoiceField::new('status', 'ai_usage.field.status');
        yield TextField::new('model', 'ai_usage.field.model');
        yield TextField::new('entityType', 'ai_usage.field.entity_type');
        yield IntegerField::new('entityId', 'ai_usage.field.entity_id');
        yield IntegerField::new('promptTokens', 'ai_usage.field.prompt_tokens');
        yield IntegerField::new('completionTokens', 'ai_usage.field.completion_tokens');
        yield TextField::new('costText', 'ai_usage.field.cost')->setSortable(false);
        yield IntegerField::new('durationMs', 'ai_usage.field.duration_ms')->hideOnIndex();
        yield IntegerField::new('changesetId', 'ai_usage.field.changeset_id')->hideOnIndex();
        yield TextField::new('error', 'ai_usage.field.error')->hideOnIndex();
    }

    /**
     * Варіанти фільтра: ключ перекладу (домен admin) → значення.
     *
     * @param list<AiFeature|AiUsageStatus> $cases
     *
     * @return array<string, string>
     */
    private static function choices(string $keyPrefix, array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            $out[$keyPrefix.$case->value] = $case->value;
        }

        return $out;
    }
}
