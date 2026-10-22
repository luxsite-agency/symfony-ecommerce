<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Customer\Account\AccountDeletion;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\AuthTokenPurpose;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Customer\Export\ExportFiles;
use App\Core\Customer\Message\ExportCustomerData;
use App\Site\Account\AccountMails;
use App\Site\Account\Form\ReauthType;
use App\Site\Account\Reauth;
use App\Site\Account\ReauthResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Персональні дані в кабінеті (E12 PR-6, консиліум П5): стан згод, експорт своїх даних (пароль → файл у фоні →
 * лист з одноразовим посиланням на 24 год через auth_mail; забрати може лише цей покупець, файл після віддачі
 * видаляється) і видалення акаунта (пароль → AccountDeletion → вихід → лист-підтвердження на колишню адресу).
 */
final class PrivacyController extends AbstractController
{
    public function __construct(
        private readonly Consents $consents,
        private readonly AuthTokens $tokens,
        private readonly AccountMails $mails,
        private readonly AuthAudit $audit,
        private readonly Reauth $reauth,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ExportFiles $files,
        private readonly AccountDeletion $deletion,
        private readonly Security $security,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function index(#[CurrentUser] Customer $customer): Response
    {
        $consents = [];
        foreach (ConsentPurpose::cases() as $purpose) {
            $consents[$purpose->value] = $this->consents->isGranted($customer, $purpose);
        }

        return $this->render('site/account/privacy.html.twig', ['customer' => $customer, 'consents' => $consents, 'policyVersion' => $this->consents->policyVersion()]);
    }

    #[IsGranted(Customer::ROLE)]
    public function export(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $form = $this->createForm(ReauthType::class, ['current' => '']);
        $form->handleRequest($request);
        $limited = false;
        $reauth = null;
        if ($form->isSubmitted() && $form->isValid() && ReauthResult::Ok === ($reauth = $this->reauth->check($form, $customer))) {
            if (!$this->mails->allow($request, $customer->getEmail())) {
                $limited = true;
            } else {
                $token = $this->tokens->issue($customer, AuthTokenPurpose::Export);
                $this->em->flush();
                $this->bus->dispatch(new ExportCustomerData((int) $customer->getId(), AuthTokens::hashOf($token), $this->mails->exportUrl($request, $token)));
                $this->audit->log('export_requested', $customer->getEmail(), $customer->getId());
                $this->addFlash('success', 'site.account.privacy.flash.export_requested');

                return $this->redirectToRoute('site_account_privacy');
            }
        }

        return $this->render('site/account/export.html.twig', ['form' => $form, 'limited' => $limited], new Response(status: $limited ? 429 : Reauth::status($form, $reauth)));
    }

    /**
     * Одноразове посилання з листа: токен цього покупця. GET лише показує кнопку (превʼю в поштовику чи prefetch браузера
     * не зʼїсть одноразове посилання; рев'ю #60 v2, L1), POST віддає файл і видаляє його. Файл ще не готовий — «готується».
     */
    #[IsGranted(Customer::ROLE)]
    public function download(Request $request, string $token, #[CurrentUser] Customer $customer): Response
    {
        $found = $this->tokens->find($token, AuthTokenPurpose::Export);
        if (null === $found || $found->getCustomer()->getId() !== $customer->getId()) {
            // 410, не 404: сторінки з листів так відповідають, і 404 на шляху з великими літерами перехопив би редирект у нижній регістр
            return $this->render('site/account/export_pending.html.twig', ['gone' => true], new Response(status: 410));
        }
        $name = AuthTokens::hashOf($token);
        if (!$this->files->exists($name)) {
            return $this->render('site/account/export_pending.html.twig', ['gone' => false, 'ready' => false], new Response(status: 202));
        }
        if (!$request->isMethod('POST')) {
            return $this->render('site/account/export_pending.html.twig', ['gone' => false, 'ready' => true]);
        }
        if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недійсний CSRF-токен');
        }
        // спершу токен (одноразовість у транзакції з блокуванням), потім файл: два паралельні запити не віддадуть його двічі (рев'ю #60, п. 5)
        if (null === $this->tokens->consume($token, AuthTokenPurpose::Export, static function (): void {})) {
            return $this->render('site/account/export_pending.html.twig', ['gone' => true], new Response(status: 410));
        }
        $json = $this->files->take($name);
        if (null === $json) {
            return $this->render('site/account/export_pending.html.twig', ['gone' => true], new Response(status: 410));
        }
        $this->audit->log('export_downloaded', $customer->getEmail(), $customer->getId());
        $response = new Response($json, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'my-data-'.date('Y-m-d').'.json'));

        return $response;
    }

    #[IsGranted(Customer::ROLE)]
    public function delete(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $form = $this->createForm(ReauthType::class, ['current' => '']);
        $form->handleRequest($request);
        $reauth = null;
        if ($form->isSubmitted() && $form->isValid() && ReauthResult::Ok === ($reauth = $this->reauth->check($form, $customer))) {
            [$email, $name, $locale] = [$customer->getEmail(), $customer->getName(), $customer->getLocale()];
            $this->deletion->delete($customer);
            $this->mails->deleted($request, $email, $name, $locale);
            $page = $this->render('site/account/deleted.html.twig');
            // вихід: сесія недійсна, cookie сесії й remember_me знімаються цією ж відповіддю
            foreach ($this->security->logout(false)?->headers->getCookies() ?? [] as $cookie) {
                $page->headers->setCookie($cookie);
            }

            return $page;
        }

        return $this->render('site/account/delete.html.twig', ['form' => $form], new Response(status: Reauth::status($form, $reauth)));
    }
}
