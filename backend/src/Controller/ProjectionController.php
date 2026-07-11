<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AccountInput;
use App\Dto\ProjectionRequest;
use App\Enum\DrawdownFrequency;
use App\Projection\ContributionSchedule;
use App\Projection\DrawdownSchedule;
use App\Projection\MonthIndexMapper;
use App\Projection\ProjectionAssumptions;
use App\Projection\ProjectionResult;
use App\Projection\Projector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectionController extends ApiController
{
    #[Route('/api/projection', name: 'api_projection', methods: ['POST'])]
    public function project(#[MapRequestPayload] ProjectionRequest $request): JsonResponse
    {
        $start = self::firstOfMonth(
            null !== $request->startsOn ? new \DateTimeImmutable($request->startsOn) : new \DateTimeImmutable('now'),
        );

        $assumptions = self::buildAssumptions($request, $start);
        $result = (new Projector())->project($assumptions);

        return $this->apiJson(self::serialize($result, $start, $assumptions->annualInflationRate));
    }

    public static function firstOfMonth(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->modify('first day of this month')->setTime(0, 0);
    }

    public static function buildAssumptions(ProjectionRequest $request, \DateTimeImmutable $start): ProjectionAssumptions
    {
        $account = $request->account;
        $horizonMonths = $account->horizonYears * 12;

        $toIndex = static fn (?string $date): ?int => null === $date
            ? null
            : MonthIndexMapper::indexOf($start, new \DateTimeImmutable($date));

        $clamp = static fn (?int $i): ?int => null === $i ? null : max(0, $i);

        $contribution = new ContributionSchedule(
            monthlyAmount: $account->contribution->monthlyAmount,
            annualEscalationRate: $account->contribution->escalationRate,
            startMonthIndex: $clamp($toIndex($account->contribution->startsOn)),
            endMonthIndex: $toIndex($account->contribution->endsOn),
        );

        $drawdown = null;
        if (null !== $account->drawdown->amount && null !== $account->drawdown->startsOn) {
            $monthlyAmount = DrawdownFrequency::Weekly === $account->drawdown->frequency
                ? $account->drawdown->amount * 52 / 12
                : $account->drawdown->amount;
            $drawdown = new DrawdownSchedule(
                monthlyAmountToday: $monthlyAmount,
                entryMode: $account->drawdown->entryMode,
                startMonthIndex: max(0, (int) $toIndex($account->drawdown->startsOn)),
                endMonthIndex: $toIndex($account->drawdown->endsOn),
                inflationIndexed: $account->drawdown->inflationIndexed,
            );
        }

        $deathMonthIndex = null;
        if (null !== $request->birthDate && null !== $request->deathAge) {
            $deathDate = (new \DateTimeImmutable($request->birthDate))->modify(sprintf('+%d years', $request->deathAge));
            $deathMonthIndex = MonthIndexMapper::indexOf($start, $deathDate);
        }

        return new ProjectionAssumptions(
            horizonMonths: $horizonMonths,
            accountType: $account->type,
            startingBalance: $account->startingBalance,
            startingBasis: $account->startingBasis,
            annualReturnRate: $account->annualReturnRate,
            annualInflationRate: $account->inflationRate,
            ordinaryIncomeTaxRate: $request->taxes->ordinaryIncomeTaxRate,
            capitalGainsTaxRate: $request->taxes->capitalGainsTaxRate,
            contribution: $contribution,
            drawdown: $drawdown,
            deathMonthIndex: $deathMonthIndex,
        );
    }

    /** @return array<string, mixed> */
    public static function serialize(ProjectionResult $result, \DateTimeImmutable $start, float $annualInflationRate): array
    {
        $monthlyInflation = (1 + $annualInflationRate) ** (1 / 12) - 1;
        $months = [];
        foreach ($result->months as $m) {
            $months[] = [
                'index' => $m->index,
                'date' => $start->modify(sprintf('+%d months', $m->index))->format('Y-m'),
                'balance' => round($m->balance, 2),
                'realBalance' => round($m->balance / (1 + $monthlyInflation) ** ($m->index + 1), 2),
                'basis' => round($m->basis, 2),
                'contribution' => round($m->contribution, 2),
                'grossWithdrawal' => round($m->grossWithdrawal, 2),
                'netWithdrawal' => round($m->netWithdrawal, 2),
                'taxPaid' => round($m->taxPaid, 2),
            ];
        }

        $s = $result->summary;
        $lastIndex = [] === $months ? 0 : $result->months[array_key_last($result->months)]->index;

        return [
            'months' => $months,
            'summary' => [
                'endingBalance' => round($s->endingBalance, 2),
                'endingRealBalance' => round($s->endingBalance / (1 + $monthlyInflation) ** ($lastIndex + 1), 2),
                'depletionDate' => null === $s->depletionMonthIndex
                    ? null
                    : $start->modify(sprintf('+%d months', $s->depletionMonthIndex))->format('Y-m'),
                'totalContributions' => round($s->totalContributions, 2),
                'totalGrossWithdrawals' => round($s->totalGrossWithdrawals, 2),
                'totalNetWithdrawals' => round($s->totalNetWithdrawals, 2),
                'totalTaxPaid' => round($s->totalTaxPaid, 2),
            ],
        ];
    }
}
