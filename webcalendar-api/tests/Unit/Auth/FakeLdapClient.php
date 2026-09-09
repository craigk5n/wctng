<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapClient;
use App\Auth\LdapConnection;

/**
 * Scripted stand-in for ext-ldap.
 *
 * Records what it was asked to do so tests can assert on the URI that was
 * built, the order of binds, and that connections are closed.
 */
final class FakeLdapClient implements LdapClient
{
    /** @var list<string> */
    public array $connectedUris = [];

    public ?FakeLdapConnection $connection = null;

    public function __construct(
        private readonly bool $supported = true,
        private readonly bool $connectSucceeds = true,
    ) {}

    #[\Override]
    public function isSupported(): bool
    {
        return $this->supported;
    }

    #[\Override]
    public function connect(string $uri): ?LdapConnection
    {
        $this->connectedUris[] = $uri;

        if (!$this->connectSucceeds) {
            return null;
        }

        return $this->connection ??= new FakeLdapConnection();
    }

    #[\Override]
    public function escapeFilterValue(string $value): string
    {
        // strtr, not str_replace: sequential replacement would re-escape the
        // backslashes it just introduced.
        return strtr($value, ['\\' => '\\5c', '*' => '\\2a', '(' => '\\28', ')' => '\\29']);
    }
}

final class FakeLdapConnection implements LdapConnection
{
    /** @var list<array{dn: string, password: string}> */
    public array $binds = [];

    /** @var list<array{base: string, filter: string, attributes: list<string>}> */
    public array $searches = [];

    public int $closes = 0;

    /** @var array<string, bool> dn => whether binding as it succeeds */
    public array $bindResults = [];

    public bool $defaultBindResult = true;

    /** @var array<array-key, mixed>|null */
    public ?array $searchEntries = ['count' => 0];

    /** @var array<array-key, mixed>|null */
    public ?array $readEntries = ['count' => 0];

    #[\Override]
    public function bind(string $dn, #[\SensitiveParameter] string $password): bool
    {
        $this->binds[] = ['dn' => $dn, 'password' => $password];

        return $this->bindResults[$dn] ?? $this->defaultBindResult;
    }

    #[\Override]
    public function search(string $baseDn, string $filter, array $attributes): ?array
    {
        $this->searches[] = ['base' => $baseDn, 'filter' => $filter, 'attributes' => $attributes];

        return $this->searchEntries;
    }

    #[\Override]
    public function read(string $dn, string $filter, array $attributes): ?array
    {
        $this->searches[] = ['base' => $dn, 'filter' => $filter, 'attributes' => $attributes];

        return $this->readEntries;
    }

    #[\Override]
    public function close(): void
    {
        $this->closes++;
    }
}
