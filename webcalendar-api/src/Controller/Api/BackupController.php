<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\BackupService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class BackupController
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {
    }

    #[Route('/api/v2/admin/backup', name: 'api_admin_backup_create', methods: ['POST'])]
    public function create(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        try {
            $result = $this->backupService->createBackup();

            return ApiResponse::success([
                'filename' => $result['filename'],
                'download_url' => "/api/v2/admin/backup/{$result['filename']}",
                'size_bytes' => $result['size_bytes'],
                'created_at' => $result['created_at'],
            ], null, Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'Backup failed: ' . $e->getMessage());
        }
    }

    #[Route('/api/v2/admin/backup', name: 'api_admin_backup_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        return ApiResponse::success($this->backupService->listBackups());
    }

    #[Route('/api/v2/admin/backup/{filename}', name: 'api_admin_backup_download', methods: ['GET'])]
    public function download(string $filename, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        // Path traversal protection
        if (!BackupService::isValidFilename($filename)) {
            return ApiResponse::error(400, 'Invalid filename');
        }

        $path = $this->backupService->getBackupDir() . '/' . $filename;
        if (!file_exists($path)) {
            return ApiResponse::error(404, 'Backup not found');
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    #[Route('/api/v2/admin/restore', name: 'api_admin_restore', methods: ['POST'])]
    public function restore(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        // Require explicit confirmation
        $confirm = $request->request->getString('confirm', '');
        if ($confirm !== 'RESTORE') {
            return ApiResponse::error(400, 'Confirmation required: send confirm=RESTORE');
        }

        $uploadedFile = $request->files->get('file');
        if ($uploadedFile === null) {
            return ApiResponse::error(400, 'No file uploaded');
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile */
        $tmpPath = $uploadedFile->getPathname();

        try {
            $result = $this->backupService->restore($tmpPath);

            return ApiResponse::success($result);
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'Restore failed: ' . $e->getMessage());
        }
    }
}
