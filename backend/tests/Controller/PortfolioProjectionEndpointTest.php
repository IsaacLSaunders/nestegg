<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class PortfolioProjectionEndpointTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function account(string $name, float $startingBalance, int $horizonYears): array
    {
        return [
            'name' => $name,
            'type' => 'roth_ira',
            'startingBalance' => $startingBalance,
            'annualReturnRate' => 0.0,
            'inflationRate' => 0.0,
            'horizonYears' => $horizonYears,
            'contribution' => ['monthlyAmount' => 0.0],
            'drawdown' => ['amount' => null],
        ];
    }

    public function testProjectsAllAccountsOverLongestHorizonAndSums(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/projection/portfolio', [
            'startsOn' => '2026-07-01',
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'accounts' => [
                $this->account('Short', 1000.0, 1),
                $this->account('Long', 2000.0, 2),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertCount(2, $data['accounts']);
        self::assertSame('Short', $data['accounts'][0]['name']);
        // Both accounts projected over the longest horizon (24 months).
        self::assertCount(24, $data['accounts'][0]['months']);
        self::assertCount(24, $data['accounts'][1]['months']);
        self::assertSame(24, $data['total']['horizonMonths']);
        // Total sums balances: 1000 + 2000, flat (no growth/flows).
        self::assertEqualsWithDelta(3000.0, $data['total']['months'][0]['balance'], 0.01);
        self::assertEqualsWithDelta(3000.0, $data['total']['months'][23]['balance'], 0.01);
        self::assertSame('2026-07', $data['total']['months'][0]['date']);
    }

    public function testEmptyAccountsRejected(): void
    {
        $client = $this->createAuthenticatedClient('empty@example.com');
        $client->jsonRequest('POST', '/api/projection/portfolio', [
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'accounts' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuth(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/projection/portfolio', ['accounts' => []]);
        self::assertResponseStatusCodeSame(401);
    }
}
