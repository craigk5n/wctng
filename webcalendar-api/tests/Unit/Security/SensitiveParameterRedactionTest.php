<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Auth\ChainedAuthenticator;
use App\Auth\LdapAuthenticator;
use App\CalDav\CoreAuthBackend;
use App\Controller\Api\McpController;
use App\Controller\Api\OAuthController;
use App\Controller\Api\ShareController;
use App\Controller\Api\UnsubscribeController;
use App\Share\ShareToken;
use App\Share\ShareTokenRepository;
use App\Tenant\MySqlTenantDatabaseCreator;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantProvisioner;
use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies PBP-S1: every parameter carrying a password, token, API key,
 * or signing secret is annotated with #[\SensitiveParameter] so the engine
 * redacts its value from stack traces.
 */
final class SensitiveParameterRedactionTest extends TestCase
{
    public function testEngineRedactsAnnotatedParameter(): void
    {
        try {
            $this->methodWithAnnotatedSecret('hunter2');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $frame = $this->findFrameFor('methodWithAnnotatedSecret', $e);
            $this->assertNotNull($frame, 'frame not found');
            $this->assertArrayHasKey('args', $frame);
            /** @var list<mixed> $args */
            $args = $frame['args'];
            $this->assertInstanceOf(
                \SensitiveParameterValue::class,
                $args[0],
                'annotated parameter must be redacted to SensitiveParameterValue'
            );
        }
    }

    public function testControlCaseUnannotatedParameterIsNotRedacted(): void
    {
        try {
            $this->methodWithUnannotatedSecret('hunter2');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $frame = $this->findFrameFor('methodWithUnannotatedSecret', $e);
            $this->assertNotNull($frame, 'frame not found');
            /** @var list<mixed> $args */
            $args = $frame['args'];
            $this->assertSame(
                'hunter2',
                $args[0],
                'control: unannotated parameter must appear verbatim — otherwise the redaction test proves nothing'
            );
        }
    }

    /**
     * Reflection-based inventory: every known secret-carrying parameter
     * in the codebase must carry #[\SensitiveParameter].
     *
     * @param class-string $class
     */
    #[DataProvider('secretParameterInventory')]
    public function testSecretParameterCarriesAttribute(string $class, string $method, string $paramName): void
    {
        $ref = new \ReflectionMethod($class, $method);
        $found = null;
        foreach ($ref->getParameters() as $p) {
            if ($p->getName() === $paramName) {
                $found = $p;
                break;
            }
        }

        $this->assertNotNull(
            $found,
            "{$class}::{$method}() has no parameter named \${$paramName} — update the inventory"
        );

        $attrs = $found->getAttributes(\SensitiveParameter::class);
        $this->assertNotEmpty(
            $attrs,
            "{$class}::{$method}(\${$paramName}) must be annotated with #[\\SensitiveParameter]"
        );
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function secretParameterInventory(): iterable
    {
        yield 'TenantDatabaseManager::encryptPassword($plaintext)' => [TenantDatabaseManager::class, 'encryptPassword', 'plaintext'];
        yield 'TenantDatabaseManager::decryptPassword($encrypted)' => [TenantDatabaseManager::class, 'decryptPassword', 'encrypted'];
        yield 'TenantDatabaseManager::__construct($appSecret)' => [TenantDatabaseManager::class, '__construct', 'appSecret'];

        yield 'TenantProvisioner::createAdminUser($password)' => [TenantProvisioner::class, 'createAdminUser', 'password'];
        // The DDL moved out of the provisioner into the creator seam; the
        // password it sets on the new login travels with it.
        yield 'MySqlTenantDatabaseCreator::create($dbPassword)' => [MySqlTenantDatabaseCreator::class, 'create', 'dbPassword'];
        yield 'MySqlTenantDatabaseCreator::__construct($adminDatabaseUrl)' => [MySqlTenantDatabaseCreator::class, '__construct', 'adminDatabaseUrl'];

        yield 'LdapAuthenticator::authenticate($password)' => [LdapAuthenticator::class, 'authenticate', 'password'];
        yield 'LdapAuthenticator::bindAsUser($password)' => [LdapAuthenticator::class, 'bindAsUser', 'password'];

        yield 'ChainedAuthenticator::authenticate($password)' => [ChainedAuthenticator::class, 'authenticate', 'password'];
        yield 'ChainedAuthenticator::tryPasswordAuth($password)' => [ChainedAuthenticator::class, 'tryPasswordAuth', 'password'];

        yield 'CoreAuthBackend::validateUserPass($password)' => [CoreAuthBackend::class, 'validateUserPass', 'password'];

        yield 'McpController::authenticateToken($token)' => [McpController::class, 'authenticateToken', 'token'];

        yield 'OAuthController::fetchUserProfile($accessToken)' => [OAuthController::class, 'fetchUserProfile', 'accessToken'];

        yield 'UnsubscribeController::unsubscribe($token)' => [UnsubscribeController::class, 'unsubscribe', 'token'];
        yield 'UnsubscribeController::findLoginByToken($token)' => [UnsubscribeController::class, 'findLoginByToken', 'token'];
        yield 'UnsubscribeController::generateToken($appSecret)' => [UnsubscribeController::class, 'generateToken', 'appSecret'];
        yield 'UnsubscribeController::__construct($appSecret)' => [UnsubscribeController::class, '__construct', 'appSecret'];

        yield 'ShareController::deleteShareToken($token)' => [ShareController::class, 'deleteShareToken', 'token'];
        yield 'ShareController::sharedEvents($token)' => [ShareController::class, 'sharedEvents', 'token'];

        yield 'ShareTokenRepository::findByToken($token)' => [ShareTokenRepository::class, 'findByToken', 'token'];
        yield 'ShareTokenRepository::create($token)' => [ShareTokenRepository::class, 'create', 'token'];
        yield 'ShareTokenRepository::delete($token)' => [ShareTokenRepository::class, 'delete', 'token'];

        yield 'ShareToken::__construct($token)' => [ShareToken::class, '__construct', 'token'];

        yield 'WebhookSubscription::__construct($secret)' => [WebhookSubscription::class, '__construct', 'secret'];
    }

    private function methodWithAnnotatedSecret(#[\SensitiveParameter] string $secret): void
    {
        // Reference $secret so static analysis doesn't complain; the throw is what matters.
        $_ = \strlen($secret);
        throw new \RuntimeException('boom');
    }

    private function methodWithUnannotatedSecret(string $secret): void
    {
        $_ = \strlen($secret);
        throw new \RuntimeException('boom');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findFrameFor(string $methodName, \Throwable $e): ?array
    {
        foreach ($e->getTrace() as $frame) {
            if (($frame['function'] ?? null) === $methodName) {
                return $frame;
            }
        }

        return null;
    }
}
