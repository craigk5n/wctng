<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreAuthBackend;
use App\Service\CoreServiceFactory;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\HTTP;

final class CoreAuthBackendTest extends TestCase
{
    public function testExtendsAbstractBasic(): void
    {
        $this->assertTrue(is_subclass_of(CoreAuthBackend::class, AbstractBasic::class));
    }

    public function testRejectsNonStringCredentials(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $backend = new CoreAuthBackend($factory);
        $method = new \ReflectionMethod($backend, 'validateUserPass');

        $this->assertFalse($method->invoke($backend, null, null));
        $this->assertFalse($method->invoke($backend, 123, 'pass'));
    }

    public function testBearerTokenAuthSuccess(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $jwtEncoder->method('decode')->willReturn(['username' => 'alice']);

        $backend = new CoreAuthBackend($factory, $jwtEncoder);

        $request = new HTTP\Request('GET', '/dav/', ['Authorization' => 'Bearer fake-jwt-token']);
        $response = new HTTP\Response();

        [$success, $principal] = $backend->check($request, $response);

        $this->assertTrue($success);
        $this->assertSame('principals/alice', $principal);
    }

    public function testBearerTokenFallsBackToBasicOnInvalidJwt(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $jwtEncoder->method('decode')->willThrowException(new \Exception('Invalid token'));

        $backend = new CoreAuthBackend($factory, $jwtEncoder);

        $request = new HTTP\Request('GET', '/dav/', ['Authorization' => 'Bearer bad-token']);
        $response = new HTTP\Response();

        [$success,] = $backend->check($request, $response);

        // Falls back to Basic — no Basic header so fails
        $this->assertFalse($success);
    }

    public function testBearerTokenRejectsTenantMismatch(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $jwtEncoder->method('decode')->willReturn(['username' => 'alice', 'tenant' => 'acme']);

        $tenantContext = new TenantContext();
        $tenantContext->setTenant(new Tenant(1, 'globex', 'Globex', '', '', '', '', 'pro', 'active'));

        $backend = new CoreAuthBackend($factory, $jwtEncoder, $tenantContext);

        $request = new HTTP\Request('GET', '/dav/', ['Authorization' => 'Bearer jwt-wrong-tenant']);
        $response = new HTTP\Response();

        [$success, $message] = $backend->check($request, $response);

        $this->assertFalse($success);
        $this->assertStringContainsString('mismatch', $message);
    }

    public function testBearerTokenAcceptsMatchingTenant(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $jwtEncoder->method('decode')->willReturn(['username' => 'alice', 'tenant' => 'acme']);

        $tenantContext = new TenantContext();
        $tenantContext->setTenant(new Tenant(1, 'acme', 'Acme', '', '', '', '', 'pro', 'active'));

        $backend = new CoreAuthBackend($factory, $jwtEncoder, $tenantContext);

        $request = new HTTP\Request('GET', '/dav/', ['Authorization' => 'Bearer jwt-correct-tenant']);
        $response = new HTTP\Response();

        [$success, $principal] = $backend->check($request, $response);

        $this->assertTrue($success);
        $this->assertSame('principals/alice', $principal);
    }
}
