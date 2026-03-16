<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ImportController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

    #[Route('/api/v2/import', name: 'api_import', methods: ['POST'])]
    public function import(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return ApiResponse::error(400, 'Missing required file upload: file');
        }

        $content = file_get_contents($file->getPathname());

        if ($content === false || $content === '') {
            return ApiResponse::error(400, 'Could not read uploaded file');
        }

        // Basic validation — must look like an ICS file
        if (!str_contains($content, 'BEGIN:VCALENDAR')) {
            return ApiResponse::error(400, 'Invalid ICS file: missing VCALENDAR');
        }

        try {
            $result = $this->coreServiceFactory->getImportService()->importIcal(
                $content,
                $user->getCoreUser(),
            );

            return ApiResponse::success([
                'imported' => $result->importedCount,
                'skipped' => $result->skippedCount,
                'warnings' => $result->warnings,
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error(400, 'Import failed: ' . $e->getMessage());
        }
    }
}
