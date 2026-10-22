<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Однакові поля зображення для CRUD з Vich (портовано з service-sites): аплоад у формі, прев'ю Liip у списку
 * та на сторінці перегляду, alt. Файл існуючого запису не замінюється (рішення PR #2, п. 7) — поле лише при створенні.
 * Підписи — ключі домену admin (media.field.*, media.help.*); $label — ключ підпису прев'ю від CRUD-контролера.
 */
trait ImageFieldsTrait
{
    /**
     * @param string $fileProperty властивість з Vich\UploadableField (File)
     * @param string $pathProperty властивість з ім'ям файлу (fileNameProperty)
     * @param string $altProperty  властивість alt
     * @param string $uriPrefix    uri_prefix мапінгу Vich ('/media')
     */
    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function imageFields(string $pageName, string $fileProperty = 'file', string $pathProperty = 'path', string $altProperty = 'alt', string $uriPrefix = '/media', string $label = 'media.field.image'): iterable
    {
        yield ImageField::new($pathProperty, $label)
            ->setBasePath('/media/cache/resolve/admin_thumb'.$uriPrefix)
            ->onlyOnIndex();
        yield ImageField::new($pathProperty, $label)
            ->setBasePath($uriPrefix)
            ->onlyOnDetail();
        yield Field::new($fileProperty, 'media.field.file')
            ->onlyWhenCreating()
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => false, 'download_uri' => false, 'image_uri' => false, 'required' => Crud::PAGE_NEW === $pageName])
            ->setHelp('media.help.file');
        yield TextField::new($altProperty, 'media.field.alt')
            ->setHelp('media.help.alt')
            ->setRequired(false);
    }
}
