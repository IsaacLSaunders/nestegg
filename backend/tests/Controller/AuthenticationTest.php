<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    private function register(KernelBrowser $client, string $email = 'auth@example.com'): void
    {
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => $email,
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testLoginThenMe(): void
    {
        $client = self::createClient();
        $this->register($client);

        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'auth@example.com',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('auth@example.com', $data['email']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $client = self::createClient();
        $this->register($client, 'wrongpw@example.com');

        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'wrongpw@example.com',
            'password' => 'incorrect password entirely',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testMeWithoutSessionIs401Json(): void
    {
        $client = self::createClient();
        $client->jsonRequest('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testLogoutEndsSession(): void
    {
        $client = self::createClient();
        $this->register($client, 'logout@example.com');
        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/api/auth/logout');
        self::assertResponseIsSuccessful();

        $client->jsonRequest('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testPatchMeUpdatesPlanningFields(): void
    {
        $client = self::createClient();
        $this->register($client, 'patch@example.com');
        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'patch@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $client->jsonRequest('PATCH', '/api/me', ['deathAge' => 100, 'birthDate' => '1991-01-01']);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(100, $data['deathAge']);
        self::assertSame('1991-01-01', $data['birthDate']);
    }
}
