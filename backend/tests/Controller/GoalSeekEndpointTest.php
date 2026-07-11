<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class GoalSeekEndpointTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $goal): array
    {
        return [
            'startsOn' => '2026-07-01',
            'birthDate' => '1990-06-15',
            'deathAge' => 90,
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'goal' => $goal,
            'account' => [
                'name' => 'goal',
                'type' => 'roth_ira',
                'startingBalance' => 0.0,
                'annualReturnRate' => 0.0,
                'inflationRate' => 0.0,
                'horizonYears' => 15,
                'contribution' => ['monthlyAmount' => 0.0, 'startsOn' => '2026-07-01', 'endsOn' => '2036-06-01'],
                'drawdown' => [
                    'amount' => 1000.0,
                    'frequency' => 'monthly',
                    'entryMode' => 'gross',
                    'startsOn' => '2036-07-01',
                    'endsOn' => '2041-06-01',
                    'inflationIndexed' => false,
                ],
            ],
        ];
    }

    public function testDrawdownGoalSolvesContribution(): void
    {
        // Save months 0..119, draw 1000/mo months 120..179 (Roth, no growth): need 500/mo.
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/goal-seek', $this->payload(['kind' => 'drawdown']));

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertTrue($data['attainable']);
        self::assertEqualsWithDelta(500.0, $data['requiredMonthlyContribution'], 1.0);
        self::assertEqualsWithDelta($data['requiredMonthlyContribution'] * 12, $data['requiredYearlyContribution'], 0.01);
        // Exactly-solved contribution may deplete precisely at the drawdown end month — both outcomes satisfy the goal.
        self::assertContains($data['projection']['summary']['depletionDate'], [null, '2041-06']);
        self::assertCount(180, $data['projection']['months']);
    }

    public function testTargetValueGoal(): void
    {
        $payload = $this->payload([
            'kind' => 'target_value',
            'amount' => 60000.0,
            'atDate' => '2036-06-01',
            'amountInTodaysDollars' => false,
        ]);
        $payload['account']['drawdown'] = ['amount' => null];

        $client = $this->createAuthenticatedClient('target@example.com');
        $client->jsonRequest('POST', '/api/goal-seek', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertTrue($data['attainable']);
        // Window is months 0..119 inclusive = 120 payments; 60000/120 = 500/mo.
        self::assertEqualsWithDelta(500.0, $data['requiredMonthlyContribution'], 1.0);
    }

    public function testDrawdownGoalWithoutDrawdownRejected(): void
    {
        $payload = $this->payload(['kind' => 'drawdown']);
        $payload['account']['drawdown'] = ['amount' => null];
        $client = $this->createAuthenticatedClient('nodraw@example.com');
        $client->jsonRequest('POST', '/api/goal-seek', $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTargetDateOutsideHorizonRejected(): void
    {
        $payload = $this->payload([
            'kind' => 'target_value',
            'amount' => 1000.0,
            'atDate' => '2099-01-01',
            'amountInTodaysDollars' => false,
        ]);
        $client = $this->createAuthenticatedClient('outside@example.com');
        $client->jsonRequest('POST', '/api/goal-seek', $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuth(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/goal-seek', $this->payload(['kind' => 'drawdown']));
        self::assertResponseStatusCodeSame(401);
    }
}
