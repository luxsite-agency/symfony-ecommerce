<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Enum;

/** Призначення одноразового токена з листа (customer_auth_token.purpose). login_link Symfony зберігає сам, без таблиці. */
enum AuthTokenPurpose: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';
    /** зміна email: токен іде на НОВУ адресу (targetEmail), чинний, поки стара адреса не змінилась */
    case ChangeEmail = 'change_email';
    /** на СТАРУ адресу після зміни email: повернути її й заблокувати вхід (7 днів); не прив'язаний до поточного email */
    case RevertEmail = 'revert_email';
    /** одноразове посилання на файл експорту даних (24 год) */
    case Export = 'export';
}
