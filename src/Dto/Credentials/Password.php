<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

final readonly class Password extends Credentials
{
    public static function fromLoginAndPassword(string $login, string $password): self
    {
        return self::from(value: (string) json_encode([
            'login' => $login,
            'password' => $password,
        ]));
    }

    public function login(): string
    {
        return $this->credentials()['login'];
    }

    public function password(): string
    {
        return $this->credentials()['password'];
    }

    /** @return array{login: string, password: string} */
    public function credentials(): array
    {
        /** @var array{login: string, password: string} $decoded */
        $decoded = json_decode((string) $this->credentials?->getValue(), true);

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'credentials' => $this->credentials(),
        ];
    }
}
