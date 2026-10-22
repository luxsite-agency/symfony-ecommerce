<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Theme;

use App\Core\AdminApi\Auth\ApiTokenContext;
use App\Site\Theme\Live\LiveTheme;
use App\Site\Theme\Templates\StoreTemplates;
use FilesystemIterator;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Живий дизайн під персональним токеном (рішення власника 20.10.2026 «як у сервісних сайтах»; еталон —
 * service-engine App\AdminApi\ThemeService). Файли — у var/share/theme (LiveTheme): шаблони site/** Twig бере першими
 * (LiveThemeLoader: live > пак > рушій), theme.css — після стилів рушія й паку. Застосовується ОДРАЗУ, без пакета змін —
 * це єдиний запис у рушії поза пакетами, тому запобіжники жорсткіші за пак:
 *   1. ім'я — лише site/**.twig поза забороненою зоною паку (кабінет, _schema, sitemap, ліцензія) або theme.css; без «..»,
 *      символьних посилань і виходу за теку; файл ≤ 256 КБ, файлів ≤ 200 (LiveTheme::normalize);
 *   2. Twig: синтаксис і прохід по AST з явним allowlist тегів, фільтрів, функцій, тестів, властивостей і ланцюжків app.*
 *      (ThemeTemplateGuard + LiveThemePolicy; рев'ю C M1–M2: без app.request.server, _context, attribute, render/controller,
 *      include_only, source/dump, constant довільних класів, рядків-callable у filter|map|sort);
 *   3. після запису — рендер ключових сторінок через HTTP до nginx (ThemeSmoke): 5xx, обірваний HTML, редирект кошика чи
 *      checkout, форма без _token/key/price або з чужим action → файл повертається як був, відповідь 422;
 *   4. кожна попередня версія — history/ (до 20), revert через той самий put; delete знімає перекриття (далі пак чи рушій);
 *   5. журнал admin_api: хто (user_id, token_id), який файл, скільки байтів — без вмісту;
 *   6. put/delete/revert — під одним блокуванням на магазин (LockFactory, ключ LOCK; рев'ю C M3): два одночасні записи інакше
 *      знімали б одне previous, і провал smoke одного повертав би старе поверх успішного іншого. Чекання до LOCK_WAIT с
 *      (smoke триває секунди), далі 409 — клієнт повторює.
 * Скоуп theme:write перевіряють REST (#[IsGranted]) і MCP (McpTools::SCOPES); тут — ще раз, бо сервіс пише на диск.
 * У git паку ці правки не потрапляють: розробник переносить їх у themes/<key>/templates/ (get віддає поточний текст).
 */
#[WithMonologChannel('admin_api')]
final class ThemeService
{
    public const int MAX_BYTES = 256 * 1024;
    public const int MAX_FILES = 200;
    public const int HISTORY_KEEP = 20;
    public const string SCOPE = 'scope:theme:write';
    public const string LOCK = 'theme-live';
    /** TTL блокування: довше за найдовший smoke (ThemeSmoke::BUDGET) з запасом. */
    public const float LOCK_TTL = 120.0;

    public function __construct(
        private readonly LiveTheme $theme,
        private readonly ThemeTemplateGuard $guard,
        private readonly ThemeSmoke $smoke,
        private readonly ApiTokenContext $context,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $locks,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%app.store_templates_dir%')] private readonly string $storeTemplatesDir,
        #[Autowire('%app.theme_lock_wait%')] private readonly float $lockWait,
    ) {
    }

