<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Seo\Entity\Redirect;
use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\Enum\RedirectKind;
use App\Core\Seo\RedirectCsv;
use App\Core\Seo\RedirectManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Symfony\Component\Translation\t;

use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Менеджер редиректів (епік E3): ручні й авто-301 правила, пошук, CSV-експорт та імпорт зі звітом. Збереження зливає
 * ланцюги (RedirectManager): ціль одразу кінцева, правила, що вели на це джерело, переписуються на ту саму ціль.
 *
 * @extends AbstractCrudController<Redirect>
 */
final class RedirectCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public function __construct(private readonly RedirectManager $redirects, private readonly RedirectCsv $csv)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Redirect::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('redirect.label.singular')
            ->setEntityLabelInPlural('redirect.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'redirect.title.new')
            ->setSearchFields(['source', 'target'])
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'redirect.help.index');
    }

    public function configureActions(Actions $actions): Actions
    {
        $export = Action::new('export', 'redirect.action.export', 'fa fa-download')->linkToCrudAction('export')->createAsGlobalAction();
        $import = Action::new('import', 'redirect.action.import', 'fa fa-upload')->linkToCrudAction('import')->createAsGlobalAction();

        return $this->configureFormActions($actions, 'redirect.action.add')
            ->add(Crud::PAGE_INDEX, $import)
            ->add(Crud::PAGE_INDEX, $export);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('code', 'redirect.field.code')->setChoices(array_combine(array_map(static fn (RedirectCode $c): string => 'enum.redirect_code.'.$c->value, RedirectCode::cases()), RedirectCode::cases()))->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'))
            ->add(ChoiceFilter::new('kind', 'redirect.field.kind')->setChoices(array_combine(array_map(static fn (RedirectKind $k): string => 'enum.redirect_kind.'.$k->value, RedirectKind::cases()), RedirectKind::cases()))->setFormTypeOption('value_type_options.choice_translation_domain', 'admin'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield TextField::new('source', 'redirect.field.source')
            ->setHelp('redirect.help.source')
            ->setFormTypeOption('setter', static fn (Redirect $r, ?string $v) => $r->setSource($v))
            ->setFormTypeOption('getter', static fn (Redirect $r): string => '' === $r->getSource() ? (string) $r->getRawSource() : $r->getSource());
        // enum-поля без setChoices: варіанти — з enumType Doctrine, підпис — trans() мовою адмінки; бейджі — за назвою case
        yield ChoiceField::new('code', 'redirect.field.code')
            ->setFormTypeOption('choice_value', static fn (?RedirectCode $c): ?string => null === $c ? null : (string) $c->value)
            ->renderAsBadges([RedirectCode::Permanent->name => 'success', RedirectCode::Temporary->name => 'info', RedirectCode::NotFound->name => 'secondary', RedirectCode::Gone->name => 'danger']);
        yield TextField::new('target', 'redirect.field.target')
            ->setRequired(false)
            ->setHelp('redirect.help.target')
            ->setFormTypeOption('setter', static fn (Redirect $r, ?string $v) => $r->setTarget($v))
            ->setFormTypeOption('getter', static fn (Redirect $r): ?string => $r->getTarget() ?? $r->getRawTarget());
        yield ChoiceField::new('kind', 'redirect.field.kind')
            ->renderAsBadges([RedirectKind::Manual->name => 'secondary', RedirectKind::Auto->name => 'primary'])->hideOnForm();
        yield IntegerField::new('hits', 'redirect.field.hits')->hideOnForm();
        yield DateTimeField::new('lastHitAt', 'redirect.field.last_hit_at')->hideOnForm();
        yield DateTimeField::new('createdAt', 'redirect.field.created_at')->onlyOnDetail();
    }

    public function createEntity(string $entityFqcn): Redirect
    {
        // «Створити редирект» з журналу 404 відкриває форму з адресою (?source=…)
        return new Redirect((string) $this->getContext()?->getRequest()->query->get('source', ''));
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->redirects->locked(function () use ($entityManager, $entityInstance): void {
            $this->prepare($entityInstance);
            parent::persistEntity($entityManager, $entityInstance);
            $this->redirects->merge($entityInstance->getSource(), $entityInstance->getCode(), $entityInstance->getTarget());
        });
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->redirects->locked(function () use ($entityManager, $entityInstance): void {
            $this->prepare($entityInstance);
            $entityInstance->setKind(RedirectKind::Manual); // правило, змінене людиною, — ручне
            parent::updateEntity($entityManager, $entityInstance);
            $this->redirects->merge($entityInstance->getSource(), $entityInstance->getCode(), $entityInstance->getTarget());
        });
    }

    #[AdminRoute(path: '/export', name: 'export')]
    public function export(): Response
    {
        $response = new StreamedResponse(function (): void {
            $out = fopen('php://output', 'w');
            if (false !== $out) {
                $this->csv->export($out);
                fclose($out);
            }
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'redirects-'.date('Y-m-d').'.csv'));

        return $response;
    }

    #[AdminRoute(path: '/import', name: 'import', options: ['methods' => ['GET', 'POST']])]
    public function import(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('file', FileType::class, [
                'label' => 'redirect.import.file',
                'help' => 'redirect.import.file_help',
                // рядком: число ICU відформатувало б із пробілом-розділювачем тисяч
                'help_translation_parameters' => ['max' => (string) RedirectCsv::WEB_MAX_ROWS],
                'translation_domain' => 'admin',
                'constraints' => [new NotNull(message: 'seo.redirect_import.file.not_null'), new File(maxSize: '1M', extensions: ['csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], 'txt' => ['text/plain', 'text/csv']])],
            ])
            ->getForm();
        $form->handleRequest($request);
        $report = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            try {
                $report = $file instanceof UploadedFile ? $this->csv->import($file->getPathname(), RedirectCsv::WEB_MAX_ROWS, $this->isGranted('ROLE_ADMIN')) : null;
            } catch (RuntimeException) {
                $this->addFlash('danger', t('redirect.flash.file_unreadable', [], 'admin')); // єдиний RuntimeException імпорту — файл не прочитався
            }
        }

        return $this->render('admin/seo/redirect_import.html.twig', ['form' => $form, 'report' => $report]);
    }

    /**
     * Під блокуванням: перевірка ще раз (між валідацією форми й записом інша форма могла зберегти зустрічне правило) і
     * кінцева ціль ланцюга. Відмова тут — лише в гонці двох форм.
     */
    private function prepare(Redirect $redirect): void
    {
        $target = null === $redirect->getTarget() ? null : $this->redirects->localize($redirect->getTarget());
        // problem() — InvalidRedirect з ключем перекладу: форма покаже його мовою адмінки
        if (null !== ($problem = $this->redirects->problem($redirect->getSource(), $redirect->getCode(), $target))) {
            throw $problem;
        }
        if (!$redirect->getCode()->isRedirect()) {
            $redirect->dropTarget();
        } elseif (null !== $target) {
            $redirect->retarget($this->redirects->resolve($redirect->getSource(), $target));
        }
    }
}
