<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Assembler;

use App\Infrastructure\Persistence\Entity\SavedPaymentMethodEntity;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Channel\Card\CardSavedPaymentMethod;
use Payroad\Domain\SavedPaymentMethod\SavedPaymentMethod;
use Payroad\Domain\SavedPaymentMethod\SavedPaymentMethodId;
use Payroad\Domain\SavedPaymentMethod\SavedPaymentMethodStatus;

final class SavedPaymentMethodAssembler
{
    public function toEntity(SavedPaymentMethod $method): SavedPaymentMethodEntity
    {
        $entity                = new SavedPaymentMethodEntity();
        $entity->id            = $method->getId()->value;
        $entity->customerId    = $method->getCustomerId()->value;
        $entity->providerName  = $method->getProviderName();
        $entity->providerToken = $method->getProviderToken();
        $entity->status        = $method->getStatus()->value;
        $entity->createdAt     = $method->getCreatedAt();
        $entity->methodType    = $method->getMethodType()->value;
        $entity->data          = ['_class' => $method->getData()::class, ...$method->getData()->toArray()];
        $entity->version       = $method->getVersion();

        return $entity;
    }

    public function toDomain(SavedPaymentMethodEntity $entity): SavedPaymentMethod
    {
        $class  = $entity->data['_class'] ?? throw new \RuntimeException('Missing _class in saved method data.');
        $data   = $class::fromArray(array_diff_key($entity->data, ['_class' => true]));
        $status = SavedPaymentMethodStatus::from($entity->status);

        $method = match ($entity->methodType) {
            'card'  => new CardSavedPaymentMethod(
                           SavedPaymentMethodId::fromUuid($entity->id),
                           new CustomerId($entity->customerId),
                           $entity->providerName,
                           $entity->providerToken,
                           $data,
                           $status,
                           $entity->createdAt,
                       ),
            default => throw new \RuntimeException("Unknown saved method type: '{$entity->methodType}'."),
        };

        $method->setVersion($entity->version);

        return $method;
    }
}
