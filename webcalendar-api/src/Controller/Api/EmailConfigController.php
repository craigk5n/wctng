<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\EmailService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EmailConfigController
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {}

    #[Route('/api/v2/admin/email-config', name: 'api_email_config_get', methods: ['GET'])]
    public function get(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        return ApiResponse::success($this->emailService->getConfig());
    }

    #[Route('/api/v2/admin/email-config/test', name: 'api_email_config_test', methods: ['POST'])]
    public function test(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        $to = isset($data['to']) && \is_string($data['to']) ? $data['to'] : $user->getCoreUser()->email();

        if ($to === '') {
            return ApiResponse::error(400, 'No email address provided');
        }

        $success = $this->emailService->sendTestEmail($to);

        if ($success) {
            return ApiResponse::success(['message' => "Test email sent to {$to}"]);
        }

        return ApiResponse::error(500, 'Failed to send test email. Check MAILER_DSN configuration.');
    }
}
