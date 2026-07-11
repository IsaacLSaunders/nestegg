<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class ProjectionEndpointTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'startsOn' => '2026-07-01',
            'birthDate' => '1990-06-15',
            'deathAge' => 90,
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'account' => [
                'name' => 'preview',
                'type' => 'traditional_401k',
                'startingBalance' => 12000.0,
                'annualReturnRate' => 0.0,
                'inflationRate' => 0.0,
                'horizonYears' => 1,
                'contribution' => ['monthlyAmount' => 0.0],
                'drawdown' => [
                    'amount' => 1000.0,
                    'frequency' => 'monthly',
                    'entryMode' => 'gross',
                    'startsOn' => '2026-07-01',
                    'inflationIndexed' => false,
                ],
            ],
        ];
    }

    public function testProjectionMatchesEngineSemantics(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/projection', $this->payload());

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertCount(12, $data['months']);
        self::assertSame('2026-07', $data['months'][0]['date']);
        self::assertEqualsWithDelta(1000.0, $data['months'][0]['grossWithdrawal'], 0.01);
        self::assertEqualsWithDelta(750.0, $data['months'][0]['netWithdrawal'], 0.01);
        self::assertSame('2027-06', $data['summary']['depletionDate']);
        self::assertEqualsWithDelta(0.0, $data['summary']['endingBalance'], 0.01);
    }

    public function testWeeklyDrawdownConverted(): void
    {
        $payload = $this->payload();
        $payload['account']['drawdown']['amount'] = 230.77; // ~1000.04/mo at x52/12
        $payload['account']['drawdown']['frequency'] = 'weekly';
        $client = $this->createAuthenticatedClient('weekly@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertEqualsWithDelta(230.77 * 52 / 12, $data['months'][0]['grossWithdrawal'], 0.01);
    }

    public function testRealBalanceDeflated(): void
    {
        $payload = $this->payload();
        $payload['account']['drawdown'] = ['amount' => null];
        $payload['account']['inflationRate'] = 0.03;
        $payload['account']['annualReturnRate'] = 0.03;
        // Growth exactly offsets inflation: real balance stays ~12000 every month.
        $client = $this->createAuthenticatedClient('real@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertEqualsWithDelta(12000.0, $data['months'][11]['realBalance'], 0.5);
        self::assertGreaterThan(12300.0, $data['months'][11]['balance']);
    }

    public function testDeathAgeBoundsDrawdown(): void
    {
        // Death age reached during the horizon stops withdrawals after that month.
        $payload = $this->payload();
        $payload['birthDate'] = '1937-01-15'; // ~89.5 years old at startsOn; death age 90 hits mid-horizon
        $client = $this->createAuthenticatedClient('death@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        $last = $data['months'][11];
        self::assertSame(0.0, $last['grossWithdrawal']);
    }

    public function testRealDollarFlowSeriesDeflatesInflationIndexedWithdrawal(): void
    {
        $payload = $this->payload();
        $payload['account']['startingBalance'] = 100000.0;
        $payload['account']['horizonYears'] = 2;
        $payload['account']['inflationRate'] = 0.03;
        $payload['account']['drawdown']['inflationIndexed'] = true;
        $client = $this->createAuthenticatedClient('real-flows@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        // Nominal withdrawal at month index 12 has grown by one year of 3% inflation.
        self::assertEqualsWithDelta(1030.00, $data['months'][12]['grossWithdrawal'], 0.01);
        // Deflated back to today's dollars, it's exactly the entered 1000/mo.
        self::assertEqualsWithDelta(1000.00, $data['months'][12]['realGrossWithdrawal'], 0.01);
    }

    public function testRealContributionEqualsNominalWhenNoInflation(): void
    {
        $payload = $this->payload();
        $payload['account']['drawdown'] = ['amount' => null];
        $payload['account']['inflationRate'] = 0.0;
        $payload['account']['contribution'] = ['monthlyAmount' => 500.0];
        $client = $this->createAuthenticatedClient('real-contribution@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertSame($data['months'][0]['contribution'], $data['months'][0]['realContribution']);
    }

    public function testZeroAmountDrawdownDoesNotCountAsActive(): void
    {
        $payload = $this->payload();
        $payload['account']['startingBalance'] = 0.0;
        $payload['account']['drawdown']['amount'] = 0.0;
        $client = $this->createAuthenticatedClient('zero-drawdown@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertNull($data['summary']['depletionDate']);
    }

    public function testRequiresAuth(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/projection', $this->payload());
        self::assertResponseStatusCodeSame(401);
    }

    public function testValidationErrors(): void
    {
        $payload = $this->payload();
        $payload['account']['horizonYears'] = 0;
        $client = $this->createAuthenticatedClient('invalid@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);
        self::assertResponseStatusCodeSame(422);
    }
}