    /**
     * Що можна перекрити і що перекрито: шаблони рушія site/** з позначкою, звідки береться поточна версія (live | pack |
     * engine), заборонена зона — locked, власні живі файли, theme.css.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $this->requireScope();
        $rows = [];
        $engine = self::twigFilesUnder($this->projectDir.'/templates/site');
        foreach ($engine as $rel) {
            $rel = 'site/'.$rel;
            $row = ['path' => $rel, 'source' => $this->sourceOf($rel), 'history' => \count($this->historyOf($rel))];
            if (StoreTemplates::isForbidden($rel)) {
                $row['locked'] = true;
            }
            $rows[] = $row;
        }
        $known = array_column($rows, 'path');
        foreach (self::twigFilesUnder($this->theme->templatesDir().'/site') as $rel) {
            $rel = 'site/'.$rel;
            if (!\in_array($rel, $known, true) && LiveTheme::isTemplateName($rel)) {
                $rows[] = ['path' => $rel, 'source' => 'live', 'history' => \count($this->historyOf($rel)), 'note' => 'власний файл (у рушії нема)'];
            }
        }
        $rows[] = ['path' => LiveTheme::CSS, 'source' => $this->sourceOf(LiveTheme::CSS), 'history' => \count($this->historyOf(LiveTheme::CSS)), 'note' => 'додатковий CSS після стилів рушія, паку й токенів теми'];

        return [
            'root' => 'var/share/theme',
            'contract' => StoreTemplates::CONTRACT,
            'files' => $rows,
            'rules' => \sprintf('Лише site/**.twig поза забороненою зоною (locked) і theme.css; файл до %d КБ, до %d файлів. Шаблони рушія доступні як @engine/… для {%% extends %%}: перевизначайте блоки, не копіюйте цілком. %s Після put ключові сторінки рендеряться автоматично; провал = файл повертається, 422. Блоки, змінні й класи — docs/themes.md.', self::MAX_BYTES / 1024, self::MAX_FILES, LiveThemePolicy::summary()),
        ];
    }

    /** @return array<string, mixed> поточний текст (живий, з паку або рушія), звідки він, версії в історії */
    public function get(string $path): array
    {
        $this->requireScope();
        $rel = $this->name($path, write: false);
        $source = $this->sourceOf($rel);
        $file = match ($source) {
            'live' => $this->theme->path($rel),
            'pack' => $this->storeTemplatesDir.'/'.$rel,
            'engine' => $this->projectDir.'/templates/'.$rel,
            default => null,
        };

        return ['path' => $rel, 'source' => $source, 'locked' => LiveTheme::CSS !== $rel && StoreTemplates::isForbidden($rel), 'content' => null === $file ? null : (string) file_get_contents($file), 'history' => $this->historyOf($rel)];
    }

    /**
     * Записати живий файл: правила імені → розмір і кількість → Twig-страж → історія → запис → рендер ключових сторінок →
     * при провалі повернення попередньої версії і 422.
     *
     * @return array<string, mixed>
     */
    public function put(string $path, string $content): array
    {
        $this->requireScope();
        $rel = $this->name($path, write: true);

        return $this->locked(fn (): array => $this->doPut($rel, $content));
    }

    /**
     * @return array<string, mixed>
     */
    private function doPut(string $rel, string $content): array
    {
        if (\strlen($content) > self::MAX_BYTES) {
            throw new ThemeException(\sprintf('Файл більший за %d КБ', self::MAX_BYTES / 1024), 413, 'payload_too_large', ['limit' => self::MAX_BYTES]);
        }
        $file = $this->theme->path($rel);
        $exists = is_file($file);
        if (!$exists && $this->liveFileCount() >= self::MAX_FILES) {
            throw new ThemeException(\sprintf('У живій темі вже %d файлів — приберіть зайві (delete)', self::MAX_FILES));
        }
        if (LiveTheme::CSS !== $rel && [] !== $problems = $this->guard->check($rel, $content)) {
            throw new ThemeException('Шаблон відхилено: '.implode('; ', $problems), 422, 'validation_failed', ['errors' => $problems]);
        }
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ThemeException('Не вдалося створити теку для '.$rel, 500, 'storage_error');
        }
        $this->assertInside($dir);
        $previous = $exists ? (string) file_get_contents($file) : null;
        if ($previous === $content) {
            return ['path' => $rel, 'source' => 'live', 'bytes' => \strlen($content), 'smoke' => 'skipped', 'unchanged' => true, 'history' => $this->historyOf($rel)];
        }
        $version = $this->snapshot($rel, $previous);
        self::write($file, $content);
        $checked = 0;
        if (LiveTheme::CSS !== $rel) {
            [$failed, $checked] = $this->smoke->run();
            if (null !== $failed) {
                if (null === $previous) {
                    @unlink($file);
                } else {
                    self::write($file, $previous);
                }
                if (null !== $version) {
                    @unlink($this->historyDir($rel).'/'.$version); // повернута версія знов поточна — дубль в історії ні до чого (L3)
                }
                $this->logger->warning('theme.put_rejected', ['path' => $rel, 'bytes' => \strlen($content), 'reason' => $failed] + $this->who());
                throw new ThemeException('Сторінка не рендериться після зміни, файл повернуто: '.$failed, 422, 'validation_failed', ['errors' => [$failed]]);
            }
        }
        $this->logger->info('theme.put', ['path' => $rel, 'bytes' => \strlen($content), 'previous' => null !== $previous, 'pages' => $checked] + $this->who());

