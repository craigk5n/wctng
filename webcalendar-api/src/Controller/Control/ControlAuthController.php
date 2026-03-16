<?php

declare(strict_types=1);

namespace App\Controller\Control;

use App\Response\ApiResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Authentication for the control plane (super-admin).
 *
 * Super-admin credentials are stored in the control database's control_admins table.
 */
final class ControlAuthController
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly int $jwtTtl,
    ) {
    }

    #[Route('/control/v1/auth/login', name: 'control_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $body */
        $body = $data;

        $username = isset($body['username']) && \is_string($body['username']) ? $body['username'] : null;
        $password = isset($body['password']) && \is_string($body['password']) ? $body['password'] : null;

        if ($username === null || $username === '' || $password === null || $password === '') {
            return ApiResponse::error(400, 'Missing required fields: username, password');
        }

        $this->ensureTable();

        $stmt = $this->pdo->prepare('SELECT password_hash FROM control_admins WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return ApiResponse::error(401, 'Invalid credentials');
        }

        /** @var string $hash */
        $hash = $row['password_hash'];

        if (!password_verify($password, $hash)) {
            return ApiResponse::error(401, 'Invalid credentials');
        }

        $token = $this->jwtEncoder->encode([
            'username' => $username,
            'role' => 'super_admin',
        ]);

        $expiresAt = new \DateTimeImmutable('+' . $this->jwtTtl . ' seconds');

        return ApiResponse::success([
            'token' => $token,
            'user' => ['username' => $username, 'role' => 'super_admin'],
            'expires_at' => $expiresAt->format('c'),
        ]);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS control_admins (
                username VARCHAR(100) NOT NULL PRIMARY KEY,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }
}
