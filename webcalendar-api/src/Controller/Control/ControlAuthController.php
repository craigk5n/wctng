<?php

declare(strict_types=1);

namespace App\Controller\Control;

use App\Response\ApiResponse;
use App\Security\PasswordHasher;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
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
        private readonly PasswordHasher $passwordHasher = new PasswordHasher(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

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

        if (!$this->passwordHasher->verify($password, $hash)) {
            return ApiResponse::error(401, 'Invalid credentials');
        }

        // Best-effort rehash-on-login for control-plane admins whose hash
        // predates pinned Argon2id parameters. Failure is non-fatal.
        if ($this->passwordHasher->needsRehash($hash)) {
            try {
                $update = $this->pdo->prepare('UPDATE control_admins SET password_hash = :hash WHERE username = :username');
                $update->execute([
                    'hash' => $this->passwordHasher->hash($password),
                    'username' => $username,
                ]);
            } catch (\Throwable) {
                // ignore — user is still authenticated
            }
        }

        $token = $this->jwtEncoder->encode([
            'username' => $username,
            'role' => 'super_admin',
        ]);

        $expiresAt = $this->clock->now()->modify('+' . $this->jwtTtl . ' seconds');

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
