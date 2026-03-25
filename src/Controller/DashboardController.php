<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Query\PaymentListQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(private readonly PaymentListQuery $listQuery) {}

    #[Route('/', methods: ['GET'])]
    #[Route('/dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'counts'  => $this->listQuery->countByStatus(),
            'metrics' => $this->listQuery->revenueMetrics(),
        ]);
    }
}
