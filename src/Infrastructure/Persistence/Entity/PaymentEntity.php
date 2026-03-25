<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use DateTimeImmutable;

final class PaymentEntity
{
    public string            $id;
    public int               $amountMinor;
    public string            $amountCurrencyCode;
    public int               $amountCurrencyPrecision;
    public string            $customerId;
    public string            $status;
    public ?string           $successfulAttemptId  = null;
    public array             $metadata             = [];
    public DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $expiresAt            = null;
    public int               $refundedAmountMinor  = 0;
    public int               $version              = 0;
}
