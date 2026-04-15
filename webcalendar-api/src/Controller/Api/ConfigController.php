<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ConfigService;

final class ConfigController
{
    /** Default feature flag values */
    private const DEFAULTS = [
        'ALLOW_HTML_DESCRIPTION' => 'Y',
        'DISABLE_LOCATION_FIELD' => 'N',
        'DISABLE_URL_FIELD' => 'N',
        'DISABLE_PRIORITY_FIELD' => 'N',
        'DISABLE_PARTICIPANTS_FIELD' => 'N',
        'DISABLE_EXT_PARTICIPANTS_FIELD' => 'N',
        'DISABLE_TASKS' => 'N',
        'DISABLE_JOURNALS' => 'N',
        'DISABLE_ATTACHMENTS' => 'N',
        'DISABLE_COMMENTS' => 'N',
        'ENABLE_SEO_PAGES' => 'N',
        'ENABLE_GEOCODING' => 'Y',
        'ENABLE_EMAIL_REMINDERS' => 'Y',
        'ENABLE_DAILY_AGENDA' => 'N',
        'MAX_EVENTS_PER_PAGE' => '1000',
        'SEO_OG_IMAGE_URL' => '',
        'SESSION_TTL' => '28800',
        'SESSION_TTL_REMEMBER_ME' => '2592000',
        'DISABLE_REMEMBER_ME' => 'N',
    ];

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    #[Route('/api/v2/admin/config', name: 'api_admin_config_get', methods: ['GET'])]
    public function getConfig(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $settings = $this->configService->getAllSettings();

        // Merge with defaults for any missing keys
        foreach (self::DEFAULTS as $key => $default) {
            if (!isset($settings[$key])) {
                $settings[$key] = $default;
            }
        }

        return ApiResponse::success($settings);
    }

    #[Route('/api/v2/admin/config', name: 'api_admin_config_update', methods: ['PUT'])]
    public function updateConfig(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        /** @var array<string, string> $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $configService = $this->configService;
        foreach ($data as $key => $value) {
            $configService->updateSetting($key, $value);
        }

        return ApiResponse::success(['updated' => \count($data)]);
    }

    /** Public endpoint — returns feature flags for frontend conditional rendering */
    #[Route('/api/v2/config/features', name: 'api_config_features', methods: ['GET'])]
    public function getFeatures(): JsonResponse
    {
        $configService = $this->configService;

        $features = [];
        foreach (self::DEFAULTS as $key => $default) {
            $features[$key] = $configService->getSetting($key, $default) ?? $default;
        }

        return ApiResponse::success($features);
    }
}
