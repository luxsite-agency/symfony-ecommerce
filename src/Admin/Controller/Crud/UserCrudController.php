<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Користувачі адмінки — лише ROLE_ADMIN (ADR D10). Пароль хешується в POST_SUBMIT форми. Деактивація відкликає всі
 * API-токени користувача (E15 PR-2): ActiveUserChecker і так не пустить, але в списку токенів має бути видно, що їх загашено.
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AdminApiTokens $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('user.label.singular')
            ->setEntityLabelInPlural('user.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'user.title.new')
            ->setEntityPermission('ROLE_ADMIN')
            ->setSearchFields(['email', 'displayName'])
            ->setDefaultSort(['email' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions, 'user.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield EmailField::new('email', 'user.field.email');
        yield TextField::new('displayName', 'user.field.display_name');
        // enum без setChoices: варіанти — з enumType Doctrine, підпис — UserRole::trans(); бейджі — за назвою case
        yield ChoiceField::new('role', 'user.field.role')
            ->setFormTypeOption('choice_value', static fn (?UserRole $r): ?string => $r?->value)
            ->renderAsBadges([UserRole::Admin->name => 'danger', UserRole::Manager->name => 'info']);
        yield BooleanField::new('active', 'user.field.active');
        yield TextField::new('plainPassword', Crud::PAGE_NEW === $pageName ? 'user.field.password' : 'user.field.new_password')
            ->onlyOnForms()
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'required' => Crud::PAGE_NEW === $pageName,
                'help' => Crud::PAGE_NEW === $pageName ? 'user.help.password' : 'user.help.new_password',
                'constraints' => Crud::PAGE_NEW === $pageName
                    ? [new NotBlank(message: 'security.user.password.not_blank'), new Length(min: 8, minMessage: 'security.user.password.min_length')]
                    : [new Length(min: 8, minMessage: 'security.user.password.min_length')],
            ]);
        yield DateTimeField::new('createdAt', 'user.field.created_at')->onlyOnIndex();
    }

    /** @param User $entityInstance */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $by = $this->getUser();
        if (!$entityInstance->isActive() && $by instanceof User) {
            $this->tokens->revokeAllOf($entityInstance, $by);
        }
    }

    public function createEntity(string $entityFqcn): User
    {
        return new User('new@example.invalid', $this->translator->trans('user.default_display_name', [], 'admin'));
    }

    /** @return FormBuilderInterface<mixed> */
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withPasswordHashing(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    /** @return FormBuilderInterface<mixed> */
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withPasswordHashing(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     *
     * @return FormBuilderInterface<mixed>
     */
    private function withPasswordHashing(FormBuilderInterface $builder): FormBuilderInterface
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $user = $event->getData();
            $plain = $form->has('plainPassword') ? $form->get('plainPassword')->getData() : null;
            if ($user instanceof User && \is_string($plain) && '' !== $plain) {
                $user->setPasswordHash($this->hasher->hashPassword($user, $plain));
            }
        });

        return $builder;
    }
}
