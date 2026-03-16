<?php

declare(strict_types=1);

namespace App\CalDav;

use App\Service\CoreServiceFactory;
use Sabre\DAV\Auth\Backend\AbstractBasic;

/**
 * CalDAV authentication backend that delegates to webcalendar-core's AuthService.
 *
 * Supports HTTP Basic authentication for CalDAV clients.
 */
final class CoreAuthBackend extends AbstractBasic
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

    /**
     * @param mixed $username
     * @param mixed $password
     */
    #[\Override]
    protected function validateUserPass($username, $password): bool
    {
        if (!\is_string($username) || !\is_string($password)) {
            return false;
        }

        $authService = $this->coreServiceFactory->getAuthService();

        return $authService->authenticate($username, $password);
    }
}
