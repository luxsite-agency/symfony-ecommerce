<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Security\Command;

use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Перший адміністратор магазину створюється з консолі: без нього в адмінку не зайти. */
#[AsCommand(name: 'app:user:create', description: 'Створити користувача адмінки (ROLE_ADMIN або ROLE_MANAGER)')]
final class CreateUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Електронна пошта (логін)')] string $email,
        #[Argument(description: 'Ім\'я для відображення')] string $name,
        #[Option(description: 'Пароль; якщо не задано — запитається приховано')] ?string $password = null,
        #[Option(description: 'ROLE_ADMIN або ROLE_MANAGER')] string $role = 'ROLE_MANAGER',
    ): int {
        $userRole = UserRole::tryFrom($role);
        if (null === $userRole) {
            $io->error(\sprintf('Невідома роль "%s". Доступні: %s', $role, implode(', ', array_map(static fn (UserRole $r): string => $r->value, UserRole::cases()))));

            return Command::INVALID;
        }

        $password ??= $io->askHidden('Пароль');
        if (!\is_string($password) || mb_strlen($password) < 8) {
            $io->error('Пароль має бути не коротшим за 8 символів');

            return Command::INVALID;
        }

        $user = new User($email, $name, $userRole);
        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::INVALID;
        }
        $user->setPasswordHash($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('Користувача %s (%s) створено з роллю %s', $user->getEmail(), $user->getDisplayName(), $userRole->value));

        return Command::SUCCESS;
    }
}
