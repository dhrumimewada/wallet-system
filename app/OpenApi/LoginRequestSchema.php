<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "LoginRequest",
    required: ["email", "password"]
)]
final class LoginRequestSchema
{
    #[OA\Property(
        property: "email",
        type: "string",
        format: "email",
        example: "john@example.com"
    )]
    public string $email;

    #[OA\Property(
        property: "password",
        type: "string",
        example: "password123"
    )]
    public string $password;
}