<?php

declare(strict_types=1);

namespace App\CalDav;

use App\Tenant\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use WebCalendar\Core\Application\Contract\AuthServiceInterface;

/**
 * CalDAV authentication backend supporting both HTTP Basic and Bearer token auth.
 *
 * - HTTP Basic: validates username/password via webcalendar-core AuthService
 * - Bearer token: validates JWT tokens (same as REST API)
 * - Tenant-aware: works with TenantContext for multi-tenant CalDAV access
 */
final class CoreAuthBackend extends AbstractBasic
{
    private ?JWTEncoderInterface $jwtEncoder;
    private ?TenantContext $tenantContext;

    public function __construct(
        private readonly AuthServiceInterface $authService,
        ?JWTEncoderInterface $jwtEncoder = null,
        ?TenantContext $tenantContext = null,
    ) {
        $this->jwtEncoder = $jwtEncoder;
        $this->tenantContext = $tenantContext;
    }

    /**
     * @return array{bool, string}
     */
    #[\Override]
    public function check(RequestInterface $request, ResponseInterface $response): array
    {
        // Try Bearer token first
        $auth = $request->getHeader('Authorization');
        if (\is_string($auth) && str_starts_with($auth, 'Bearer ') && $this->jwtEncoder !== null) {
            $token = substr($auth, 7);
            try {
                /** @var array<string, mixed> $payload */
                $payload = $this->jwtEncoder->decode($token);
                $username = isset($payload['username']) && \is_string($payload['username']) ? $payload['username'] : null;

                if ($username !== null) {
                    // Validate tenant claim if in multi-tenant mode
                    if ($this->tenantContext !== null && $this->tenantContext->isMultiTenant()) {
                        $jwtTenant = isset($payload['tenant']) && \is_string($payload['tenant']) ? $payload['tenant'] : null;
                        $currentTenant = $this->tenantContext->getTenant();
                        if ($currentTenant !== null && $jwtTenant !== $currentTenant->slug()) {
                            return [false, 'JWT tenant mismatch'];
                        }
                    }

                    return [true, 'principals/' . $username];
                }
            } catch (\Exception) {
                // Invalid JWT — fall through to Basic auth
            }
        }

        // Fall through to HTTP Basic auth
        /** @var array{bool, string} */
        return parent::check($request, $response);
    }

    /**
     * @param mixed $username
     * @param mixed $password
     */
    #[\Override]
    protected function validateUserPass($username, #[\SensitiveParameter] $password): bool
    {
        if (!\is_string($username) || !\is_string($password)) {
            return false;
        }

        return $this->authService->authenticate($username, $password);
    }
}
