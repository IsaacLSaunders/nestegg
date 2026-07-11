<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Portfolio;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Portfolio> */
final class PortfolioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Portfolio::class);
    }

    /** @return Portfolio[] */
    public function findOwnedBy(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['id' => 'ASC']);
    }

    public function findOneOwnedBy(int $id, User $owner): ?Portfolio
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }
}
