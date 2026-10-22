<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Справжні фото для демо-даних (замість плейсхолдерів DemoImageFactory): тека з файлами за конвенцією паку
 * store-demo (`catalog/demo-images/`, у образ її кладе `bin/store deploy` як `config/store/demo-images/`):
 *
 *   <KEY>-<n>.jpg          товар DemoCatalog::products() (KEY без префікса DEMO-, n = слот 1..images)
 *   category-<key>.jpg     категорія DemoCatalog::categories()
 *   post-<key>.jpg         обкладинка демо-статті DemoTexts::posts()
 *
 * Розширення — jpg/jpeg/webp/png, регістр ключа будь-який; симлінки ігноруються. Теки нема або файлу нема — сідер малює плейсхолдер, як і раніше.
 */
final class DemoPhotos
{
    private const array EXTENSIONS = ['jpg', 'jpeg', 'webp', 'png'];

    public function __construct(#[Autowire('%app.demo.images_dir%')] private readonly string $dir)
    {
    }

    /** Та сама служба з іншою текою (опція --images-dir команди). */
    public function withDir(string $dir): self
    {
        return new self($dir);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function isAvailable(): bool
    {
        return '' !== $this->dir && is_dir($this->dir);
    }

    public function product(string $key, int $slot): ?string
    {
        return $this->find($key.'-'.$slot);
    }

    public function category(string $key): ?string
    {
        return $this->find('category-'.$key);
    }

    public function post(string $key): ?string
    {
        return $this->find('post-'.$key);
    }

    /** sha256 файлу — ключ ідемпотентності: Media з таким contentHash уже є цим фото. */
    public static function hash(string $path): string
    {
        return (string) hash_file('sha256', $path);
    }

    private function find(string $base): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $root = realpath($this->dir);
        foreach ([$base, strtoupper($base), strtolower($base)] as $name) {
            foreach (self::EXTENSIONS as $extension) {
                $path = $this->dir.'/'.$name.'.'.$extension;
                // симлінк не приймаємо: інакше пак міг би опублікувати будь-який файл образу
                if (is_file($path) && !is_link($path) && false !== $root && \dirname((string) realpath($path)) === $root) {
                    return $path;
                }
            }
        }

        return null;
    }
}
