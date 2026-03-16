<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JwtTokenTest extends KernelTestCase
{
    private JWTEncoderInterface $encoder;

    protected function setUp(): void
    {
        self::bootKernel();
        $encoder = self::getContainer()->get('lexik_jwt_authentication.encoder');
        $this->assertInstanceOf(JWTEncoderInterface::class, $encoder);
        $this->encoder = $encoder;
    }

    public function testTokenContainsRequiredClaims(): void
    {
        $token = $this->encoder->encode([
            'username' => 'testuser',
            'is_admin' => false,
        ]);

        $decoded = $this->encoder->decode($token);

        $this->assertSame('testuser', $decoded['username']);
        $this->assertFalse($decoded['is_admin']);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
    }

    public function testTokenExpiresAfterConfiguredTtl(): void
    {
        $token = $this->encoder->encode([
            'username' => 'testuser',
            'is_admin' => false,
        ]);

        $decoded = $this->encoder->decode($token);

        $ttl = $decoded['exp'] - $decoded['iat'];
        // JWT_TTL is 3600 (1 hour) in .env.test
        $this->assertSame(3600, $ttl);
    }

    public function testTokenCanContainAdminFlag(): void
    {
        $token = $this->encoder->encode([
            'username' => 'adminuser',
            'is_admin' => true,
        ]);

        $decoded = $this->encoder->decode($token);

        $this->assertSame('adminuser', $decoded['username']);
        $this->assertTrue($decoded['is_admin']);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->expectException(JWTDecodeFailureException::class);
        $this->encoder->decode('invalid.token.string');
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = $this->encoder->encode(['username' => 'testuser', 'is_admin' => false]);

        // Tamper with the payload section
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $parts[1] = base64_encode('{"username":"hacker","is_admin":true,"iat":9999999999,"exp":9999999999}');
        $tampered = implode('.', $parts);

        $this->expectException(JWTDecodeFailureException::class);
        $this->encoder->decode($tampered);
    }

    public function testTokenIsSignedWithRs256(): void
    {
        $token = $this->encoder->encode(['username' => 'testuser', 'is_admin' => false]);

        // Decode the header to check algorithm
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $headerJson = base64_decode($parts[0], true);
        $this->assertIsString($headerJson);

        /** @var array{alg: string, typ: string} $header */
        $header = json_decode($headerJson, true);
        $this->assertIsArray($header);
        $this->assertSame('RS256', $header['alg']);
    }
}
