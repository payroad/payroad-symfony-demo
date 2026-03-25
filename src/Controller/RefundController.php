<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Query\PaymentDetailQuery;
use Payroad\Application\Exception\PaymentNotFoundException;
use Payroad\Application\Exception\PaymentNotRefundableException;
use Payroad\Application\Exception\RefundExceedsPaymentAmountException;
use Payroad\Application\UseCase\Card\InitiateCardRefundCommand;
use Payroad\Application\UseCase\Card\InitiateCardRefundUseCase;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RefundController extends AbstractController
{
    public function __construct(
        private readonly InitiateCardRefundUseCase $initiateRefund,
        private readonly PaymentDetailQuery        $detailQuery,
    ) {}

    /**
     * POST /api/refunds
     *
     * Body: { "paymentId": "uuid", "amount": 1000 }
     * Amount is in minor units (cents).
     */
    #[Route('/api/refunds', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body      = $request->toArray();
        $paymentId = (string) ($body['paymentId'] ?? '');
        $amount    = (int) ($body['amount'] ?? 0);

        if ($paymentId === '' || $amount <= 0) {
            return $this->json(
                ['error' => 'paymentId and a positive amount are required'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Fetch payment to get currency precision for Money construction
        $payment = $this->detailQuery->find($paymentId);
        if ($payment === null) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        }

        $currency = new Currency($payment['currency'], $payment['currencyPrecision']);

        try {
            $refund = $this->initiateRefund->execute(new InitiateCardRefundCommand(
                paymentId: PaymentId::fromUuid($paymentId),
                amount:    Money::ofMinor($amount, $currency),
            ));
        } catch (PaymentNotFoundException) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        } catch (PaymentNotRefundableException | RefundExceedsPaymentAmountException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\DomainException | \LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'refundId' => $refund->getId()->value,
            'status'   => $refund->getStatus()->value,
            'amount'   => $amount,
            'currency' => $payment['currency'],
        ], Response::HTTP_CREATED);
    }
}
