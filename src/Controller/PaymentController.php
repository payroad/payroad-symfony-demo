<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Currency\KnownCurrencies;
use App\Infrastructure\Query\PaymentDetailQuery;
use App\Infrastructure\Query\PaymentListQuery;
use Payroad\Application\Exception\PaymentNotFoundException;
use Payroad\Application\UseCase\Card\InitiateCardAttemptCommand;
use Payroad\Application\UseCase\Card\InitiateCardAttemptUseCase;
use Payroad\Application\UseCase\Payment\CancelPaymentCommand;
use Payroad\Application\UseCase\Payment\CancelPaymentUseCase;
use Payroad\Application\UseCase\Payment\CreatePaymentCommand;
use Payroad\Application\UseCase\Payment\CreatePaymentUseCase;
use Payroad\Application\UseCase\Webhook\HandleWebhookCommand;
use Payroad\Application\UseCase\Webhook\HandleWebhookUseCase;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Payment\PaymentMetadata;
use Payroad\Domain\Channel\Card\CardAttemptData;
use Payroad\Port\Provider\Card\CardAttemptContext;
use Payroad\Port\Provider\Card\OneStepCardProviderInterface;
use Payroad\Port\Provider\Card\TwoStepCardProviderInterface;
use Payroad\Port\Provider\ProviderRegistryInterface;
use Payroad\Port\Provider\WebhookResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly CreatePaymentUseCase       $createPayment,
        private readonly InitiateCardAttemptUseCase $initiateCardAttempt,
        private readonly CancelPaymentUseCase       $cancelPayment,
        private readonly HandleWebhookUseCase       $handleWebhook,
        private readonly ProviderRegistryInterface  $providers,
        private readonly PaymentListQuery           $listQuery,
        private readonly PaymentDetailQuery         $detailQuery,
    ) {}

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * POST /api/payments
     *
     * Body: { "amount": 1000, "currency": "USD", "customerId": "cust_123" }
     */
    #[Route('/api/payments', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $request->toArray();

        $currency = KnownCurrencies::get($body['currency'] ?? 'USD');
        $amount   = Money::ofMinor((int) ($body['amount'] ?? 0), $currency);

        $payment = $this->createPayment->execute(new CreatePaymentCommand(
            amount:     $amount,
            customerId: new CustomerId($body['customerId'] ?? 'guest'),
            metadata:   PaymentMetadata::fromArray($body['metadata'] ?? []),
        ));

        $providerName = $body['provider'] ?? 'stripe';

        $attempt = $this->initiateCardAttempt->execute(new InitiateCardAttemptCommand(
            paymentId:    $payment->getId(),
            providerName: $providerName,
            context:      new CardAttemptContext(
                customerIp:       $request->getClientIp() ?? '0.0.0.0',
                browserUserAgent: $request->headers->get('User-Agent', ''),
            ),
        ));

        /** @var CardAttemptData $data */
        $data = $attempt->getData();

        return $this->json([
            'paymentId'    => $payment->getId()->value,
            'attemptId'    => $attempt->getId()->value,
            'clientToken'  => $data->getClientToken(),
            'amount'       => $payment->getAmount()->getMinorAmount(),
            'currency'     => $payment->getAmount()->getCurrency()->code,
        ], Response::HTTP_CREATED);
    }

    // ── Charge (two-step providers: Braintree, etc.) ──────────────────────────

    /**
     * POST /api/payments/{id}/charge
     *
     * Submits a client-provided nonce to complete a server-side charge.
     * Used by providers with a two-step flow (init → submit nonce).
     *
     * Body: { "attemptId": "...", "nonce": "..." }
     */
    #[Route('/api/payments/{id}/charge', methods: ['POST'])]
    public function charge(string $id, Request $request): JsonResponse
    {
        $body      = $request->toArray();
        $attemptId = (string) ($body['attemptId'] ?? '');
        $nonce     = (string) ($body['nonce']     ?? '');

        if ($attemptId === '' || $nonce === '') {
            return $this->json(['error' => 'attemptId and nonce are required'], Response::HTTP_BAD_REQUEST);
        }

        $payment = $this->detailQuery->find($id);
        if ($payment === null) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        }

        $attempt = null;
        foreach ($payment['attempts'] as $a) {
            if ($a['id'] === $attemptId) {
                $attempt = $a;
                break;
            }
        }

        if ($attempt === null) {
            return $this->json(['error' => 'Attempt not found'], Response::HTTP_NOT_FOUND);
        }

        $currency = \App\Infrastructure\Currency\KnownCurrencies::get($payment['currency']);
        $amount   = Money::ofMinor($payment['amount'], $currency);

        $provider = $this->providers->forCard($attempt['providerName']);

        if (!$provider instanceof TwoStepCardProviderInterface) {
            if ($provider instanceof OneStepCardProviderInterface) {
                return $this->json(
                    ['error' => "Provider \"{$attempt['providerName']}\" uses a one-step flow — the charge is confirmed client-side, no nonce submission needed."],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            return $this->json(
                ['error' => "Provider \"{$attempt['providerName']}\" does not support server-side nonce submission."],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $result = $provider->chargeWithNonce($nonce, $amount);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->handleWebhook->execute(new HandleWebhookCommand(
            $attempt['providerName'],
            new WebhookResult(
                providerReference:    $attempt['providerReference'],
                newStatus:            $result->newStatus,
                providerStatus:       $result->providerStatus,
                statusChanged:        true,
                newProviderReference: $result->transactionId,
            ),
        ));

        return $this->json([
            'paymentId'     => $id,
            'transactionId' => $result->transactionId,
        ], Response::HTTP_CREATED);
    }

    // ── Metrics / chart / export  (priority:1 keeps them above /{id}) ─────────

    /**
     * GET /api/payments/metrics?from=Y-m-d&to=Y-m-d
     *
     * Returns aggregate revenue stats for the given date range.
     */
    #[Route('/api/payments/metrics', methods: ['GET'], priority: 1)]
    public function metrics(Request $request): JsonResponse
    {
        $from = $request->query->get('from');
        $to   = $request->query->get('to');

        $metrics   = $this->listQuery->revenueMetrics($from ?: null, $to ?: null);
        $breakdown = $this->listQuery->providerBreakdown($from ?: null, $to ?: null);

        return $this->json([
            'totalRevenue'   => $metrics['totalRevenue'],
            'netRevenue'     => $metrics['netRevenue'],
            'avgOrderValue'  => $metrics['avgOrderValue'],
            'refundRate'     => $metrics['refundRate'],
            'succeededCount' => $metrics['succeededCount'],
            'refundedCount'  => $metrics['refundedCount'],
            'byProvider'     => $breakdown,
        ]);
    }

    /**
     * GET /api/payments/chart?from=Y-m-d&to=Y-m-d
     *
     * Returns daily revenue series and provider breakdown for charts.
     */
    #[Route('/api/payments/chart', methods: ['GET'], priority: 1)]
    public function chart(Request $request): JsonResponse
    {
        $from = $request->query->get('from');
        $to   = $request->query->get('to');

        return $this->json([
            'daily'      => $this->listQuery->dailyRevenue($from ?: null, $to ?: null),
            'byProvider' => $this->listQuery->providerBreakdown($from ?: null, $to ?: null),
        ]);
    }

    /**
     * GET /api/payments/export?status=&provider=&search=&from=&to=
     *
     * Downloads a CSV of matching payments (up to 10 000 rows).
     */
    #[Route('/api/payments/export', methods: ['GET'], priority: 1)]
    public function export(Request $request): Response
    {
        $rows = $this->listQuery->execute(
            status:   $request->query->get('status')   ?: null,
            provider: $request->query->get('provider') ?: null,
            search:   $request->query->get('search')   ?: null,
            from:     $request->query->get('from')     ?: null,
            to:       $request->query->get('to')       ?: null,
            limit:    10_000,
            offset:   0,
        );

        $csv = "id,amount,currency,status,customerId,providerName,methodType,createdAt\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row['id'],
                $row['amount'],
                $row['currency'],
                $row['status'],
                '"' . str_replace('"', '""', $row['customerId']) . '"',
                $row['providerName'] ?? '',
                $row['methodType']   ?? '',
                $row['createdAt'],
            ]) . "\n";
        }

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="payments-' . date('Y-m-d') . '.csv"',
        ]);
    }

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * GET /api/payments?status=&provider=&search=&from=&to=&page=1&limit=50
     */
    #[Route('/api/payments', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status   = $request->query->get('status')   ?: null;
        $provider = $request->query->get('provider') ?: null;
        $search   = $request->query->get('search')   ?: null;
        $from     = $request->query->get('from')     ?: null;
        $to       = $request->query->get('to')       ?: null;
        $limit    = min((int) ($request->query->get('limit', 50)), 100);
        $page     = max(1, (int) ($request->query->get('page', 1)));
        $offset   = ($page - 1) * $limit;

        $data  = $this->listQuery->execute($status, $provider, $search, $from, $to, $limit, $offset);
        $total = $this->listQuery->countFiltered($status, $provider, $search, $from, $to);

        return $this->json([
            'data'  => $data,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / $limit)),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * GET /api/payments/{id}
     *
     * Returns full payment detail including attempts and refunds.
     */
    #[Route('/api/payments/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $payment = $this->detailQuery->find($id);

        if ($payment === null) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($payment);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /**
     * POST /api/payments/{id}/cancel
     */
    #[Route('/api/payments/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id): JsonResponse
    {
        try {
            $this->cancelPayment->execute(new CancelPaymentCommand(PaymentId::fromUuid($id)));
        } catch (PaymentNotFoundException) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['status' => 'canceled']);
    }
}
