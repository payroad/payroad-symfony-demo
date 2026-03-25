<?php

declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Infrastructure\Persistence\Entity\PaymentEntity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-side query service for the payments dashboard.
 * Uses raw DBAL for queries that require JOINs or aggregates across tables.
 */
final class PaymentListQuery
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Builds a WHERE clause with positional parameters for the common payment filters.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(
        ?string $status,
        ?string $provider,
        ?string $search,
        ?string $from,
        ?string $to,
    ): array {
        $conditions = [];
        $params     = [];

        if ($status !== null && $status !== '') {
            $conditions[] = 'p.status = ?';
            $params[]     = $status;
        }

        if ($provider !== null && $provider !== '') {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM payment_attempts pa2
                WHERE pa2.payment_id = p.id AND pa2.provider_name = ?
            )';
            $params[] = $provider;
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(p.id ILIKE ? OR p.customer_id ILIKE ?)';
            $like         = '%' . $search . '%';
            $params[]     = $like;
            $params[]     = $like;
        }

        if ($from !== null && $from !== '') {
            $conditions[] = 'p.created_at >= ?';
            $params[]     = $from . ' 00:00:00';
        }

        if ($to !== null && $to !== '') {
            $conditions[] = 'p.created_at <= ?';
            $params[]     = $to . ' 23:59:59';
        }

        $sql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        return [$sql, $params];
    }

    // ── List & pagination ─────────────────────────────────────────────────────

    /**
     * Returns paginated payment rows enriched with provider/methodType from
     * the successful attempt (NULL for pending/failed payments).
     *
     * @return list<array{
     *     id: string,
     *     amount: int,
     *     currency: string,
     *     status: string,
     *     customerId: string,
     *     createdAt: string,
     *     refundedAmount: int,
     *     providerName: string|null,
     *     methodType: string|null,
     * }>
     */
    public function execute(
        ?string $status   = null,
        ?string $provider = null,
        ?string $search   = null,
        ?string $from     = null,
        ?string $to       = null,
        int     $limit    = 50,
        int     $offset   = 0,
    ): array {
        [$where, $params] = $this->buildWhere($status, $provider, $search, $from, $to);

        $sql = "
            SELECT
                p.id,
                p.amount_minor,
                p.amount_currency_code,
                p.status,
                p.customer_id,
                p.created_at,
                p.refunded_amount_minor,
                pa.provider_name,
                pa.method_type
            FROM payments p
            LEFT JOIN payment_attempts pa ON pa.id = p.successful_attempt_id
            {$where}
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->em->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn(array $row) => [
            'id'             => $row['id'],
            'amount'         => (int) $row['amount_minor'],
            'currency'       => $row['amount_currency_code'],
            'status'         => $row['status'],
            'customerId'     => $row['customer_id'],
            'createdAt'      => substr((string) $row['created_at'], 0, 19),
            'refundedAmount' => (int) $row['refunded_amount_minor'],
            'providerName'   => $row['provider_name'],
            'methodType'     => $row['method_type'],
        ], $rows);
    }

    public function countFiltered(
        ?string $status   = null,
        ?string $provider = null,
        ?string $search   = null,
        ?string $from     = null,
        ?string $to       = null,
    ): int {
        [$where, $params] = $this->buildWhere($status, $provider, $search, $from, $to);

        $sql = "
            SELECT COUNT(p.id)
            FROM payments p
            LEFT JOIN payment_attempts pa ON pa.id = p.successful_attempt_id
            {$where}
        ";

        return (int) $this->em->getConnection()->executeQuery($sql, $params)->fetchOne();
    }

    // ── Status counts (for stat cards) ────────────────────────────────────────

    public function countByStatus(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('p.status, COUNT(p.id) as cnt')
            ->from(PaymentEntity::class, 'p')
            ->groupBy('p.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    // ── Revenue metrics ───────────────────────────────────────────────────────

    /**
     * @return array{
     *     totalRevenue: int,
     *     netRevenue: int,
     *     avgOrderValue: int,
     *     refundRate: float,
     *     succeededCount: int,
     *     refundedCount: int,
     * }
     */
    public function revenueMetrics(?string $from = null, ?string $to = null): array
    {
        $conditions = [];
        $params     = [];

        if ($from !== null && $from !== '') {
            $conditions[] = 'created_at >= ?';
            $params[]     = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $conditions[] = 'created_at <= ?';
            $params[]     = $to . ' 23:59:59';
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN status = 'succeeded' THEN amount_minor ELSE 0 END), 0)
                    AS total_revenue,
                COALESCE(SUM(CASE WHEN status = 'succeeded'
                    THEN amount_minor - refunded_amount_minor ELSE 0 END), 0)
                    AS net_revenue,
                COUNT(CASE WHEN status = 'succeeded' THEN 1 END)
                    AS succeeded_count,
                COUNT(CASE WHEN status IN ('refunded', 'partially_refunded') THEN 1 END)
                    AS refunded_count
            FROM payments
            {$where}
        ";

        $row = $this->em->getConnection()->executeQuery($sql, $params)->fetchAssociative();

        $succeededCount = (int) ($row['succeeded_count'] ?? 0);
        $totalRevenue   = (int) ($row['total_revenue']   ?? 0);
        $refundedCount  = (int) ($row['refunded_count']  ?? 0);

        return [
            'totalRevenue'   => $totalRevenue,
            'netRevenue'     => (int) ($row['net_revenue'] ?? 0),
            'avgOrderValue'  => $succeededCount > 0 ? (int) ($totalRevenue / $succeededCount) : 0,
            'refundRate'     => $succeededCount > 0 ? round($refundedCount / $succeededCount, 4) : 0.0,
            'succeededCount' => $succeededCount,
            'refundedCount'  => $refundedCount,
        ];
    }

    // ── Chart data ────────────────────────────────────────────────────────────

    /**
     * Returns daily revenue for succeeded payments, defaulting to last 30 days.
     *
     * @return list<array{day: string, revenue: int, cnt: int}>
     */
    public function dailyRevenue(?string $from = null, ?string $to = null): array
    {
        $fromDate = ($from !== null && $from !== '') ? $from : date('Y-m-d', strtotime('-29 days'));
        $toDate   = ($to   !== null && $to   !== '') ? $to   : date('Y-m-d');

        $sql = "
            SELECT
                DATE(created_at)                    AS day,
                COALESCE(SUM(amount_minor), 0)      AS revenue,
                COUNT(id)                            AS cnt
            FROM payments
            WHERE status = 'succeeded'
              AND created_at >= ?
              AND created_at <= ?
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ";

        $rows = $this->em->getConnection()
            ->executeQuery($sql, [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'])
            ->fetchAllAssociative();

        return array_map(fn(array $r) => [
            'day'     => (string) $r['day'],
            'revenue' => (int) $r['revenue'],
            'cnt'     => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Revenue and count breakdown by provider for succeeded payments.
     *
     * @return list<array{provider: string, count: int, total: int}>
     */
    public function providerBreakdown(?string $from = null, ?string $to = null): array
    {
        $conditions = ["p.status = 'succeeded'"];
        $params     = [];

        if ($from !== null && $from !== '') {
            $conditions[] = 'p.created_at >= ?';
            $params[]     = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $conditions[] = 'p.created_at <= ?';
            $params[]     = $to . ' 23:59:59';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $sql = "
            SELECT
                pa.provider_name                    AS provider,
                COUNT(p.id)                          AS cnt,
                COALESCE(SUM(p.amount_minor), 0)    AS total
            FROM payments p
            JOIN payment_attempts pa ON pa.id = p.successful_attempt_id
            {$where}
            GROUP BY pa.provider_name
            ORDER BY total DESC
        ";

        $rows = $this->em->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn(array $r) => [
            'provider' => (string) $r['provider'],
            'count'    => (int) $r['cnt'],
            'total'    => (int) $r['total'],
        ], $rows);
    }
}
