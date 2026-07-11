<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected function createAuthenticatedClient(string $email = 'owner@example.com'): KernelBrowser
    {
        self::ensureKernelShutdown(); // allows multi-client tests (e.g. alice + bob)
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => $email,
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
        ]);
        self::assertResponseStatusCodeSame(201);
        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        return $client;
    }

    /** @return array<mixed> */
    protected function json(KernelBrowser $client): array
    {
        return json_decode($client->getResponse()->getContent(), true);
    }
}
