<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Admin\Settings\GoogleSignInPanel;
use App\Core\Ai\AiGateway;
use App\Core\Ai\AiUsageStats;
use App\Core\Ai\Exception\AiException;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Security\Entity\User;
use App\Core\Settings\Entity\Setting;
use App\Core\Settings\SecretState;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use App\Site\Feed\FeedLinks;
use App\Site\SiteUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Налаштування сайту (лише ROLE_ADMIN): один рядок на ключ із SettingKeys, лише редагування значення.
 * HTML-коди зберігаються як є і в списку показуються текстом (екрановано). Кеш Settings скидається при збереженні.
 * Секретні ключі (E15 BYOK): поле пароля завжди порожнє, порожнє при збереженні = лишити чинне, у списку — маска;
 * зміна (без значення) — у security_audit (user_id + HMAC email, як AdminLoginAudit). Конверт, якого не розшифрувати
 * (SETTINGS_SECRET_KEY змінено/втрачено), — бейдж і банер «введіть знову», не 500. Зміна будь-якого іншого ключа —
 * settings.changed у security_audit (код, хто, коли; без значень). Під списком — розділ «Вхід через Google» (GoogleSignInPanel).
 * Підписи й підказки ключів — мовою адмінки за SettingKeys::labelKey()/helpKey() (домен admin).
 *
 * @extends AbstractCrudController<Setting>
 */
