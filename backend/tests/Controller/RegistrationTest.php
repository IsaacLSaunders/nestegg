<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationTest extends WebTestCase
{
    public function testRegisterCreatesUser(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'isaac@example.com',
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
            'deathAge' => 92,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('isaac@example.com', $data['email']);
        self::assertSame('1990-06-15', $data['birthDate']);
        self::assertSame(92, $data['deathAge']);
        self::assertArrayHasKey('id', $data);
        self::assertArrayNotHasKey('password', $data);
    }

    public function testDeathAgeDefaultsTo90(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'default@example.com',
            'password' => 'correct horse battery staple',
            'birthDate' => '1985-01-01',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(90, $data['deathAge']);
    }

    public function testDuplicateEmailRejected(): void
    {
        $client = self::createClient();
        $payload = [
            'email' => 'dupe@example.com',
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
        ];
        $client->jsonRequest('POST', '/api/auth/register', $payload);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/auth/register', $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testInvalidPayloadRejected(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'not-an-email',
            'password' => 'short',
            'birthDate' => '1990-06-15',
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
