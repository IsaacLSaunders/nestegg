<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UpdateMeRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
final class MeController extends ApiController
{
    #[Route('', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->apiJson($user->toJson());
    }

    #[Route('', name: 'api_me_update', methods: ['PATCH'])]
    public function update(
        #[MapRequestPayload] UpdateMeRequest $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $request->birthDate) {
            $user->setBirthDate(new \DateTimeImmutable($request->birthDate));
        }
        if (null !== $request->deathAge) {
            $user->setDeathAge($request->deathAge);
        }
        $em->flush();

        return $this->apiJson($user->toJson());
    }
}
