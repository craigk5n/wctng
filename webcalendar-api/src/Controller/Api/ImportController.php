<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Application\Service\ImportService;

final class ImportController
{
    public function __construct(
        private readonly CategoryService $categoryService,
        private readonly ImportService $importService,
    ) {}

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
            // Count categories before import to detect new ones
            $catsBefore = \count($this->categoryService->getCategoriesForUser($user->getUserIdentifier()));

            $result = $this->importService->importIcal($content, $user->getCoreUser());

            $catsAfter = \count($this->categoryService->getCategoriesForUser($user->getUserIdentifier()));
            $newCategories = max(0, $catsAfter - $catsBefore);

            return ApiResponse::success([
                'imported' => $result->importedCount,
                'skipped' => $result->skippedCount,
                'new_categories' => $newCategories,
                'warnings' => $result->warnings,
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error(400, 'Import failed: ' . $e->getMessage());
        }
    }
}
