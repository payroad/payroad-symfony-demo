<?php

declare(strict_types=1);

namespace App\Infrastructure\Query;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-side query service for the payment detail view.
 * Returns a fully denormalized array including attempts and refunds.
 */
final class PaymentDetailQuery
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Fetches a single payment with its attempts and refunds.
     * Returns null if the payment does not exist.
     *
     * @return array{
     *     id: string,
     *     amount: int,
     *     currency: string,
     *     currencyPrecision: int,
     *     status: string,
     *     customerId: string,
     *     createdAt: string,
     *     expiresAt: string|null,
     *     refundedAmount: int,
     *     providerName: string|null,
     *     methodType: string|null,
     *     canRefund: bool,
     *     canCancel: bool,
     *     attempts: list<array>,
     *     refunds: list<array>,
     * }|null
     */
    public function find(string $id): ?array
    {
        $conn = $this->em->getConnection();

        $row = $conn->executeQuery(
            'SELECT
                 p.id, p.amount_minor, p.amount_currency_code, p.amount_currency_precision,
                 p.status, p.customer_id, p.created_at, p.expires_at, p.refunded_amount_minor,
                 pa.provider_name, pa.method_type
             FROM payments p
             LEFT JOIN payment_attempts pa ON pa.id = p.successful_attempt_id
             WHERE p.id = ?',
            [$id],
        )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $attempts = $conn->executeQuery(
            'SELECT
                 id, provider_name, method_type, status, provider_status,
                 provider_reference, amount_minor, amount_currency_code, created_at
             FROM payment_attempts
             WHERE payment_id = ?
             ORDER BY created_at DESC',
            [$id],
        )->fetchAllAssociative();

        $refunds = $conn->executeQuery(
            'SELECT
                 id, provider_name, method_type, status, provider_status,
                 amount_minor, amount_currency_code, created_at
             FROM refunds
             WHERE payment_id = ?
             ORDER BY created_at DESC',
            [$id],
        )->fetchAllAssociative();

        $terminalStatuses = ['succeeded', 'failed', 'canceled', 'refunded', 'expired'];
        $isTerminal = in_array($row['status'], $terminalStatuses, true);

        // Refund is available for card, cash and p2p payments in a refundable state
        $canRefund = in_array($row['status'], ['succeeded', 'partially_refunded'], true)
            && in_array($row['method_type'] ?? '', ['card', 'cash', 'p2p'], true);

        // Cancel is available for any non-terminal payment that has no active attempts
        $canCancel = !$isTerminal;

        return [
            'id'                => $row['id'],
            'amount'            => (int) $row['amount_minor'],
            'currency'          => $row['amount_currency_code'],
            'currencyPrecision' => (int) $row['amount_currency_precision'],
            'status'            => $row['status'],
            'customerId'        => $row['customer_id'],
            'createdAt'         => substr((string) $row['created_at'], 0, 19),
            'expiresAt'         => $row['expires_at'] ? substr((string) $row['expires_at'], 0, 19) : null,
            'refundedAmount'    => (int) $row['refunded_amount_minor'],
            'providerName'      => $row['provider_name'],
            'methodType'        => $row['method_type'],
            'canRefund'         => $canRefund,
            'canCancel'         => $canCancel,
            'attempts'          => array_map(fn(array $a) => [
                'id'                => $a['id'],
                'providerName'      => $a['provider_name'],
                'methodType'        => $a['method_type'],
                'status'            => $a['status'],
                'providerStatus'    => $a['provider_status'],
                'providerReference' => $a['provider_reference'],
                'amount'            => (int) $a['amount_minor'],
                'currency'          => $a['amount_currency_code'],
                'createdAt'         => substr((string) $a['created_at'], 0, 19),
            ], $attempts),
            'refunds'           => array_map(fn(array $r) => [
                'id'             => $r['id'],
                'providerName'   => $r['provider_name'],
                'methodType'     => $r['method_type'],
                'status'         => $r['status'],
                'providerStatus' => $r['provider_status'],
                'amount'         => (int) $r['amount_minor'],
                'currency'       => $r['amount_currency_code'],
                'createdAt'      => substr((string) $r['created_at'], 0, 19),
            ], $refunds),
        ];
    }
}
