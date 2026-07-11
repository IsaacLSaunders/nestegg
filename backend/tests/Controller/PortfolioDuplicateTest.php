<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class PortfolioDuplicateTest extends ApiTestCase
{
    public function testDuplicateClonesPortfolioAndAccounts(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Original',
            'ordinaryIncomeTaxRate' => 0.24,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $pid = $this->json($client)['id'];
        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", [
            'name' => 'My 401k',
            'type' => 'traditional_401k',
            'startingBalance' => 10000.0,
            'annualReturnRate' => 0.07,
            'inflationRate' => 0.03,
            'horizonYears' => 30,
        ]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', "/api/portfolios/{$pid}/duplicate");
        self::assertResponseStatusCodeSame(201);
        $copy = $this->json($client);
        self::assertSame('Original (copy)', $copy['name']);
        self::assertSame(0.24, $copy['ordinaryIncomeTaxRate']);
        self::assertNotSame($pid, $copy['id']);
        self::assertCount(1, $copy['accounts']);
        self::assertSame('My 401k', $copy['accounts'][0]['name']);
        self::assertNotNull($copy['accounts'][0]['id']);

        $client->jsonRequest('GET', '/api/portfolios');
        self::assertCount(2, $this->json($client));
    }

    public function testCannotDuplicateOthersPortfolio(): void
    {
        $alice = $this->createAuthenticatedClient('alice3@example.com');
        $alice->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Private',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $pid = $this->json($alice)['id'];

        $bob = $this->createAuthenticatedClient('bob3@example.com');
        $bob->jsonRequest('POST', "/api/portfolios/{$pid}/duplicate");
        self::assertResponseStatusCodeSame(404);
    }

    public function testDuplicateClampsLongNameToFitColumn(): void
    {
        $client = $this->createAuthenticatedClient();
        $longName = str_repeat('x', 120);
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => $longName,
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $pid = $this->json($client)['id'];

        $client->jsonRequest('POST', "/api/portfolios/{$pid}/duplicate");
        self::assertResponseStatusCodeSame(201);
        $copy = $this->json($client);
        self::assertLessThanOrEqual(120, mb_strlen($copy['name']));
        self::assertStringEndsWith(' (copy)', $copy['name']);
    }
}
