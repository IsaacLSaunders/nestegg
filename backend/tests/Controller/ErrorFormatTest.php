<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class ErrorFormatTest extends ApiTestCase
{
    public function testValidationErrorIsJsonEvenWithoutAcceptHeader(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/portfolios',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'text/html',
            ],
            content: json_encode(['name' => '']),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('application/json', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testNotFoundErrorIsJsonEvenWithoutAcceptHeader(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/portfolios/999999',
            server: [
                'HTTP_ACCEPT' => 'text/html',
            ],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('application/json', $client->getResponse()->headers->get('Content-Type'));
    }
}
