<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GoalSeekRequest;
use App\Dto\ProjectionRequest;
use App\Projection\Goal\DrawdownGoal;
use App\Projection\Goal\Goal;
use App\Projection\Goal\TargetValueGoal;
use App\Projection\GoalSeeker;
use App\Projection\MonthIndexMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class GoalSeekController extends ApiController
{
    #[Route('/api/goal-seek', name: 'api_goal_seek', methods: ['POST'])]
    public function solve(#[MapRequestPayload] GoalSeekRequest $request): JsonResponse
    {
        $start = ProjectionController::firstOfMonth(
            null !== $request->startsOn ? new \DateTimeImmutable($request->startsOn) : new \DateTimeImmutable('now'),
        );

        $projectionRequest = new ProjectionRequest(
            account: $request->account,
            taxes: $request->taxes,
            birthDate: $request->birthDate,
            deathAge: $request->deathAge,
            startsOn: $request->startsOn,
        );
        $assumptions = ProjectionController::buildAssumptions($projectionRequest, $start);

        $goal = $this->buildGoal($request, $assumptions->horizonMonths, $assumptions->deathMonthIndex, $assumptions->annualInflationRate, $start, $assumptions->drawdown?->startMonthIndex, $assumptions->drawdown?->endMonthIndex, null !== $assumptions->drawdown);

        $result = (new GoalSeeker())->solve($assumptions, $goal);

        return $this->apiJson([
            'attainable' => $result->attainable,
            'requiredMonthlyContribution' => $result->requiredMonthlyContribution,
            'requiredYearlyContribution' => round($result->requiredMonthlyContribution * 12, 2),
            'projection' => ProjectionController::serialize($result->projection, $start, $assumptions->annualInflationRate),
        ]);
    }

    private function buildGoal(
        GoalSeekRequest $request,
        int $horizonMonths,
        ?int $deathMonthIndex,
        float $annualInflationRate,
        \DateTimeImmutable $start,
        ?int $drawdownStart,
        ?int $drawdownEnd,
        bool $hasDrawdown,
    ): Goal {
        if ('drawdown' === $request->goal->kind) {
            if (!$hasDrawdown) {
                throw new UnprocessableEntityHttpException('A drawdown goal requires the account to define a drawdown amount and start.');
            }
            if ($drawdownStart >= $horizonMonths) {
                throw new UnprocessableEntityHttpException('The drawdown window starts outside the projection horizon — extend horizonYears or move the drawdown start.');
            }
            if (null !== $deathMonthIndex && $deathMonthIndex < $drawdownStart) {
                throw new UnprocessableEntityHttpException('The assumed death age falls before the drawdown starts.');
            }
            $surviveThrough = $drawdownEnd ?? $deathMonthIndex ?? ($horizonMonths - 1);

            return new DrawdownGoal(min($surviveThrough, $horizonMonths - 1));
        }

        if (null === $request->goal->amount || null === $request->goal->atDate) {
            throw new UnprocessableEntityHttpException('A target_value goal requires amount and atDate.');
        }
        $atIndex = MonthIndexMapper::indexOf($start, new \DateTimeImmutable($request->goal->atDate));
        if ($atIndex < 0 || $atIndex >= $horizonMonths) {
            throw new UnprocessableEntityHttpException('atDate is outside the projection horizon.');
        }

        $nominal = $request->goal->amount;
        if ($request->goal->amountInTodaysDollars) {
            $monthlyInflation = (1 + $annualInflationRate) ** (1 / 12) - 1;
            $nominal *= (1 + $monthlyInflation) ** ($atIndex + 1);
        }

        return new TargetValueGoal($nominal, $atIndex);
    }
}
