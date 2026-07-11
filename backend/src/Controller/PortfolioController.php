<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PortfolioInput;
use App\Entity\Portfolio;
use App\Entity\User;
use App\Repository\PortfolioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/portfolios')]
final class PortfolioController extends AbstractController
{
    public function __construct(
        private readonly PortfolioRepository $portfolios,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_portfolios_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(array_map(
            static fn (Portfolio $p): array => $p->toJson(),
            $this->portfolios->findOwnedBy($user),
        ));
    }

    #[Route('', name: 'api_portfolios_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] PortfolioInput $input): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $portfolio = (new Portfolio())
            ->setOwner($user)
            ->setName($input->name)
            ->setOrdinaryIncomeTaxRate($input->ordinaryIncomeTaxRate)
            ->setCapitalGainsTaxRate($input->capitalGainsTaxRate);

        $this->em->persist($portfolio);
        $this->em->flush();

        return $this->json($portfolio->toJson(), 201);
    }

    #[Route('/{id}', name: 'api_portfolios_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->findOwnedOr404($id)->toJson());
    }

    #[Route('/{id}', name: 'api_portfolios_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] PortfolioInput $input): JsonResponse
    {
        $portfolio = $this->findOwnedOr404($id)
            ->setName($input->name)
            ->setOrdinaryIncomeTaxRate($input->ordinaryIncomeTaxRate)
            ->setCapitalGainsTaxRate($input->capitalGainsTaxRate);
        $this->em->flush();

        return $this->json($portfolio->toJson());
    }

    #[Route('/{id}', name: 'api_portfolios_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->em->remove($this->findOwnedOr404($id));
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function findOwnedOr404(int $id): Portfolio
    {
        /** @var User $user */
        $user = $this->getUser();
        $portfolio = $this->portfolios->findOneOwnedBy($id, $user);
        if (null === $portfolio) {
            throw $this->createNotFoundException();
        }

        return $portfolio;
    }
}
