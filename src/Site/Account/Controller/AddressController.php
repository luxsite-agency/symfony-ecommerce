<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAddress;
use App\Core\Customer\Repository\CustomerAddressRepository;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Shipping\NovaPoshta\NovaPoshtaDirectory;
use App\Site\Account\Form\AddressData;
use App\Site\Account\Form\AddressType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Адреси доставки в кабінеті (E12 PR-6): список, нова, редагування, видалення, «за замовчуванням». Лише свої
 * (WHERE customer = :me; чужа — 404). Відділення НП — через наявний довідник (той самий пікер, що на checkout,
 * і той самий серверний фолбек без JS). У журналі — лише id, без адрес.
 */
final class AddressController extends AbstractController
{
    public function __construct(
        private readonly CustomerAddressRepository $addresses,
        private readonly EntityManagerInterface $em,
        private readonly NovaPoshtaDirectory $novaPoshta,
        private readonly AuthAudit $audit,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function index(#[CurrentUser] Customer $customer): Response
    {
        return $this->render('site/account/addresses.html.twig', ['customer' => $customer, 'addresses' => $this->addresses->findForCustomer($customer), 'max' => CustomerAddress::MAX_PER_CUSTOMER]);
    }

    #[IsGranted(Customer::ROLE)]
    public function new(Request $request, #[CurrentUser] Customer $customer): Response
    {
        if (\count($this->addresses->findForCustomer($customer)) >= CustomerAddress::MAX_PER_CUSTOMER) {
            $this->addFlash('error', 'site.account.address.error.too_many');

            return $this->redirectToRoute('site_account_addresses');
        }

        return $this->form($request, $customer, new CustomerAddress($customer), null);
    }

    #[IsGranted(Customer::ROLE)]
    public function edit(Request $request, int $id, #[CurrentUser] Customer $customer): Response
    {
        $address = $this->addresses->findOneForCustomer($id, $customer) ?? throw $this->createNotFoundException();

        return $this->form($request, $customer, $address, AddressData::of($address));
    }

    #[IsGranted(Customer::ROLE)]
    public function delete(Request $request, int $id, #[CurrentUser] Customer $customer): Response
    {
        $this->assertCsrf($request);
        $address = $this->addresses->findOneForCustomer($id, $customer) ?? throw $this->createNotFoundException();
        $wasDefault = $address->isDefault();
        $this->em->remove($address);
        $this->em->flush();
        if ($wasDefault && null !== ($next = $this->addresses->findDefault($customer))) {
            $this->addresses->makeDefault($next);
            $this->em->flush();
        }
        $this->audit->log('address_deleted', $customer->getEmail(), $customer->getId(), ['address_id' => $id]);
        $this->addFlash('success', 'site.account.address.flash.deleted');

        return $this->redirectToRoute('site_account_addresses');
    }

    #[IsGranted(Customer::ROLE)]
    public function makeDefault(Request $request, int $id, #[CurrentUser] Customer $customer): Response
    {
        $this->assertCsrf($request);
        $address = $this->addresses->findOneForCustomer($id, $customer) ?? throw $this->createNotFoundException();
        $this->addresses->makeDefault($address);
        $this->em->flush();
        $this->addFlash('success', 'site.account.address.flash.default');

        return $this->redirectToRoute('site_account_addresses');
    }

    private function form(Request $request, Customer $customer, CustomerAddress $address, ?AddressData $data): Response
    {
        $isNew = null === $address->getId();
        $data ??= self::blank($customer);
        $form = $this->createForm(AddressType::class, $data);
        $form->handleRequest($request);
        $npEnabled = $this->novaPoshta->isEnabled();
        $npWarehouses = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $method = ShippingMethod::tryFrom($data->shipping) ?? ShippingMethod::NovaPoshta;
            $point = null;
            if ($npEnabled && ShippingMethod::NovaPoshta === $method) {
                $point = $this->novaPoshta->pointFor((string) $data->npWarehouseRef, $request->getLocale());
                if (null === $point && '' === trim((string) $data->address)) {
                    // місто впізнано, відділення не обрано: select відділень цього міста (фолбек без JS, як на checkout)
                    $city = '' !== (string) $data->npCityRef ? $this->novaPoshta->city((string) $data->npCityRef) : $this->novaPoshta->findCityByName((string) $data->city, $request->getLocale());
                    if (null !== $city) {
                        $data->npCityRef = $city->ref;
                        $data->city = $city->name($request->getLocale());
                        $npWarehouses = $this->novaPoshta->warehouses($city->ref, $request->getLocale());
                    }
                    $form->get('address')->addError(new FormError('site.checkout.np.choose_warehouse'));
                }
            }
            if (0 === \count($form->get('address')->getErrors())) {
                try {
                    $address->fill($data->label, $data->recipientName, $data->recipientPhone, $method, $data->city, $data->address, $point);
                    if ($isNew) {
                        $this->em->persist($address);
                    }
                    if ($data->isDefault || ($isNew && null === $this->addresses->findDefault($customer))) {
                        $this->em->flush();
                        $this->addresses->makeDefault($address);
                    }
                    $this->em->flush();
                    $this->audit->log($isNew ? 'address_added' : 'address_updated', $customer->getEmail(), $customer->getId(), ['address_id' => $address->getId()]);
                    $this->addFlash('success', 'site.account.address.flash.saved');

                    return $this->redirectToRoute('site_account_addresses');
                } catch (InvalidArgumentException $e) {
                    $form->get(str_contains($e->getMessage(), 'телефон') ? 'recipientPhone' : 'address')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('site/account/address_form.html.twig', [
            'customer' => $customer,
            'form' => $form,
            'data' => $data,
            'isNew' => $isNew,
            'addressId' => $address->getId(),
            'shippingMethods' => ShippingMethod::selectable(),
            'np' => $npEnabled ? ['warehouses' => $npWarehouses] : null,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    private static function blank(Customer $customer): AddressData
    {
        $data = new AddressData();
        $data->recipientName = $customer->getName();
        $data->recipientPhone = $customer->getPhone() ?? '';

        return $data;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
    }
}
