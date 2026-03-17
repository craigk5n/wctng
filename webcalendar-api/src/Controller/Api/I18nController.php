<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class I18nController
{
    private const SUPPORTED_LOCALES = [
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
    ];

    #[Route('/api/v2/i18n/locales', name: 'api_i18n_locales', methods: ['GET'])]
    public function locales(): JsonResponse
    {
        $locales = [];
        foreach (self::SUPPORTED_LOCALES as $code => $name) {
            $locales[] = [
                'code' => $code,
                'name' => $name,
            ];
        }

        return ApiResponse::success($locales);
    }
}
