<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AccountInput;
use App\Entity\Account;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\PortfolioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    /**
     * AbstractController::json() always forces 'json_encode_options' to
     * JsonResponse::DEFAULT_ENCODING_OPTIONS in the serializer context, which
     * overrides the serializer's built-in JSON_PRESERVE_ZERO_FRACTION default.
     * Without this, a whole-number float like 1500.0 serializes as `1500`
     * instead of `1500.0`. Re-add the flag explicitly so numeric fields keep
     * their float shape in the response.
     */
    private const JSON_CONTEXT = ['json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION];

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PortfolioRepository $portfolios,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/portfolios/{portfolioId}/accounts', name: 'api_accounts_create', methods: ['POST'], requirements: ['portfolioId' => '\d+'])]
    public function create(int $portfolioId, #[MapRequestPayload] AccountInput $input): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $portfolio = $this->portfolios->findOneOwnedBy($portfolioId, $user);
        if (null === $portfolio) {
            throw $this->createNotFoundException();
        }

        $account = new Account();
        $account->setPortfolio($portfolio);
        $this->apply($account, $input);

        $this->em->persist($account);
        $this->em->flush();

        return $this->json($account->toJson(), 201, [], self::JSON_CONTEXT);
    }

    #[Route('/api/accounts/{id}', name: 'api_accounts_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->findOwnedOr404($id)->toJson(), 200, [], self::JSON_CONTEXT);
    }

    #[Route('/api/accounts/{id}', name: 'api_accounts_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] AccountInput $input): JsonResponse
    {
        $account = $this->findOwnedOr404($id);
        $this->apply($account, $input);
        $this->em->flush();

        return $this->json($account->toJson(), 200, [], self::JSON_CONTEXT);
    }

    #[Route('/api/accounts/{id}', name: 'api_accounts_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->em->remove($this->findOwnedOr404($id));
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function apply(Account $account, AccountInput $input): void
    {
        $account
            ->setName($input->name)
            ->setType($input->type)
            ->setStartingBalance($input->startingBalance)
            ->setStartingBasis($input->startingBasis)
            ->setAnnualReturnRate($input->annualReturnRate)
            ->setInflationRate($input->inflationRate)
            ->setHorizonYears($input->horizonYears)
            ->setContributionMonthlyAmount($input->contribution->monthlyAmount)
            ->setContributionEscalationRate($input->contribution->escalationRate)
            ->setContributionStartsOn(self::date($input->contribution->startsOn))
            ->setContributionEndsOn(self::date($input->contribution->endsOn))
            ->setDrawdownAmount($input->drawdown->amount)
            ->setDrawdownFrequency($input->drawdown->frequency)
            ->setDrawdownEntryMode($input->drawdown->entryMode)
            ->setDrawdownStartsOn(self::date($input->drawdown->startsOn))
            ->setDrawdownEndsOn(self::date($input->drawdown->endsOn))
            ->setDrawdownInflationIndexed($input->drawdown->inflationIndexed);
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }

    private function findOwnedOr404(int $id): Account
    {
        /** @var User $user */
        $user = $this->getUser();
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if (null === $account) {
            throw $this->createNotFoundException();
        }

        return $account;
    }
}
