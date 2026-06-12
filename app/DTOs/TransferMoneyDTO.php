<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class TransferMoneyDTO
{
    public function __construct(
        public int $fromWalletId,
        public int $toWalletId,
        public int $amount,
        public ?string $description = null,
    ) {}
}