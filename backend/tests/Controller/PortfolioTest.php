<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class PortfolioTest extends ApiTestCase
{
    public function testCreateAndListPortfolios(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Aggressive path',
            'ordinaryIncomeTaxRate' => 0.24,
            'capitalGainsTaxRate' => 0.15,
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->json($client);
        self::assertSame('Aggressive path', $created['name']);
        self::assertSame(0.24, $created['ordinaryIncomeTaxRate']);
        self::assertSame([], $created['accounts']);

        $client->jsonRequest('GET', '/api/portfolios');
        self::assertResponseIsSuccessful();
        $list = $this->json($client);
        self::assertCount(1, $list);
        self::assertSame($created['id'], $list[0]['id']);
    }

    public function testUpdatePortfolio(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Before',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $id = $this->json($client)['id'];

        $client->jsonRequest('PUT', "/api/portfolios/{$id}", [
            'name' => 'After',
            'ordinaryIncomeTaxRate' => 0.32,
            'capitalGainsTaxRate' => 0.20,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('After', $this->json($client)['name']);
        self::assertSame(0.32, $this->json($client)['ordinaryIncomeTaxRate']);
    }

    public function testDeletePortfolio(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Doomed',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $id = $this->json($client)['id'];

        $client->jsonRequest('DELETE', "/api/portfolios/{$id}");
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', "/api/portfolios/{$id}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testCannotSeeOthersPortfolios(): void
    {
        $alice = $this->createAuthenticatedClient('alice@example.com');
        $alice->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Alice private',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $aliceId = $this->json($alice)['id'];

        $bob = $this->createAuthenticatedClient('bob@example.com');
        $bob->jsonRequest('GET', "/api/portfolios/{$aliceId}");
        self::assertResponseStatusCodeSame(404);

        $bob->jsonRequest('GET', '/api/portfolios');
        self::assertSame([], $this->json($bob));
    }

    public function testValidationRejectsOutOfRangeRates(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Bad rates',
            'ordinaryIncomeTaxRate' => 24.0,
            'capitalGainsTaxRate' => 0.15,
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
