<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PortfolioRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortfolioRepository::class)]
class Portfolio
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private float $ordinaryIncomeTaxRate = 0.22;

    #[ORM\Column]
    private float $capitalGainsTaxRate = 0.15;

    /** @var Collection<int, Account> */
    #[ORM\OneToMany(targetEntity: Account::class, mappedBy: 'portfolio', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $accounts;

    public function __construct()
    {
        $this->accounts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getOrdinaryIncomeTaxRate(): float
    {
        return $this->ordinaryIncomeTaxRate;
    }

    public function setOrdinaryIncomeTaxRate(float $rate): static
    {
        $this->ordinaryIncomeTaxRate = $rate;

        return $this;
    }

    public function getCapitalGainsTaxRate(): float
    {
        return $this->capitalGainsTaxRate;
    }

    public function setCapitalGainsTaxRate(float $rate): static
    {
        $this->capitalGainsTaxRate = $rate;

        return $this;
    }

    /** @return Collection<int, Account> */
    public function getAccounts(): Collection
    {
        return $this->accounts;
    }

    public function addAccount(Account $account): static
    {
        if (!$this->accounts->contains($account)) {
            $this->accounts->add($account);
            $account->setPortfolio($this);
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ordinaryIncomeTaxRate' => $this->ordinaryIncomeTaxRate,
            'capitalGainsTaxRate' => $this->capitalGainsTaxRate,
            'accounts' => array_map(static fn (Account $account): array => $account->toJson(), $this->accounts->toArray()),
        ];
    }
}
