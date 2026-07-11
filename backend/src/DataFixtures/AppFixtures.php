<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\Portfolio;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Enum\DrawdownFrequency;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setEmail('demo@nestegg.local')
            ->setBirthDate(new \DateTimeImmutable('1990-06-15'))
            ->setDeathAge(90)
            ->setPassword($this->hasher->hashPassword($user, 'demo-password-123'));
        $manager->persist($user);

        $portfolio = (new Portfolio())
            ->setOwner($user)
            ->setName('Baseline')
            ->setOrdinaryIncomeTaxRate(0.22)
            ->setCapitalGainsTaxRate(0.15);

        $k401 = (new Account())
            ->setName('Employer 401k')
            ->setType(AccountType::Traditional401k)
            ->setStartingBalance(50000.0)
            ->setAnnualReturnRate(0.07)
            ->setInflationRate(0.03)
            ->setHorizonYears(40)
            ->setContributionMonthlyAmount(1500.0)
            ->setContributionEscalationRate(0.02)
            ->setContributionEndsOn(new \DateTimeImmutable('2041-07-01'))
            ->setDrawdownAmount(4000.0)
            ->setDrawdownFrequency(DrawdownFrequency::Monthly)
            ->setDrawdownEntryMode(DrawdownEntryMode::Net)
            ->setDrawdownStartsOn(new \DateTimeImmutable('2041-07-01'));

        $brokerage = (new Account())
            ->setName('Taxable brokerage')
            ->setType(AccountType::Brokerage)
            ->setStartingBalance(25000.0)
            ->setStartingBasis(20000.0)
            ->setAnnualReturnRate(0.06)
            ->setInflationRate(0.03)
            ->setHorizonYears(40)
            ->setContributionMonthlyAmount(500.0);

        $portfolio->addAccount($k401)->addAccount($brokerage);
        $manager->persist($portfolio);
        $manager->flush();
    }
}
