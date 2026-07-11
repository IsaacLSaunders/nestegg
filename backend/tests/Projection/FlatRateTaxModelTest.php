<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Enum\AccountType;
use App\Projection\FlatRateTaxModel;
use PHPUnit\Framework\TestCase;

final class FlatRateTaxModelTest extends TestCase
{
    private FlatRateTaxModel $model;

    protected function setUp(): void
    {
        $this->model = new FlatRateTaxModel(0.25, 0.15);
    }

    public function testTraditionalTaxedAtOrdinaryRate(): void
    {
        self::assertEqualsWithDelta(750.0, $this->model->netFromGross(AccountType::Traditional401k, 1000.0, 0.0), 0.001);
        self::assertEqualsWithDelta(1000.0, $this->model->grossFromNet(AccountType::TraditionalIra, 750.0, 0.0), 0.001);
    }

    public function testRothAnd529AndCashUntaxed(): void
    {
        foreach ([AccountType::Roth401k, AccountType::RothIra, AccountType::Plan529, AccountType::Cash] as $type) {
            self::assertSame(1000.0, $this->model->netFromGross($type, 1000.0, 0.5));
            self::assertSame(1000.0, $this->model->grossFromNet($type, 1000.0, 0.5));
        }
    }

    public function testBrokerageTaxesOnlyGainsFraction(): void
    {
        // 40% of the withdrawal is gains: tax = 1000 * 0.4 * 0.15 = 60.
        self::assertEqualsWithDelta(940.0, $this->model->netFromGross(AccountType::Brokerage, 1000.0, 0.4), 0.001);
        // Inverse: gross = 940 / (1 - 0.4*0.15) = 1000.
        self::assertEqualsWithDelta(1000.0, $this->model->grossFromNet(AccountType::Brokerage, 940.0, 0.4), 0.001);
    }
}