#[IsGranted('ROLE_ADMIN')]
#[WithMonologChannel('security_audit')]
final class SettingCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    private const string CLEAR_FIELD = 'clearSecret';
    private const string AI_CHECK_CSRF = 'settings-ai-check';

    public function __construct(
        private readonly Settings $settings,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly FeedLinks $feeds,
        private readonly SecretBox $box,
        private readonly AiGateway $ai,
        private readonly AiUsageStats $aiStats,
        private readonly GoogleSignInPanel $google,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGeneratorInterface $adminUrls,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.secret%')] private readonly string $kernelSecret = '',
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Setting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('setting.label.singular')
            ->setEntityLabelInPlural('setting.label.plural')
            // типовий заголовок — (string) Setting українською; тут — підпис ключа мовою адмінки
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Setting $s) => t(SettingKeys::labelKey($s->getCode()), [], 'admin'))
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Setting $s) => t(SettingKeys::labelKey($s->getCode()), [], 'admin'))
            ->setEntityPermission('ROLE_ADMIN')
            ->setSearchFields(['code', 'value'])
            ->setDefaultSort(['code' => 'ASC'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'setting.help.index')
            ->overrideTemplate('crud/index', 'admin/setting/index.html.twig');
    }

    /** Під списком — адреси товарних фідів для Merchant Center і Meta та час останньої генерації (templates/admin/setting). */
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $responseParameters->set('feeds', $this->feeds->all());
            $responseParameters->set('ai', [
                'keyAvailable' => $this->box->isAvailable(),
                'keyProblem' => $this->box->keyProblem(),
                'keyState' => $this->ai->keyState()->value,
                'reason' => $this->ai->notConfiguredReason(),
                'configured' => $this->ai->isConfigured(),
                'model' => $this->ai->model(),
                'budget' => $this->ai->monthlyBudget(),
                'month' => $this->aiStats->monthSummary(),
                'csrf' => self::AI_CHECK_CSRF,
                'edit' => $this->editUrls([SettingKeys::AI_OPENROUTER_KEY, SettingKeys::AI_MODEL, SettingKeys::AI_MONTHLY_BUDGET_USD]),
            ]);
            $responseParameters->set('google', $this->google->view());
        }

        return $responseParameters;
    }

    /**
     * Прямі посилання на редагування рядків (блок AI під списком): власник не мусить шукати меню дій рядка.
     * Рядки вже створив ensureRows() у createIndexQueryBuilder.
     *
     * @param list<string> $codes
     *
     * @return array<string, string> code => URL сторінки редагування
     */
    private function editUrls(array $codes): array
    {
        $urls = [];
        foreach ($this->em->getRepository(Setting::class)->findBy(['code' => $codes]) as $setting) {
            $urls[$setting->getCode()] = $this->adminUrls->unsetAll()->setController(self::class)->setAction(Action::EDIT)->setEntityId($setting->getId())->generateUrl();
        }

        return $urls;
    }

    public function configureActions(Actions $actions): Actions
    {
        $check = Action::new('checkOnSite', 'setting.action.check_on_site', 'fa fa-external-link')
            ->linkToUrl(fn (): string => $this->siteUrls->home($this->siteUrls->defaultLocale()))
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener'])
            ->createAsGlobalAction();

        return $this->configureFormActions($actions)
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, $check)
            ->add(Crud::PAGE_EDIT, $check);
    }

    public function configureFields(string $pageName): iterable
    {
        // назва — посилання на редагування: меню дій рядка власник не знаходив
        $tr = $this->translator;
        yield TextField::new('label', 'setting.field.label')->onlyOnIndex()->setSortable(false)->setTemplatePath('admin/setting/field/label.html.twig')
            ->formatValue(static fn ($v, ?Setting $s): string => null === $s ? '' : $tr->trans(SettingKeys::groupKey($s->getCode()), [], 'admin').' → '.$tr->trans(SettingKeys::labelKey($s->getCode()), [], 'admin'));
        yield TextField::new('code', 'setting.field.code')->setDisabled()->hideOnIndex();
        // значення в списку — текстом (екранування Twig), обрізане; у формі — за типом ключа
        // шаблон textarea кладе сире значення в title — для секретів не годиться, тому текстовий шаблон із готовим рядком
        // вибір — підписом; порожнє значення вибору — чинне типове (Settings::get бере app.settings_defaults)
        $settings = $this->settings;
        yield TextField::new('valueText', 'setting.field.value')->onlyOnIndex()->setSortable(false)
            ->formatValue(static fn ($v, ?Setting $s): string => null !== $s && SettingKeys::TYPE_CHOICE === SettingKeys::type($s->getCode())
                ? $tr->trans((string) SettingKeys::choiceLabel($s->getCode(), $settings->get($s->getCode())), [], 'admin')
                : ('' === trim((string) $v) ? '—' : mb_strimwidth((string) $v, 0, 120, '…'))); // порожнє — у «Стані»
        yield from $this->valueField($pageName);
        // рев'ю PR #5, п. 4: невалідний id мовчки не виводиться на сайті — у списку про це каже бейдж
        yield TextField::new('status', 'setting.field.status')->onlyOnIndex()->setSortable(false)->renderAsHtml()->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static function ($v, ?Setting $s) use ($settings, $tr): string {
                $e = static fn (string $key): string => htmlspecialchars($tr->trans($key, [], 'admin'), \ENT_QUOTES);
                if (null === $s || null === $s->getValue() || '' === trim($s->getValue())) {
                    return '<span class="badge badge-secondary">'.$e(null !== $s && SettingKeys::TYPE_CHOICE === SettingKeys::type($s->getCode()) ? 'setting.status.default' : 'setting.status.not_set').'</span>';
                }
                if (SettingKeys::isSecret($s->getCode())) {
                    $state = $settings->secretState($s->getCode());
                    $class = match ($state) {
                        SecretState::Ready => 'badge-success',
                        SecretState::NoKey => 'badge-warning',
                        default => 'badge-danger',
                    };

                    return \sprintf('<span class="badge %s" title="%s">%s</span>', $class, htmlspecialchars((string) $state->hint()?->trans($tr), \ENT_QUOTES), htmlspecialchars($state->trans($tr), \ENT_QUOTES));
                }

                return SettingKeys::isValidValue($s->getCode(), $s->getValue())
                    ? '<span class="badge badge-success">'.$e('setting.status.output').'</span>'
                    : '<span class="badge badge-danger" title="'.$e('setting.status.invalid_title').'">'.$e('setting.status.invalid').'</span>';
            });
        yield DateTimeField::new('updatedAt', 'setting.field.updated_at')->onlyOnIndex();
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $this->settings->ensureRows();

        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $secret = SettingKeys::isSecret($entityInstance->getCode());
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $before = \is_string($original['value'] ?? null) ? $original['value'] : null;
        if ($secret && $this->clearRequested()) {
            $entityInstance->setValue(null);
        }
        parent::updateEntity($entityManager, $entityInstance);
        $this->settings->invalidate();
        if ($before === $entityInstance->getValue()) {
            return;
        }
        if ($secret) {
            $this->audit('settings.secret_'.(null === $entityInstance->getValue() ? 'cleared' : 'set'), $entityInstance, SecretBox::mask($entityInstance->getValue()));
        } else {
            $this->audit('settings.changed', $entityInstance, null);
        }
    }

    /**
     * «Перевірити налаштування» входу через Google: формат Client ID, стан секрету, перемикач, discovery/JWKS Google.
     * Результат — flash-список (EasyAdmin рендерить flash як HTML, тому тексти екрануються); значень ключів у ньому нема.
     */
    #[AdminRoute(path: '/google-check', name: 'google_check', options: ['methods' => ['POST']])]
    public function googleCheck(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(GoogleSignInPanel::CSRF, (string) $request->request->get('token', ''))) {
            $this->addFlash('danger', t('settings.google.csrf_invalid', [], 'admin'));
        } else {
            $rows = $this->google->check();
            $allOk = array_all($rows, static fn (array $row): bool => $row['ok']);
            $items = array_map(static fn (array $row): string => \sprintf('<li class="%s">%s %s</li>', $row['ok'] ? 'text-success' : 'text-danger', $row['ok'] ? '✓' : '✗', htmlspecialchars($row['text'], \ENT_QUOTES)), $rows);
            $this->addFlash($allOk ? 'success' : 'warning', '<ul class="mb-0 ps-3" id="google-check-result">'.implode('', $items).'</ul>');
        }

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    /**
     * «Перевірити з'єднання» (E15 BYOK): GET /key OpenRouter — токенів не витрачає; результат у flash, ключ не показується.
     * Flash EasyAdmin рендерить як HTML (|raw), тому текст від провайдера (підпис ключа, error.message) екранується.
     */
    #[AdminRoute(path: '/ai-check', name: 'ai_check', options: ['methods' => ['POST']])]
    public function aiCheck(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::AI_CHECK_CSRF, (string) $request->request->get('token', ''))) {
            $this->addFlash('danger', t('ai_key.flash.csrf_invalid', [], 'admin'));
        } elseif (null !== $this->ai->notConfiguredReason()) {
            // причина — за станом ключа мовою адмінки (notConfiguredReason() — українською для логів і винятків)
            $this->addFlash('warning', t('ai_key.reason.'.$this->ai->keyState()->value, [], 'admin'));
        } else {
            $user = $this->security->getUser();
            try {
                $summary = $this->ai->keyInfo($user instanceof User ? $user : null)->trans($this->translator);
                $this->addFlash('success', htmlspecialchars($this->translator->trans('ai_key.flash.check_ok', ['summary' => $summary, 'model' => $this->ai->model()], 'admin'), \ENT_QUOTES));
            } catch (AiException $e) {
                $this->addFlash('danger', htmlspecialchars($this->translator->trans('ai_key.flash.check_failed', ['error' => $e->getMessage()], 'admin'), \ENT_QUOTES));
            }
        }

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    /** Меню «Ключ і модель AI» і банер журналу AI: одразу на редагування рядка ключа OpenRouter (рядок створює ensureRows). */
    #[AdminRoute(path: '/ai-key', name: 'ai_key', options: ['methods' => ['GET']])]
    public function aiKey(): Response
    {
        $this->settings->ensureRows();

        return $this->redirect($this->editUrls([SettingKeys::AI_OPENROUTER_KEY])[SettingKeys::AI_OPENROUTER_KEY]
            ?? $this->adminUrls->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    private function clearRequested(): bool
    {
        $form = $this->getContext()?->getRequest()->request->all('Setting');

        return \is_array($form) && filter_var($form[self::CLEAR_FIELD] ?? false, \FILTER_VALIDATE_BOOL);
    }

    private function audit(string $event, Setting $setting, ?string $mask): void
    {
        $user = $this->security->getUser();
        $ip = $this->getContext()?->getRequest()->getClientIp();
        $this->logger->info($event, [
            'event' => $event,
            'code' => $setting->getCode(),
            'mask' => $mask,
            'user_id' => $user instanceof User ? $user->getId() : null,
            // email — HMAC, як в AdminLoginAudit: журнал порівнюваний, але ПД у ньому нема
            'email_hmac' => null === $user ? null : AuthAudit::emailHmac($user->getUserIdentifier(), $this->kernelSecret),
            'ip' => null === $ip ? null : IpUtils::anonymize($ip),
        ]);
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function valueField(string $pageName): iterable
    {
        if (Crud::PAGE_EDIT !== $pageName) {
            return;
        }
        $setting = $this->getContext()?->getEntity()->getInstance();
        $code = $setting instanceof Setting ? $setting->getCode() : '';
        $label = SettingKeys::labelKey($code);
        $help = t(SettingKeys::helpKey($code), [], 'admin');
        switch (SettingKeys::type($code)) {
            case SettingKeys::TYPE_SECRET:
                yield from $this->secretFields($code, $help);
                break;
            case SettingKeys::TYPE_BOOL:
                yield BooleanField::new('value', $label)->renderAsSwitch()->setHelp($help)
                    ->setFormTypeOptions([
                        'getter' => static fn (Setting $s): bool => \in_array(mb_strtolower(trim((string) $s->getValue())), ['1', 'true', 'yes', 'on'], true),
                        'setter' => static fn (Setting $s, ?bool $v) => $s->setValue($v ? '1' : '0'),
                    ]);
                break;
            case SettingKeys::TYPE_CHOICE:
                // порожній рядок (типове) — у формі вибрано чинне типове значення, після збереження воно запишеться явно
                $current = $this->settings->get($code);
                yield ChoiceField::new('value', $label)->setChoices(SettingKeys::choices($code))->renderExpanded()
                    ->setRequired(true)->setHelp($help)
                    ->setFormTypeOptions(['choice_translation_domain' => 'admin', 'getter' => static fn (Setting $s): ?string => $s->getValue() ?? $current]);
                break;
            case SettingKeys::TYPE_HTML:
                yield TextareaField::new('value', $label)->setHelp(t('setting.help.html_raw', ['help' => $help], 'admin'))->setNumOfRows(12)
                    ->setFormTypeOption('attr', ['spellcheck' => 'false', 'style' => 'font-family: monospace']);
                break;
            case SettingKeys::TYPE_LIST:
                yield TextField::new('value', $label)->setHelp($help)->setRequired(false);
                break;
            default:
                yield TextField::new('value', $label)->setHelp($help)->setRequired(false);
        }
    }

    /**
     * Секрет: поле пароля (завжди порожнє; порожнє при збереженні — лишити чинне), перемикач «видалити» поза мапінгом.
     * Без ключа шифрування — лише пояснення, зберегти нічого не можна.
     *
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    private function secretFields(string $code, TranslatableMessage $help): iterable
    {
        if (!$this->box->isAvailable()) {
            yield TextField::new('value', SettingKeys::labelKey($code))->setDisabled()->setFormTypeOption('mapped', false)
                ->setHelp(t('setting.help.secret_disabled', ['problem' => self::keyProblemText($this->box->keyProblem()), 'help' => $help], 'admin'));

            return;
        }
        $state = $this->settings->secretState($code);
        $box = $this->box;
        yield TextField::new('value', SettingKeys::labelKey($code))->setFormType(PasswordType::class)->setRequired(false)
            ->setHelp(SecretState::Unreadable === $state ? t('setting.help.secret_unreadable', ['hint' => $state->hint(), 'help' => $help], 'admin') : $help)
            ->setFormTypeOptions([
                'always_empty' => true,
                // placeholder перекладає тема форм (домен admin): маска ключа — параметром, щоб не шукати її як ключ перекладу
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'setting.placeholder.secret'],
                'attr_translation_parameters' => ['mask' => SecretBox::mask($this->currentValue()) ?? 'none'],
                'getter' => static fn (Setting $s): string => '',
                'setter' => static function (Setting $s, ?string $v) use ($box): void {
                    $v = trim((string) $v);
                    if ('' !== $v) {
                        $s->setValue($box->encrypt($v));
                    }
                },
            ]);
        yield BooleanField::new(self::CLEAR_FIELD, 'setting.field.clear_secret')->renderAsSwitch(false)
            ->setFormTypeOptions(['mapped' => false, 'required' => false])
            ->setHelp('setting.help.clear_secret');
    }

    /** Порожній ключ шифрування і некоректний (перенос рядка, не 32 байти) — різні підказки, щоб не шукати причину годину. */
    public static function keyProblemText(?string $problem): TranslatableMessage
    {
        return new TranslatableMessage('invalid' === $problem ? 'ai_key.key_problem.invalid' : 'ai_key.key_problem.missing', [], 'admin');
    }

    private function currentValue(): ?string
    {
        $setting = $this->getContext()?->getEntity()->getInstance();

        return $setting instanceof Setting ? $setting->getValue() : null;
    }
}
