<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Google;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Google\GoogleAccounts;
use App\Core\Customer\Google\GoogleOidcClient;
use App\Core\Customer\Google\GoogleSignInConfig;
use App\Core\Customer\Google\GoogleSignInRefused;
use App\Core\Customer\Google\OidcException;
use App\Site\Account\AccountMails;
use App\Site\Account\Form\ReauthType;
use App\Site\Account\Reauth;
use App\Site\Account\ReauthResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Сторінки потоку Google (маршрути site_account_google_*, SiteRouteLoader). Без ключів (GoogleSignInConfig) усе — 404.
 * start — GET з ?next=<відносний шлях>: кладе прохід у сесію і веде на Google (вхід або реєстрація — одна кнопка).
 * link — у кабінеті: поточний пароль (Reauth, як для зміни пароля: сесія без пароля не має прив'язувати чужий Google),
 * далі той самий прохід у режимі link; callback у цьому режимі обробляється тут (автентифікатор його пропускає) —
 * без повторного входу. unlink — POST з CSRF `account`, лише коли є пароль (GoogleAccounts::canUnlink).
 * Підключення і відключення — лист покупцю з адресою акаунта Google (рев'ю #74, M1: прив'язка не має бути невидимим
 * «запасним входом»).
 */
final class GoogleSignInController extends AbstractController
{
    public function __construct(
        private readonly GoogleSignInConfig $config,
        private readonly GoogleFlow $flow,
        private readonly GoogleOidcClient $oidc,
        private readonly GoogleAccounts $accounts,
        private readonly Reauth $reauth,
        private readonly AuthAudit $audit,
        private readonly AccountMails $mails,
    ) {
    }

    public function start(Request $request): Response
    {
        $this->ensureEnabled();
        if ($request->hasPreviousSession() && null !== $this->getUser()) {
            return $this->redirectToRoute('site_account');
        }
        if (!$this->flow->allow($request)) {
            $this->audit->log('google_rate_limited');
            $this->addFlash('error', 'site.account.error.too_many');

            return $this->redirectToRoute('site_account_login', [], Response::HTTP_SEE_OTHER);
        }
        $next = ReturnPath::sanitize($request->query->get('next'));
        $flow = $this->flow->begin($request->getSession(), GoogleFlow::MODE_LOGIN, $next, $request->getLocale());
        $this->audit->log('google_started');

        return $this->redirect($this->oidc->authorizationUrl($this->flow->redirectUri(), $flow['state'], $flow['nonce'], $flow['verifier']));
    }

    /** Режим login перехоплює GoogleAuthenticator; сюди доходить лише режим link (або прохід без сесії → на вхід). */
    public function callback(Request $request): Response
    {
        $this->ensureEnabled();
        $customer = $this->getUser();
        if (!$customer instanceof Customer) {
            // прохід загубився (нова сесія, інший браузер): нехай починає знову зі сторінки входу
            $this->flow->take($request, $request->query->get('state'));
            $this->addFlash('error', 'site.account.google.error.state');

            return $this->redirectToRoute('site_account_login');
        }
        if (!$this->flow->allow($request)) {
            $this->audit->log('google_rate_limited', $customer->getEmail(), $customer->getId());
            $this->addFlash('error', 'site.account.error.too_many');

            return $this->redirectToRoute('site_account_profile');
        }
        $flow = $this->flow->take($request, $request->query->get('state'));
        if (null !== $flow && GoogleFlow::MODE_LOGIN === $flow['mode']) {
            // прохід входу, почату анонімно, дожив до callback уже залогіненого (інша вкладка): сесію не підміняємо (рев'ю #74, L5)
            $this->audit->log('google_ignored', $customer->getEmail(), $customer->getId(), ['reason' => 'already_logged_in']);

            return $this->redirect($this->flow->target($flow['next'], $flow['locale']));
        }
        if (null === $flow || GoogleFlow::MODE_LINK !== $flow['mode'] || $flow['customer'] !== $customer->getId()) {
            $this->audit->log('google_link_failed', $customer->getEmail(), $customer->getId(), ['reason' => 'state']);
            $this->addFlash('error', 'site.account.google.error.state');

            return $this->redirectToRoute('site_account_profile');
        }
        $error = $request->query->get('error');
        $code = $request->query->get('code');
        try {
            if (\is_string($error) && '' !== $error) {
                throw new OidcException(OidcException::providerReason($error));
            }
            if (!\is_string($code) || '' === $code) {
                throw new OidcException('code_missing');
            }
            $identity = $this->oidc->exchange($code, $this->flow->redirectUri(), $flow['verifier'], $flow['nonce']);
            $this->accounts->link($customer, $identity);
        } catch (OidcException $e) {
            $this->audit->log('google_link_failed', $customer->getEmail(), $customer->getId(), ['reason' => $e->reason]);
            $this->addFlash('error', 'cancelled' === $e->reason ? 'site.account.google.error.cancelled' : 'site.account.google.error.provider');

            return $this->redirectToRoute('site_account_profile');
        } catch (GoogleSignInRefused $e) {
            $this->audit->log('google_link_failed', $customer->getEmail(), $customer->getId(), ['reason' => $e->reason]);
            $this->addFlash('error', $e->messageKey());

            return $this->redirectToRoute('site_account_profile');
        }
        $this->audit->log('google_linked', $customer->getEmail(), $customer->getId());
        $this->mails->googleLinked($request, $customer);
        $this->addFlash('success', 'site.account.google.flash.linked');

        return $this->redirectToRoute('site_account_profile');
    }

    #[IsGranted(Customer::ROLE)]
    public function link(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $this->ensureEnabled();
        if ($customer->isGoogleLinked()) {
            return $this->redirectToRoute('site_account_profile');
        }
        $form = $this->createForm(ReauthType::class, ['current' => '']);
        $form->handleRequest($request);
        $reauth = null;
        if ($form->isSubmitted() && $form->isValid() && ReauthResult::Ok === ($reauth = $this->reauth->check($form, $customer))) {
            if (!$this->flow->allow($request)) {
                $this->audit->log('google_rate_limited', $customer->getEmail(), $customer->getId());
                $this->addFlash('error', 'site.account.error.too_many');

                return $this->redirectToRoute('site_account_profile');
            }
            $flow = $this->flow->begin($request->getSession(), GoogleFlow::MODE_LINK, null, $request->getLocale(), $customer->getId());
            $this->audit->log('google_link_started', $customer->getEmail(), $customer->getId());

            return $this->redirect($this->oidc->authorizationUrl($this->flow->redirectUri(), $flow['state'], $flow['nonce'], $flow['verifier'], $customer->getEmail()));
        }

        return $this->render('site/account/google_link.html.twig', ['form' => $form], new Response(status: Reauth::status($form, $reauth)));
    }

    #[IsGranted(Customer::ROLE)]
    public function unlink(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $this->ensureEnabled();
        if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недійсний CSRF-токен');
        }
        if (!$this->accounts->canUnlink($customer)) {
            $this->addFlash('error', 'site.account.google.error.no_other_method');

            return $this->redirectToRoute('site_account_profile');
        }
        $googleEmail = (string) $customer->getGoogleEmail();
        $this->accounts->unlink($customer);
        $this->audit->log('google_unlinked', $customer->getEmail(), $customer->getId());
        $this->mails->googleUnlinked($request, $customer, $googleEmail);
        $this->addFlash('success', 'site.account.google.flash.unlinked');

        return $this->redirectToRoute('site_account_profile');
    }

    private function ensureEnabled(): void
    {
        if (!$this->config->isEnabled()) {
            throw $this->createNotFoundException('Вхід через Google не налаштовано');
        }
    }
}
