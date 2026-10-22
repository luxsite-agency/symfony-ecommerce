<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b;

use App\Core\B2b\Entity\Company;
use App\Core\B2b\Entity\CompanyMember;
use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\B2b\Repository\CompanyMemberRepository;
use App\Core\B2b\Repository\WholesaleApplicationRepository;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Security\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Заявки оптовиків (E13.1, PR-5): подання з кабінету, схвалення й відхилення з адмінки. Усі зміни групи покупця
 * йдуть лише звідси (консиліум 07.10, П2: «групу ставить лише схвалення заявки») і пишуться в security_audit
 * (хто, кому, яка група), без реквізитів і ПД (П5). Листи покупцю надсилає Site-шар (WholesaleMails), бо URL
 * будується з хоста запиту; тут лише домен і БД.
 */
final class WholesaleApplications
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WholesaleApplicationRepository $applications,
        private readonly CompanyMemberRepository $members,
        private readonly AuthAudit $audit,
        private readonly LockFactory $locks,
    ) {
    }

    /** Чи може покупець подати заявку: email підтверджено, нема активної заявки й компанії. */
    public function canApply(Customer $customer): bool
    {
        return $customer->isActive() && $customer->isEmailVerified()
            && null === $this->applications->findPending($customer)
            && null === $this->members->findByCustomer($customer);
    }

    /**
     * Подання: одна активна заявка на покупця. Повторний сабміт (друга вкладка, подвійний клік) ловить унікальний
     * індекс (customer_id, pending_key), а не лише перевірка вище.
     *
     * @throws WholesaleApplicationException ключ перекладу site.account.wholesale.error.*
     */
    public function submit(Customer $customer, string $companyName, string $taxId, bool $vatPayer, string $contactName, ?string $contactPhone, ?string $note): WholesaleApplication
    {
        if (!$customer->isEmailVerified()) {
            throw new WholesaleApplicationException('site.account.wholesale.error.unverified');
        }
        if (null !== $this->members->findByCustomer($customer)) {
            throw new WholesaleApplicationException('site.account.wholesale.error.already_member');
        }
        if (null !== $this->applications->findPending($customer)) {
            throw new WholesaleApplicationException('site.account.wholesale.error.pending');
        }
        $application = new WholesaleApplication($customer, $companyName, $taxId, $vatPayer, $contactName, $contactPhone, $note);
        $this->em->persist($application);
        try {
            $this->em->wrapInTransaction(function () use ($application): void {
                $this->em->flush();
                $this->audit->log('wholesale_applied', $application->getCustomer()->getEmail(), $application->getCustomer()->getId(), ['application_id' => $application->getId()]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new WholesaleApplicationException('site.account.wholesale.error.pending');
        }

        return $application;
    }

    /**
     * Схвалення: компанія зі снепшоту заявки (наявна компанія з тим самим кодом без членів оновлюється з нього),
     * покупець стає її власником і отримує групу. Під блокуванням на покупця: два менеджери не схвалять одну
     * заявку двічі.
     *
     * @throws WholesaleApplicationException текст для адмінки
     */
    public function approve(WholesaleApplication $application, User $reviewer, CustomerGroup $group, ?string $comment): void
    {
        $customer = $application->getCustomer();
        $lock = $this->locks->createLock('wholesale-customer-'.$customer->getId(), 10);
        if (!$lock->acquire()) {
            throw new WholesaleApplicationException('Заявку зараз обробляє інший менеджер, спробуйте за хвилину', 'error.wholesale_application.locked');
        }
        try {
            $this->em->refresh($application);
            if (!$application->isPending()) {
                throw new WholesaleApplicationException('Заявку вже розглянуто', 'error.wholesale_application.already_decided');
            }
            if (null !== $this->members->findByCustomer($customer)) {
                throw new WholesaleApplicationException('Покупець уже належить до компанії', 'error.wholesale_application.already_member');
            }
            $company = $this->em->getRepository(Company::class)->findOneBy(['taxId' => $application->getTaxId()]);
            if (null !== $company && !$company->getMembers()->isEmpty()) {
                throw new WholesaleApplicationException(\sprintf('Компанія з кодом %s уже є і має власника', $application->getTaxId()), 'error.wholesale_application.company_has_owner', ['tax_id' => $application->getTaxId()]);
            }
            if (null === $company) {
                $company = new Company($application->getCompanyName(), $application->getTaxId(), $application->isVatPayer(), $application->getContactName(), $application->getContactPhone());
                $this->em->persist($company);
            } else {
                // компанія без членів (покупця видалено): реквізити зі свіжої заявки
                $company->rename($application->getCompanyName());
                $company->setVatPayer($application->isVatPayer());
                $company->setContact($application->getContactName(), $application->getContactPhone());
            }
            $this->em->persist(new CompanyMember($company, $customer));
            $previous = $customer->getCustomerGroup();
            $customer->setCustomerGroup($group);
            $application->approve($reviewer, $group, $company, $comment);
            try {
                $this->em->wrapInTransaction(function () use ($application, $customer, $group, $previous, $reviewer): void {
                    $this->flushApproval($application, $customer, $group, $previous, $reviewer);
                });
            } catch (UniqueConstraintViolationException) {
                // гонка з іншим схваленням чи заявкою на той самий код поза блокуванням (інший покупець)
                throw new WholesaleApplicationException('Компанію з таким кодом щойно створено іншою заявкою, оновіть сторінку', 'error.wholesale_application.company_race');
            }
        } finally {
            $lock->release();
        }
    }

    private function flushApproval(WholesaleApplication $application, Customer $customer, CustomerGroup $group, ?CustomerGroup $previous, User $reviewer): void
    {
        $this->em->flush();
        $this->audit->log('wholesale_approved', $customer->getEmail(), $customer->getId(), [
            'application_id' => $application->getId(),
            'reviewer_id' => $reviewer->getId(),
        ]);
        $this->audit->log('group_changed', $customer->getEmail(), $customer->getId(), [
            'from' => $previous?->getCode(),
            'to' => $group->getCode(),
            'reviewer_id' => $reviewer->getId(),
            'application_id' => $application->getId(),
        ]);
    }

    /** @throws WholesaleApplicationException */
    public function reject(WholesaleApplication $application, User $reviewer, string $reason): void
    {
        $customer = $application->getCustomer();
        $lock = $this->locks->createLock('wholesale-customer-'.$customer->getId(), 10);
        if (!$lock->acquire()) {
            throw new WholesaleApplicationException('Заявку зараз обробляє інший менеджер, спробуйте за хвилину', 'error.wholesale_application.locked');
        }
        try {
            $this->em->refresh($application);
            if (!$application->isPending()) {
                throw new WholesaleApplicationException('Заявку вже розглянуто', 'error.wholesale_application.already_decided');
            }
            $application->reject($reviewer, $reason);
            $this->em->flush();
            $this->audit->log('wholesale_rejected', $customer->getEmail(), $customer->getId(), [
                'application_id' => $application->getId(),
                'reviewer_id' => $reviewer->getId(),
            ]);
        } finally {
            $lock->release();
        }
    }
}
