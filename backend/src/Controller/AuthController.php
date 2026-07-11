<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        if (null !== $users->findOneBy(['email' => $request->email])) {
            return $this->json(['error' => 'Email already registered.'], 409);
        }

        $user = new User();
        $user->setEmail($request->email)
            ->setBirthDate(new \DateTimeImmutable($request->birthDate))
            ->setDeathAge($request->deathAge)
            ->setPassword($hasher->hashPassword($user, $request->password));

        $em->persist($user);
        $em->flush();

        return $this->json($user->toJson(), 201);
    }
}
