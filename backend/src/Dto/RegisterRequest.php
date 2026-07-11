<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 10, max: 4096)]
        public string $password,
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $birthDate,
        #[Assert\Range(min: 1, max: 120)]
        public int $deathAge = 90,
    ) {
    }
}
