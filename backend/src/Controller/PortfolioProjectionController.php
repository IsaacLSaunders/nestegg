<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AccountInput;
use App\Dto\PortfolioProjectionRequest;
use App\Dto\ProjectionRequest;
use App\Projection\Projector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class PortfolioProjectionController extends ApiController
{
    #[Route('/api/projection/portfolio', name: 'api_projection_portfolio', methods: ['POST'])]
    public function project(#[MapRequestPayload] PortfolioProjectionRequest $request): JsonResponse
    {
        $start = ProjectionController::firstOfMonth(
            null !== $request->startsOn ? new \DateTimeImmutable($request->startsOn) : new \DateTimeImmutable('now'),
        );

        $maxHorizonYears = max(array_map(static fn (AccountInput $a): int => $a->horizonYears, $request->accounts));

        $projector = new Projector();
        $accountsOut = [];
        $sums = [];
        foreach ($request->accounts as $account) {
            $stretched = self::withHorizon($account, $maxHorizonYears);
            $projectionRequest = new ProjectionRequest(
                account: $stretched,
                taxes: $request->taxes,
                birthDate: $request->birthDate,
                deathAge: $request->deathAge,
                startsOn: $request->startsOn,
            );
            $assumptions = ProjectionController::buildAssumptions($projectionRequest, $start);
            $serialized = ProjectionController::serialize($projector->project($assumptions), $start, $assumptions->annualInflationRate);
            $accountsOut[] = ['name' => $account->name, ...$serialized];

            foreach ($serialized['months'] as $month) {
                $sums[$month['index']]['balance'] = ($sums[$month['index']]['balance'] ?? 0.0) + $month['balance'];
            }
        }

        // Portfolio-level real-dollar deflator: v1 uses the first account's inflation
        // rate (accounts may disagree; the overview needs one deflator).
        $monthlyInflation = (1 + $request->accounts[0]->inflationRate) ** (1 / 12) - 1;
        $totalMonths = [];
        foreach ($sums as $index => $sum) {
            $totalMonths[] = [
                'index' => $index,
                'date' => $start->modify(sprintf('+%d months', $index))->format('Y-m'),
                'balance' => round($sum['balance'], 2),
                'realBalance' => round($sum['balance'] / (1 + $monthlyInflation) ** ($index + 1), 2),
            ];
        }

        return $this->apiJson([
            'accounts' => $accountsOut,
            'total' => ['months' => $totalMonths, 'horizonMonths' => $maxHorizonYears * 12],
        ]);
    }

    private static function withHorizon(AccountInput $a, int $horizonYears): AccountInput
    {
        return new AccountInput(
            name: $a->name,
            type: $a->type,
            startingBalance: $a->startingBalance,
            startingBasis: $a->startingBasis,
            annualReturnRate: $a->annualReturnRate,
            inflationRate: $a->inflationRate,
            horizonYears: $horizonYears,
            contribution: $a->contribution,
            drawdown: $a->drawdown,
        );
    }
}
