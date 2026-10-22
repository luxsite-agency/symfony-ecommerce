<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo;

use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\Enum\RedirectKind;
use App\Core\Seo\Exception\InvalidRedirect;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Правила редиректів (епік E3) — пошук на 404, ручні правила (адмінка, імпорт CSV) і авто-301 при зміні адреси.
 *
 * Інваріант: ланцюгів нема — ціль 301/302 (шлях без query) ніколи не є джерелом іншого 301/302. Тому на 404 —
 * один запит унікальним індексом і одна переадресація. Тримається злиттям: нове правило A→B бере кінцеву ціль B
 * (B→C ⇒ A→C), а правила X→A переписуються на ту саму ціль (індекс target_path). Цикл (A→B при B→…→A) і редирект на
 * себе — відмова для ручних правил; авто-301 циклів не дає, бо нові адреси живі й правила з таким джерелом видаляються.
 * Абсолютна ціль на своєму site_host — це шлях (інакше A→https://site/B і B→A обходили б перевірку циклу). Ручні правила
 * пишуться під блокуванням (locked()): дві паралельні форми A→B і B→A не дадуть циклу.
 */
final class RedirectManager
{
    private const int MAX_CHAIN = 20;
    private const int CHUNK = 500;
    public const string EXTERNAL_DENIED = 'Ціль на іншому сайті може задати лише адміністратор; менеджеру — шлях від кореня (/…)';

    private const string LOCK = 'seo-redirect-rules';

    public function __construct(
        private readonly Connection $db,
        private readonly LiveUrls $live,
        private readonly LockFactory $locks,
        #[Autowire('%site_host%')] private readonly string $siteHost,
    ) {
    }

    /** Абсолютна ціль на своєму сайті → шлях. */
    public function localize(string $target): string
    {
        return RedirectPath::localize($target, $this->siteHost);
    }

    /**
     * Перевірка й запис ручного правила під одним блокуванням (паралельні A→B і B→A).
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function locked(callable $write): mixed
    {
        $lock = $this->locks->createLock(self::LOCK, 30);
        $lock->acquire(true);
        try {
            return $write();
        } finally {
            $lock->release();
        }
    }

    /** @return array{id: int, code: RedirectCode, target: string|null}|null */
    public function find(string $source): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, code, target FROM seo_redirect WHERE source = ?', [$source]);
        if (false === $row) {
            return null;
        }

        return ['id' => (int) $row['id'], 'code' => RedirectCode::from((int) $row['code']), 'target' => null === $row['target'] ? null : (string) $row['target']];
    }

    public function hit(int $id): void
    {
        $this->db->executeStatement('UPDATE seo_redirect SET hits = hits + 1, last_hit_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Повідомлення, чому ручне правило не можна зберегти, або null. Ціль — уже нормалізована
     * (RedirectPath::normalizeTarget). Жива адреса — відмова: правило не спрацювало б, а злиття ланцюгів переписало б
     * на його ціль правила, що ведуть на цю живу сторінку.
     */
    public function check(string $source, RedirectCode $code, ?string $target): ?string
    {
        return $this->problem($source, $code, $target)?->getMessage();
    }

    /** Те саме, що check(), але з ключем перекладу error.redirect.* (форма адмінки); виняток не кидається — це відповідь. */
    public function problem(string $source, RedirectCode $code, ?string $target): ?InvalidRedirect
    {
        if ($this->live->isLive($source)) {
            return new InvalidRedirect(\sprintf('Адреса %s зараз відкривається на сайті — правило спрацьовує лише на 404. Спершу зніміть сторінку з публікації або змініть її адресу (тоді 301 запишеться сам).', $source), 'error.redirect.source_live', ['source' => $source]);
        }
        if (!$code->isRedirect()) {
            return null;
        }
        if (null === $target) {
            return new InvalidRedirect('Для 301/302 вкажіть ціль: шлях від кореня (/…) або повний URL https://…', 'error.redirect.target_required');
        }
        try {
            $this->resolve($source, $target);
        } catch (InvalidRedirect $e) {
            return $e;
        }

        return null;
    }

    /**
     * Кінцева ціль правила $source → $target: якщо ціль — джерело іншого 301/302, береться його ціль (і так далі).
     *
     * @throws InvalidRedirect редирект на себе або цикл
     */
    public function resolve(string $source, string $target): string
    {
        $seen = [$source => true];
        $current = $this->localize($target);
        for ($i = 0; $i < self::MAX_CHAIN; ++$i) {
            $path = RedirectPath::targetPath($current);
            if (null === $path) {
                return $current;
            }
            if (isset($seen[$path])) {
                throw $path === $source && 0 === $i ? new InvalidRedirect('Редирект на ту саму адресу', 'error.redirect.self') : new InvalidRedirect(\sprintf('Цикл редиректів: %s → %s веде назад на %s', $source, $target, $path), 'error.redirect.loop', ['source' => $source, 'target' => $target, 'path' => $path]);
            }
            $seen[$path] = true;
            $row = $this->db->fetchAssociative('SELECT code, target FROM seo_redirect WHERE source = ?', [$path]);
            if (false === $row || null === $row['target'] || !RedirectCode::from((int) $row['code'])->isRedirect()) {
                return $current;
            }
            $current = (string) $row['target'];
        }

        throw new InvalidRedirect(\sprintf('Задовгий ланцюг редиректів від %s', $source), 'error.redirect.chain_too_long', ['source' => $source]);
    }

    /**
     * Після збереження правила: його джерело знято з журналу 404, правила X→source ведуть одразу на кінцеву ціль.
     * Ціль — уже після resolve().
     */
    public function merge(string $source, RedirectCode $code, ?string $target): void
    {
        $this->db->executeStatement('DELETE FROM seo_not_found WHERE path = ?', [$source]);
        if ($code->isRedirect() && null !== $target) {
            $this->repoint($source, $target);
        }
    }

    /**
     * Створити або замінити правило за джерелом (імпорт CSV). Повертає true, якщо правило нове. $allowExternal = false —
     * ціль лише на своєму сайті (менеджер без ROLE_ADMIN не веде відвідувачів на чужі домени).
     *
     * @throws InvalidRedirect
     */
    public function save(string $rawSource, RedirectCode $code, ?string $rawTarget, RedirectKind $kind = RedirectKind::Manual, bool $allowExternal = true): bool
    {
        $source = RedirectPath::normalize($rawSource) ?? throw new InvalidRedirect(\sprintf('Невалідна адреса-джерело «%s»: потрібен шлях від кореня сайту', $rawSource), 'error.redirect.invalid_source', ['source' => $rawSource]);
        $target = null;
        if ($code->isRedirect() && null !== $rawTarget && '' !== trim($rawTarget)) {
            $target = RedirectPath::normalizeTarget($rawTarget) ?? throw new InvalidRedirect(\sprintf('Невалідна ціль «%s»: шлях від кореня (/…) або повний URL https://…', $rawTarget), 'error.redirect.invalid_target', ['target' => $rawTarget]);
            $target = $this->localize($target);
            if (!$allowExternal && RedirectPath::isAbsoluteUrl($target)) {
                throw new InvalidRedirect(self::EXTERNAL_DENIED, 'error.redirect.external_denied');
            }
        }

        return $this->locked(function () use ($source, $code, $target, $kind): bool {
            if (null !== ($problem = $this->problem($source, $code, $target))) {
                throw $problem;
            }
            $target = null === $target ? null : $this->resolve($source, $target);

            return $this->db->transactional(function () use ($source, $code, $target, $kind): bool {
                $created = 1 === $this->upsert($source, $code, $target, $kind);
                $this->merge($source, $code, $target);

                return $created;
            });
        });
    }

    /**
     * Авто-301: адреси сутностей змінились (слаг товару, сторінки, статті; шлях категорії з нащадками). Нові адреси
     * живі — правила з таким джерелом видаляються (і старе A→B, коли B повернули назад на A); стара → нова (301, auto),
     * правила, що вели на стару, — на нову. Обмін адресами (A↔B в одному пакеті) правил не дає: обидві живі.
     *
     * @param iterable<array{0: string, 1: string}> $moves пари [стара, нова] шляхів сайту
     *
     * @return int записаних правил
     */
    public function recordMoves(iterable $moves): int
    {
        $pairs = [];
        foreach ($moves as [$old, $new]) {
            $old = RedirectPath::normalize($old);
            $new = RedirectPath::normalize($new);
            if (null !== $old && null !== $new && $old !== $new) {
                $pairs[$old] = $new;
            }
        }
        if ([] === $pairs) {
            return 0;
        }
        $live = array_flip(array_values($pairs));

        return $this->db->transactional(function () use ($pairs, $live): int {
            $this->release(array_keys($live));
            $written = 0;
            foreach ($pairs as $old => $new) {
                if (isset($live[$old])) {
                    continue;
                }
                $this->upsert($old, RedirectCode::Permanent, $new, RedirectKind::Auto);
                $this->repoint($old, $new);
                ++$written;
            }

            return $written;
        });
    }

    /**
     * Адреси щойно стали живими (нова сутність, новий переклад): правила з таким джерелом більше не потрібні, а
     * правила, що вели на них, лишаються — сторінка знову є.
     *
     * @param list<string> $paths нормалізовані шляхи
     */
    public function release(array $paths): void
    {
        foreach (array_chunk(array_values(array_unique($paths)), self::CHUNK) as $chunk) {
            $this->db->executeStatement('DELETE FROM seo_redirect WHERE source IN (?)', [$chunk], [ArrayParameterType::STRING]);
        }
    }

    /** X→$from ⇒ X→$to; правило, що стало б «на себе» ($to→$from), видаляється. */
    private function repoint(string $from, string $to): void
    {
        $toPath = RedirectPath::targetPath($to);
        if (null !== $toPath) {
            $this->db->executeStatement('DELETE FROM seo_redirect WHERE target_path = ? AND source = ?', [$from, $toPath]);
        }
        $this->db->executeStatement('UPDATE seo_redirect SET target = ?, target_path = ? WHERE target_path = ? AND source <> ?', [$to, $toPath, $from, $from]);
    }

    /** @return int 1 — вставлено, 2 — оновлено, 0 — без змін (affected rows MySQL) */
    private function upsert(string $source, RedirectCode $code, ?string $target, RedirectKind $kind): int
    {
        return (int) $this->db->executeStatement(
            'INSERT INTO seo_redirect (source, target, target_path, code, kind, hits, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW()) AS new
             ON DUPLICATE KEY UPDATE target = new.target, target_path = new.target_path, code = new.code, kind = new.kind',
            [$source, $target, null === $target ? null : RedirectPath::targetPath($target), $code->value, $kind->value],
        );
    }
}
