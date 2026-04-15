<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CustomHtmlSanitizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ConfigService;

final class CustomHtmlController
{
    private readonly CustomHtmlSanitizer $sanitizer;

    public function __construct(
        private readonly ConfigService $configService,
    ) {
        $this->sanitizer = new CustomHtmlSanitizer();
    }

    /**
     * Admin endpoint: get custom HTML/CSS values.
     */
    #[Route('/api/v2/admin/custom-html', name: 'admin_custom_html_get', methods: ['GET'])]
    public function adminGet(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        return ApiResponse::success($this->getValues());
    }

    /**
     * Admin endpoint: update custom HTML/CSS values.
     */
    #[Route('/api/v2/admin/custom-html', name: 'admin_custom_html_update', methods: ['PUT'])]
    public function adminUpdate(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;
        $configService = $this->configService;

        if (isset($data['header_html']) && \is_string($data['header_html'])) {
            $configService->updateSetting('CUSTOM_HEADER_HTML', $this->sanitizer->sanitizeHtml($data['header_html']));
        }

        if (isset($data['trailer_html']) && \is_string($data['trailer_html'])) {
            $configService->updateSetting('CUSTOM_TRAILER_HTML', $this->sanitizer->sanitizeHtml($data['trailer_html']));
        }

        if (isset($data['custom_css']) && \is_string($data['custom_css'])) {
            $configService->updateSetting('CUSTOM_CSS', $this->sanitizer->sanitizeCss($data['custom_css']));
        }

        return ApiResponse::success($this->getValues());
    }

    /**
     * Public endpoint: get custom HTML/CSS for SPA rendering.
     */
    #[Route('/api/v2/config/custom-html', name: 'config_custom_html', methods: ['GET'])]
    public function publicGet(): JsonResponse
    {
        return ApiResponse::success($this->getValues());
    }

    /**
     * @return array{header_html: string, trailer_html: string, custom_css: string}
     */
    private function getValues(): array
    {
        $configService = $this->configService;

        return [
            'header_html' => $configService->getSetting('CUSTOM_HEADER_HTML') ?? '',
            'trailer_html' => $configService->getSetting('CUSTOM_TRAILER_HTML') ?? '',
            'custom_css' => $configService->getSetting('CUSTOM_CSS') ?? '',
        ];
    }
}
