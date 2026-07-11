<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AccountTest extends ApiTestCase
{
    private function createPortfolio(KernelBrowser $client): int
    {
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Main',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->json($client)['id'];
    }

    /** @return array<string, mixed> */
    private function accountPayload(): array
    {
        return [
            'name' => 'My 401k',
            'type' => 'traditional_401k',
            'startingBalance' => 50000.0,
            'annualReturnRate' => 0.07,
            'inflationRate' => 0.03,
            'horizonYears' => 40,
            'contribution' => [
                'monthlyAmount' => 1500.0,
                'escalationRate' => 0.02,
                'startsOn' => null,
                'endsOn' => '2041-07-01',
            ],
            'drawdown' => [
                'amount' => 4000.0,
                'frequency' => 'monthly',
                'entryMode' => 'net',
                'startsOn' => '2041-07-01',
                'endsOn' => null,
                'inflationIndexed' => true,
            ],
        ];
    }

    public function testCreateAccountUnderPortfolio(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);

        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        self::assertResponseStatusCodeSame(201);
        $data = $this->json($client);
        self::assertSame('My 401k', $data['name']);
        self::assertSame('traditional_401k', $data['type']);
        self::assertSame($pid, $data['portfolioId']);
        self::assertSame(1500.0, $data['contribution']['monthlyAmount']);
        self::assertSame('2041-07-01', $data['drawdown']['startsOn']);
        self::assertNull($data['drawdown']['endsOn']);
        self::assertTrue($data['drawdown']['inflationIndexed']);

        $client->jsonRequest('GET', "/api/portfolios/{$pid}");
        $accounts = $this->json($client)['accounts'];
        self::assertCount(1, $accounts);
        // Regression: nested account floats must keep their zero fraction too.
        self::assertSame(1500.0, $accounts[0]['contribution']['monthlyAmount']);
    }

    public function testBrokerageAccountKeepsStartingBasis(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);

        $payload = $this->accountPayload();
        $payload['name'] = 'Taxable';
        $payload['type'] = 'brokerage';
        $payload['startingBasis'] = 30000.0;

        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $payload);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(30000.0, $this->json($client)['startingBasis']);
    }

    public function testUpdateAndDeleteAccount(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);
        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        $id = $this->json($client)['id'];

        $updated = $this->accountPayload();
        $updated['name'] = 'Renamed 401k';
        $updated['annualReturnRate'] = 0.05;
        $client->jsonRequest('PUT', "/api/accounts/{$id}", $updated);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed 401k', $this->json($client)['name']);
        self::assertSame(0.05, $this->json($client)['annualReturnRate']);

        $client->jsonRequest('DELETE', "/api/accounts/{$id}");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', "/api/accounts/{$id}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testCannotTouchOthersAccounts(): void
    {
        $alice = $this->createAuthenticatedClient('alice2@example.com');
        $pid = $this->createPortfolio($alice);
        $alice->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        $accountId = $this->json($alice)['id'];

        $bob = $this->createAuthenticatedClient('bob2@example.com');
        $bob->jsonRequest('GET', "/api/accounts/{$accountId}");
        self::assertResponseStatusCodeSame(404);
        $bob->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidEnumRejected(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);

        $payload = $this->accountPayload();
        $payload['type'] = 'mattress';
        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDrawdownAmountWithoutStartDateIsRejected(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);

        $payload = $this->accountPayload();
        $payload['drawdown']['amount'] = 4000.0;
        $payload['drawdown']['startsOn'] = null;
        $payload['drawdown']['endsOn'] = null;
        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $payload);
        self::assertResponseStatusCodeSame(422);
    }
}
