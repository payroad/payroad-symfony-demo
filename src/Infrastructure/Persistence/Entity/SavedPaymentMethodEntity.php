<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use DateTimeImmutable;

final class SavedPaymentMethodEntity
{
    public string            $id;
    public string            $customerId;
    public string            $providerName;
    public string            $providerToken;
    public string            $status;
    public DateTimeImmutable $createdAt;
    public string            $methodType;
    public array             $data    = [];
    public int               $version = 0;
}