        return ['path' => $rel, 'source' => 'live', 'bytes' => \strlen($content), 'smoke' => LiveTheme::CSS === $rel ? 'skipped' : 'ok', 'pages' => $checked, 'history' => $this->historyOf($rel)];
    }

    /**
     * Прибрати живе перекриття: далі береться пак або рушій; версія лишається в історії.
     *
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        $this->requireScope();
        $rel = $this->name($path, write: true);

        return $this->locked(function () use ($rel): array {
            $file = $this->theme->path($rel);
            if (!is_file($file)) {
                throw new ThemeException('Живого перекриття для '.$rel.' нема', 404, 'not_found');
            }
            $this->snapshot($rel, (string) file_get_contents($file));
            unlink($file);
            $this->logger->info('theme.delete', ['path' => $rel] + $this->who());

            return ['path' => $rel, 'source' => $this->sourceOf($rel), 'history' => $this->historyOf($rel)];
        });
    }

    /**
     * Повернути версію з історії (version — як у history: YYYYMMDD-HHMMSS[-n]) через той самий put з усіма перевірками.
     *
     * @return array<string, mixed>
     */
    public function revert(string $path, string $version): array
    {
        $this->requireScope();
        $rel = $this->name($path, write: true);
        if (1 !== preg_match('/^\d{8}-\d{6}(-\d+)?$/', $version)) {
            throw new ThemeException('version — як у history: YYYYMMDD-HHMMSS');
        }
        $file = $this->historyDir($rel).'/'.$version;
        if (!is_file($file)) {
            throw new ThemeException('Версії нема: '.$version, 404, 'not_found');
        }

        return $this->locked(fn (): array => $this->doPut($rel, (string) file_get_contents($file)));
    }

    /**
     * Один запис живої теми за раз (M3): блокування на магазин, чекання до lockWait с, далі 409 з Retry-After.
     *
     * @template T
     *
     * @param callable(): T $op
     *
     * @return T
     */
    private function locked(callable $op): mixed
    {
        $lock = $this->locks->createLock(self::LOCK, self::LOCK_TTL);
        $deadline = microtime(true) + $this->lockWait;
        while (!$lock->acquire()) {
            if (microtime(true) >= $deadline) {
                throw new ThemeException('Інший запис живої теми ще триває — повторіть за кілька секунд', 409, 'locked', ['retry_after' => 5]);
            }
            usleep(200_000);
        }
        try {
            return $op();
        } finally {
            $lock->release();
        }
    }

    private function requireScope(): void
    {
        if (!$this->auth->isGranted(self::SCOPE)) {
            throw new ThemeException('Токену бракує дозволу theme:write або власнику — ролі для нього', 403, 'insufficient_scope', ['scope' => 'theme:write']);
        }
    }

    /** Нормалізоване ім'я; для читання заборонена зона дозволена (подивитись _schema чи sitemap рушія можна, перекрити — ні). */
    private function name(string $path, bool $write): string
    {
        [$rel, $error] = LiveTheme::normalize($path);
        if (null === $rel) {
            if (!$write && null !== $error && str_starts_with($error, 'Заборонена зона')) {
                $rel = ltrim(str_replace('\\', '/', trim($path)), '/');
                $rel = preg_replace('#^templates/#', '', $rel) ?? $rel;
                if (1 === preg_match(LiveTheme::TEMPLATE_RE, $rel) && !str_contains($rel, '..') && !str_contains($rel, '//')) {
                    return $rel;
                }
            }
            throw new ThemeException((string) $error, 422, 'validation_failed', ['path' => $path]);
        }
        if (LiveTheme::CSS !== $rel && LiveTheme::hasSymlink($this->theme->templatesDir(), $rel)) {
            throw new ThemeException('У шляху символьне посилання — заборонено', 422, 'validation_failed', ['path' => $rel]);
        }

        return $rel;
    }

    /** Тека запису — справді всередині кореня живої теми (realpath після mkdir; симлінки в теках — теж вихід). */
    private function assertInside(string $dir): void
    {
        $root = realpath($this->theme->root());
        $real = realpath($dir);
        if (false === $root || false === $real || !str_starts_with($real.'/', $root.'/')) {
            throw new ThemeException('Шлях поза текою живої теми', 422, 'validation_failed');
        }
    }

    private function sourceOf(string $rel): string
    {
        $live = $this->theme->path($rel);
        if (is_file($live) && !is_link($live)) {
            return 'live';
        }
        if (LiveTheme::CSS === $rel) {
            return 'none';
        }
        if (is_file($this->storeTemplatesDir.'/'.$rel)) {
            return 'pack';
        }

        return is_file($this->projectDir.'/templates/'.$rel) ? 'engine' : 'none';
    }

    private function liveFileCount(): int
    {
        return \count(self::twigFilesUnder($this->theme->templatesDir())) + (is_file($this->theme->cssFile()) ? 1 : 0);
    }

    /** @return list<string> відносні шляхи *.twig під текою (без символьних посилань) */
    private static function twigFilesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink() && str_ends_with($file->getFilename(), '.twig')) {
                $out[] = str_replace('\\', '/', substr($file->getPathname(), \strlen(rtrim($dir, '/')) + 1));
            }
        }
        sort($out);

        return $out;
    }

    private function historyDir(string $rel): string
    {
        return $this->theme->root().'/history/'.str_replace('/', '__', $rel);
    }

    /** @return list<string> версії, новіші першими */
    private function historyOf(string $rel): array
    {
        $dir = $this->historyDir($rel);
        if (!is_dir($dir)) {
            return [];
        }
        $versions = array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => 1 === preg_match('/^\d{8}-\d{6}(-\d+)?$/', $f)));
        rsort($versions, \SORT_STRING);

        return $versions;
    }

    /** @return ?string ім'я записаної версії (null — попередньої версії не було) */
    private function snapshot(string $rel, ?string $previous): ?string
    {
        if (null === $previous) {
            return null;
        }
        $dir = $this->historyDir($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ThemeException('Не вдалося створити теку історії для '.$rel, 500, 'storage_error');
        }
        $base = date('Ymd-His');
        $name = $base;
        for ($i = 1; is_file($dir.'/'.$name); ++$i) {
            $name = $base.'-'.$i;
        }
        self::write($dir.'/'.$name, $previous);
        foreach (\array_slice($this->historyOf($rel), self::HISTORY_KEEP) as $old) {
            @unlink($dir.'/'.$old);
        }

        return $name;
    }

    /** Атомарно: tmp поруч + rename, щоб відвідувач не побачив півфайлу. */
    private static function write(string $file, string $content): void
    {
        $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
        if (false === file_put_contents($tmp, $content) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new ThemeException('Не вдалося записати '.basename($file), 500, 'storage_error');
        }
    }

    /** @return array<string, mixed> */
    private function who(): array
    {
        $token = $this->context->current();

        return ['user_id' => $token?->getUser()->getId(), 'token_id' => $token?->getId()];
    }
}
