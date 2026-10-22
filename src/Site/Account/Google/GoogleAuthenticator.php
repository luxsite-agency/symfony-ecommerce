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
use App\Core\Customer\Google\GoogleSignInResult;
use App\Core\Customer\Google\OidcException;
use App\Site\Account\AccountMails;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Вхід через Google (firewall site, custom_authenticators): перехоплює callback `/account/google/callback` у режимі
 * login (режим link обробляє GoogleSignInController::callback без повторного входу). Порядок: ліміт з IP → прохід із сесії
 * за state → помилка від Google (?error=) → обмін коду з PKCE і перевірка id_token (GoogleOidcClient) → правила магазину
 * (GoogleAccounts::signIn) → паспорт покупця. user_checker (CustomerChecker) і LoginSuccessEvent (журнал, кошик, GA4)
 * працюють як для пароля. Невдача: журнал `google_failed` з кодом причини, флеш покупцю, назад на вхід.
 * Прохід у режимі login, що дожив до callback уже залогіненого покупця (вкладка 2 увійшла паролем), не перехоплюється:
 * сесію мовчки не підміняємо (рев'ю #74, L5) — його забирає GoogleSignInController::callback і веде в кабінет.
 * Прив'язка до наявного покупця при вході (GoogleSignInResult::linkedNow) — лист `google_linked` (M1).
 */
final class GoogleAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface
{
    public const string PASSPORT_ATTRIBUTE = 'google';
    private const string FLOW_ATTRIBUTE = '_google_flow';
    private const string RESULT_ATTRIBUTE = '_google_result';

    public function __construct(
        private readonly GoogleSignInConfig $config,
        private readonly GoogleFlow $flow,
        private readonly GoogleOidcClient $oidc,
        private readonly GoogleAccounts $accounts,
        private readonly AuthAudit $audit,
        private readonly UrlGeneratorInterface $urls,
        private readonly AccountMails $mails,
        private readonly Security $security,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->config->isEnabled()
            // _canonical_route у атрибутах нема: матчер кладе канонічне ім'я в _route (gotchas 2026-10-08)
            && 'site_account_google_callback' === $request->attributes->get('_route')
            && GoogleFlow::MODE_LINK !== $this->flow->pendingMode($request)
            // ContextListener уже відновив токен сесії: залогінений покупець через callback не перелогінюється
            && !$this->security->getUser() instanceof Customer;
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->flow->allow($request)) {
            throw new TooManyLoginAttemptsAuthenticationException();
        }
        $flow = $this->flow->take($request, $request->query->get('state'));
        if (null === $flow) {
            throw new CustomUserMessageAuthenticationException('site.account.google.error.state');
        }
        $request->attributes->set(self::FLOW_ATTRIBUTE, $flow);
        $request->attributes->set(self::RESULT_ATTRIBUTE, null);
        $error = $request->query->get('error');
        if (\is_string($error) && '' !== $error) {
            $reason = OidcException::providerReason($error);
            throw new CustomUserMessageAuthenticationException('cancelled' === $reason ? 'site.account.google.error.cancelled' : 'site.account.google.error.provider', ['reason' => $reason]);
        }
        $code = $request->query->get('code');
        if (!\is_string($code) || '' === $code) {
            throw new CustomUserMessageAuthenticationException('site.account.google.error.state', ['reason' => 'code_missing']);
        }
        try {
            $identity = $this->oidc->exchange($code, $this->flow->redirectUri(), $flow['verifier'], $flow['nonce']);
            $result = $this->accounts->signIn($identity, $flow['locale']);
        } catch (OidcException $e) {
            throw new CustomUserMessageAuthenticationException('site.account.google.error.provider', ['reason' => $e->reason]);
        } catch (GoogleSignInRefused $e) {
            throw new CustomUserMessageAuthenticationException($e->messageKey(), ['reason' => $e->reason]);
        }
        $customer = $result->customer;
        $request->attributes->set(self::RESULT_ATTRIBUTE, $result);
        $passport = new SelfValidatingPassport(new UserBadge($customer->getEmail(), static fn () => $customer));
        $passport->setAttribute(self::PASSPORT_ATTRIBUTE, $result);

        return $passport;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        /** @var array{next: ?string, locale: string} $flow */
        $flow = $request->attributes->get(self::FLOW_ATTRIBUTE);
        $result = $request->attributes->get(self::RESULT_ATTRIBUTE);
        if ($result instanceof GoogleSignInResult && $result->linkedNow) {
            // наявний покупець отримав новий спосіб входу: лист із адресою Google (рев'ю #74, M1) — після flush у signIn()
            $this->mails->googleLinked($request, $result->customer);
        }

        return new RedirectResponse($this->flow->target($flow['next'], $flow['locale']));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $data = $exception->getMessageData();
        $reason = \is_string($data['reason'] ?? null) ? $data['reason'] : $exception->getMessageKey();
        $this->audit->log($exception instanceof TooManyLoginAttemptsAuthenticationException ? 'google_rate_limited' : 'google_failed', null, null, ['reason' => $reason]);
        /** @var array{locale: string}|null $flow */
        $flow = $request->attributes->get(self::FLOW_ATTRIBUTE);
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', match (true) {
                $exception instanceof TooManyLoginAttemptsAuthenticationException => 'site.account.error.too_many',
                $exception instanceof CustomUserMessageAuthenticationException => $exception->getMessageKey(),
                default => 'site.account.google.error.failed',
            });
        }

        return new RedirectResponse($this->urls->generate('site_account_login', ['_locale' => $flow['locale'] ?? $request->getLocale()]));
    }

    public function isInteractive(): bool
    {
        return true;
    }
}
