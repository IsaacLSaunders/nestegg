<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Enum\DrawdownFrequency;
use App\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Portfolio $portfolio;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(enumType: AccountType::class)]
    private AccountType $type;

    #[ORM\Column]
    private float $startingBalance = 0.0;

    #[ORM\Column(nullable: true)]
    private ?float $startingBasis = null;

    #[ORM\Column]
    private float $annualReturnRate = 0.07;

    #[ORM\Column]
    private float $inflationRate = 0.03;

    #[ORM\Column]
    private int $horizonYears = 40;

    #[ORM\Column]
    private float $contributionMonthlyAmount = 0.0;

    #[ORM\Column]
    private float $contributionEscalationRate = 0.0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contributionStartsOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contributionEndsOn = null;

    #[ORM\Column(nullable: true)]
    private ?float $drawdownAmount = null;

    #[ORM\Column(enumType: DrawdownFrequency::class)]
    private DrawdownFrequency $drawdownFrequency = DrawdownFrequency::Monthly;

    #[ORM\Column(enumType: DrawdownEntryMode::class)]
    private DrawdownEntryMode $drawdownEntryMode = DrawdownEntryMode::Gross;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $drawdownStartsOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $drawdownEndsOn = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $drawdownInflationIndexed = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortfolio(): Portfolio
    {
        return $this->portfolio;
    }

    public function setPortfolio(Portfolio $portfolio): static
    {
        $this->portfolio = $portfolio;

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

    public function getType(): AccountType
    {
        return $this->type;
    }

    public function setType(AccountType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStartingBalance(): float
    {
        return $this->startingBalance;
    }

    public function setStartingBalance(float $startingBalance): static
    {
        $this->startingBalance = $startingBalance;

        return $this;
    }

    public function getStartingBasis(): ?float
    {
        return $this->startingBasis;
    }

    public function setStartingBasis(?float $startingBasis): static
    {
        $this->startingBasis = $startingBasis;

        return $this;
    }

    public function getAnnualReturnRate(): float
    {
        return $this->annualReturnRate;
    }

    public function setAnnualReturnRate(float $annualReturnRate): static
    {
        $this->annualReturnRate = $annualReturnRate;

        return $this;
    }

    public function getInflationRate(): float
    {
        return $this->inflationRate;
    }

    public function setInflationRate(float $inflationRate): static
    {
        $this->inflationRate = $inflationRate;

        return $this;
    }

    public function getHorizonYears(): int
    {
        return $this->horizonYears;
    }

    public function setHorizonYears(int $horizonYears): static
    {
        $this->horizonYears = $horizonYears;

        return $this;
    }

    public function getContributionMonthlyAmount(): float
    {
        return $this->contributionMonthlyAmount;
    }

    public function setContributionMonthlyAmount(float $amount): static
    {
        $this->contributionMonthlyAmount = $amount;

        return $this;
    }

    public function getContributionEscalationRate(): float
    {
        return $this->contributionEscalationRate;
    }

    public function setContributionEscalationRate(float $rate): static
    {
        $this->contributionEscalationRate = $rate;

        return $this;
    }

    public function getContributionStartsOn(): ?\DateTimeImmutable
    {
        return $this->contributionStartsOn;
    }

    public function setContributionStartsOn(?\DateTimeImmutable $date): static
    {
        $this->contributionStartsOn = $date;

        return $this;
    }

    public function getContributionEndsOn(): ?\DateTimeImmutable
    {
        return $this->contributionEndsOn;
    }

    public function setContributionEndsOn(?\DateTimeImmutable $date): static
    {
        $this->contributionEndsOn = $date;

        return $this;
    }

    public function getDrawdownAmount(): ?float
    {
        return $this->drawdownAmount;
    }

    public function setDrawdownAmount(?float $amount): static
    {
        $this->drawdownAmount = $amount;

        return $this;
    }

    public function getDrawdownFrequency(): DrawdownFrequency
    {
        return $this->drawdownFrequency;
    }

    public function setDrawdownFrequency(DrawdownFrequency $frequency): static
    {
        $this->drawdownFrequency = $frequency;

        return $this;
    }

    public function getDrawdownEntryMode(): DrawdownEntryMode
    {
        return $this->drawdownEntryMode;
    }

    public function setDrawdownEntryMode(DrawdownEntryMode $mode): static
    {
        $this->drawdownEntryMode = $mode;

        return $this;
    }

    public function getDrawdownStartsOn(): ?\DateTimeImmutable
    {
        return $this->drawdownStartsOn;
    }

    public function setDrawdownStartsOn(?\DateTimeImmutable $date): static
    {
        $this->drawdownStartsOn = $date;

        return $this;
    }

    public function getDrawdownEndsOn(): ?\DateTimeImmutable
    {
        return $this->drawdownEndsOn;
    }

    public function setDrawdownEndsOn(?\DateTimeImmutable $date): static
    {
        $this->drawdownEndsOn = $date;

        return $this;
    }

    public function isDrawdownInflationIndexed(): bool
    {
        return $this->drawdownInflationIndexed;
    }

    public function setDrawdownInflationIndexed(bool $indexed): static
    {
        $this->drawdownInflationIndexed = $indexed;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'portfolioId' => $this->portfolio->getId(),
            'name' => $this->name,
            'type' => $this->type->value,
            'startingBalance' => $this->startingBalance,
            'startingBasis' => $this->startingBasis,
            'annualReturnRate' => $this->annualReturnRate,
            'inflationRate' => $this->inflationRate,
            'horizonYears' => $this->horizonYears,
            'contribution' => [
                'monthlyAmount' => $this->contributionMonthlyAmount,
                'escalationRate' => $this->contributionEscalationRate,
                'startsOn' => $this->contributionStartsOn?->format('Y-m-d'),
                'endsOn' => $this->contributionEndsOn?->format('Y-m-d'),
            ],
            'drawdown' => [
                'amount' => $this->drawdownAmount,
                'frequency' => $this->drawdownFrequency->value,
                'entryMode' => $this->drawdownEntryMode->value,
                'startsOn' => $this->drawdownStartsOn?->format('Y-m-d'),
                'endsOn' => $this->drawdownEndsOn?->format('Y-m-d'),
                'inflationIndexed' => $this->drawdownInflationIndexed,
            ],
        ];
    }
}
