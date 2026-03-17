<?php

declare(strict_types=1);

namespace App\Share;

final readonly class ShareToken
{
    public function __construct(
        private int $id,
        private string $token,
        private string $ownerLogin,
        private ?string $expiresAt,
        private string $createdAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function ownerLogin(): string
    {
        return $this->ownerLogin;
    }

    public function expiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return new \DateTimeImmutable($this->expiresAt) < new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'owner_login' => $this->ownerLogin,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
        ];
    }
}
