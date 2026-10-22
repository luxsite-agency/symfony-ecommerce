<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** Вхід/вихід адмінки на хості admin_host; сторінка — стандартна EasyAdmin з рядком ліцензії внизу (admin/security/login.html.twig). */
#[Route(host: '%admin_host%')]
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils, #[CurrentUser] ?UserInterface $user): Response
    {
        if (null !== $user) {
            return $this->redirectToRoute('admin');
        }

        return $this->render('admin/security/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
            'page_title' => 'Symfony ecommerce LuxSite',
            'csrf_token_intention' => 'authenticate',
            'target_path' => $this->generateUrl('admin'),
            // підписи — ключі домену admin мовою адмінки (AdminLocaleListener: типова — мова магазину, ?_locale — вибір)
            'translation_domain' => 'admin',
            'username_label' => 'login.email',
            'password_label' => 'login.password',
            'sign_in_label' => 'login.sign_in',
        ]);
    }

    #[Route('/logout', name: 'admin_logout')]
    public function logout(): never
    {
        throw new LogicException('Вихід обробляє firewall admin (security.yaml).');
    }
}
