<?php

declare(strict_types=1);

namespace Marrow\Auth;

interface GuardInterface
{
    public function check(): bool;
    public function user(): ?object;
    public function id(): int|string|null;
    public function attempt(array $credentials): bool;
    public function login(object $user): void;
    public function logout(): void;
}
