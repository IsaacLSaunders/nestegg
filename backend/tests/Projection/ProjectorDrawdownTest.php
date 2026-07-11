<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Projection\ContributionSchedule;
use App\Projection\DrawdownSchedule;
use App\Projection\ProjectionAssumptions;
use App\Projection\Projector;
use PHPUnit\Framework\TestCase;

final class ProjectorDrawdownTest extends TestCase
{
    private function assumptions(
        AccountType $type,
        float $startingBalance,
        DrawdownSchedule $drawdown,
        float $ordinaryRate = 0.25,
        float $capGainsRate = 0.15,
        float $annualReturnRate = 0.0,
        float $annualInflationRate = 0.0,
        ?float $startingBasis = null,
        ?int $deathMonthIndex = null,
        int $horizonMonths = 12,
    ): ProjectionAssumptions {
        return new ProjectionAssumptions(
            horizonMonths: $horizonMonths,
            accountType: $type,
            startingBalance: $startingBalance,
            startingBasis: $startingBasis,
            annualReturnRate: $annualReturnRate,
            annualInflationRate: $annualInflationRate,
            ordinaryIncomeTaxRate: $ordinaryRate,
            capitalGainsTaxRate: $capGainsRate,
            contribution: new ContributionSchedule(0.0),
            drawdown: $drawdown,
            deathMonthIndex: $deathMonthIndex,
        );
    }

    public function testGrossDrawdownFromTraditional(): void
    {
        // 12000 balance, withdraw 1000 gross monthly, 25% ordinary: net 750/mo, depletes exactly at month 11.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Traditional401k,
            12000.0,
            new DrawdownSchedule(1000.0, DrawdownEntryMode::Gross, 0),
        ));
        self::assertEqualsWithDelta(1000.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(750.0, $result->months[0]->netWithdrawal, 0.01);
        self::assertEqualsWithDelta(250.0, $result->months[0]->taxPaid, 0.01);
        self::assertSame(11, $result->summary->depletionMonthIndex);
        self::assertEqualsWithDelta(0.0, $result->summary->endingBalance, 0.01);
        self::assertEqualsWithDelta(12000.0, $result->summary->totalGrossWithdrawals, 0.01);
        self::assertEqualsWithDelta(9000.0, $result->summary->totalNetWithdrawals, 0.01);
        self::assertEqualsWithDelta(3000.0, $result->summary->totalTaxPaid, 0.01);
    }

    public function testNetEntryModeGrossesUp(): void
    {
        // Want 750 net from traditional at 25%: gross withdrawal must be 1000.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Traditional401k,
            12000.0,
            new DrawdownSchedule(750.0, DrawdownEntryMode::Net, 0),
        ));
        self::assertEqualsWithDelta(1000.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(750.0, $result->months[0]->netWithdrawal, 0.01);
    }

    public function testRothDrawdownUntaxed(): void
    {
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            1200.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Net, 0),
        ));
        self::assertEqualsWithDelta(100.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertSame(0.0, $result->months[0]->taxPaid);
    }

    public function testBrokerageBasisProportionalReduction(): void
    {
        // Balance 1000, basis 600 → gainsFraction 0.4. Withdraw 100 gross:
        // tax = 100*0.4*0.15 = 6, net 94; basis -= 100*600/1000 = 60 → 540; balance 900.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Brokerage,
            1000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
            startingBasis: 600.0,
            horizonMonths: 1,
        ));
        self::assertEqualsWithDelta(94.0, $result->months[0]->netWithdrawal, 0.01);
        self::assertEqualsWithDelta(6.0, $result->months[0]->taxPaid, 0.01);
        self::assertEqualsWithDelta(540.0, $result->months[0]->basis, 0.01);
        self::assertEqualsWithDelta(900.0, $result->months[0]->balance, 0.01);
    }

    public function testBrokerageAllBasisNoTax(): void
    {
        // basis == balance → gainsFraction 0 → no tax.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Brokerage,
            1000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
            startingBasis: 1000.0,
            horizonMonths: 1,
        ));
        self::assertSame(0.0, $result->months[0]->taxPaid);
    }

    public function testInflationIndexedDrawdownGrows(): void
    {
        // 3% inflation, indexed: month 12 target = 100 * (1.03)^(12/12) = 103.
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            100000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
            annualInflationRate: 0.03,
            horizonMonths: 13,
        ));
        self::assertEqualsWithDelta(100.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(103.0, $result->months[12]->grossWithdrawal, 0.01);
    }

    public function testUnindexedDrawdownStaysFlat(): void
    {
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            100000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0, null, false),
            annualInflationRate: 0.03,
            horizonMonths: 13,
        ));
        self::assertEqualsWithDelta(100.0, $result->months[12]->grossWithdrawal, 0.01);
    }

    public function testDrawdownWindowAndDeathBound(): void
    {
        // Start month 2, explicit end month 4; death at month 3 cuts it earlier.
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            10000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 2, 4),
            deathMonthIndex: 3,
        ));
        self::assertSame(0.0, $result->months[1]->grossWithdrawal);
        self::assertEqualsWithDelta(100.0, $result->months[2]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(100.0, $result->months[3]->grossWithdrawal, 0.01);
        self::assertSame(0.0, $result->months[4]->grossWithdrawal);
    }

    public function testPartialFinalWithdrawalMarksDepletion(): void
    {
        // 250 balance, 100/mo gross: months 0,1 full; month 2 partial (50); depletion at month 2.
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            250.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
        ));
        self::assertEqualsWithDelta(50.0, $result->months[2]->grossWithdrawal, 0.01);
        self::assertSame(2, $result->summary->depletionMonthIndex);
        self::assertSame(0.0, $result->months[3]->grossWithdrawal);
        self::assertEqualsWithDelta(0.0, $result->summary->endingBalance, 0.01);
    }

    public function testDrawdownFromEmptyAccountRecordsImmediateDepletion(): void
    {
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            0.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
        ));
        self::assertSame(0, $result->summary->depletionMonthIndex);
        self::assertSame(0.0, $result->months[0]->grossWithdrawal);
        self::assertSame(0.0, $result->summary->endingBalance);
    }
}
