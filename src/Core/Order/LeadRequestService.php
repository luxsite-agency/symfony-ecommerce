<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Order\Attachment\AttachmentException;
use App\Core\Order\Attachment\AttachmentPolicy;
use App\Core\Order\Attachment\AttachmentStorage;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderAttachment;
use App\Core\Order\Entity\OrderStatusHistory;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendShopOrderEmail;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Загальна заявка без товару (B2B «усе через запит», бриф B2B-клієнта 20.10.2026): той самий канал, що й запит ціни —
 * Order із джерелом lead, без рядків (сума 0), компанія в колонці, контекст сторінки/вибраний варіант/адреса сторінки —
 * рядками в коментарі (CRM отримує лише buyer_comment); вкладення — OrderAttachment у приватному сховищі.
 * Outbox у тій самій транзакції: PushOrderToCrm і лист магазину; покупцю листа нема.
 *
 * Помилки валідації — ключі перекладу site.lead.error.<поле|код> (OrderValidationException::errors), контролер їх перекладає.
 */
final class LeadRequestService
{
    public const int MAX_COMMENT = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderNumberGenerator $numbers,
        private readonly MessageBusInterface $bus,
        private readonly AttachmentPolicy $policy,
        private readonly AttachmentStorage $storage,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
    ) {
    }

    /** @throws OrderValidationException */
    public function submit(LeadRequest $input): Order
    {
        $errors = [];
        $name = trim($input->name);
        if ('' === $name || mb_strlen($name) > 120) {
            $errors['name'] = 'site.lead.error.name';
        }
        $phone = '';
        try {
            $phone = PhoneNormalizer::normalize($input->phone);
        } catch (InvalidArgumentException) {
            $errors['phone'] = 'site.lead.error.phone';
        }
        $email = mb_strtolower(trim($input->email));
        if ('' !== $email && (mb_strlen($email) > 180 || false === filter_var($email, \FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'site.lead.error.email';
        }
        $company = mb_substr(trim($input->company), 0, 160);
        $comment = mb_substr(trim($input->comment), 0, self::MAX_COMMENT);
        $context = mb_substr(trim($input->context), 0, 200);
        $choice = mb_substr(trim($input->choice), 0, 200);
        $sourceUrl = mb_substr(trim($input->sourceUrl), 0, 500);
        if ('' === $comment && '' === $choice && '' === $context && null === $input->attachment) {
            $errors['comment'] = 'site.lead.error.comment';
        }
        $mime = null;
        if (null !== $input->attachment) {
            try {
                $mime = $this->policy->check($input->attachment);
            } catch (AttachmentException $e) {
                $errors['attachment'] = 'site.lead.error.'.$e->key;
            }
        }
        if ([] !== $errors) {
            throw new OrderValidationException($errors);
        }

        $storedName = null;
        $uploadedAt = new DateTimeImmutable();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $order = new Order($this->numbers->next(), OrderSource::Lead, $input->locale, $name, $phone, $this->currency, ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
            $order->setEmail('' === $email ? null : $email);
            $order->setCompany($company);
            $order->setComment(self::composeComment($comment, $context, $choice, $sourceUrl));
            $order->setIp($input->ip);
            $order->recalculateTotals();
            $order->addHistory(new OrderStatusHistory($order, null, $order->getStatus(), 'place', 'customer', OrderSource::Lead->value));
            if (null !== $input->attachment && null !== $mime) {
                $originalName = $input->attachment->getClientOriginalName();
                $size = (int) $input->attachment->getSize();
                $storedName = $this->storage->store($input->attachment, $uploadedAt);
                $this->em->persist(new OrderAttachment($order, '' === $originalName ? 'attachment.'.AttachmentPolicy::ALLOWED[$mime] : $originalName, $mime, $size, $storedName, $uploadedAt));
            }
            $this->em->persist($order);
            $this->em->flush();

            $this->bus->dispatch(new PushOrderToCrm((int) $order->getId()));
            $this->bus->dispatch(new SendShopOrderEmail((int) $order->getId()));
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            if (null !== $storedName) {
                $this->storage->discard($storedName, $uploadedAt);
            }
            throw $e;
        }

        return $order;
    }

    /** Коментар замовлення: текст покупця, далі службові рядки — те, що бачить менеджер у CRM і в адмінці. */
    public static function composeComment(string $comment, string $context, string $choice, string $sourceUrl): string
    {
        $lines = ['Заявка'.('' !== $comment ? ': '.$comment : '')];
        if ('' !== $context) {
            $lines[] = 'Контекст: '.$context;
        }
        if ('' !== $choice) {
            $lines[] = 'Варіант: '.$choice;
        }
        if ('' !== $sourceUrl) {
            $lines[] = 'Сторінка: '.$sourceUrl;
        }

        return implode("\n", $lines);
    }
}
