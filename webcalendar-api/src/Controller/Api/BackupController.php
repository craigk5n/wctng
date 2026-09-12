<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\BackupService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class BackupController
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {}

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

        // is_file rather than file_exists: isValidFilename() turns away
        // anything with a slash in it, but "." and ".." are made only of
        // characters it allows, and both name a directory rather than a
        // backup in it.
        $path = $this->backupService->getBackupDir() . '/' . $filename;
        if (!is_file($path)) {
            return ApiResponse::error(404, 'Backup not found');
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    #[Route('/api/v2/admin/backup/{filename}', name: 'api_admin_backup_delete', methods: ['DELETE'])]
    public function delete(string $filename, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        if (!BackupService::isValidFilename($filename)) {
            return ApiResponse::error(400, 'Invalid filename');
        }

        $path = $this->backupService->getBackupDir() . '/' . $filename;
        if (!is_file($path)) {
            return ApiResponse::error(404, 'Backup not found');
        }

        if (!unlink($path)) {
            return ApiResponse::error(500, 'Failed to delete backup file');
        }

        return ApiResponse::success(['deleted' => $filename]);
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

        // files->get() hands back an array when the field is repeated, and
        // the only method used below exists on a single file.
        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile instanceof UploadedFile) {
            return ApiResponse::error(400, 'No file uploaded');
        }

        // A database dump is routinely larger than upload_max_filesize. PHP
        // still hands over an UploadedFile for one that did not make it, but
        // its path points at nothing -- which reached the service as a missing
        // backup and came back a 500 blaming the file rather than the limit.
        if (!$uploadedFile->isValid()) {
            return ApiResponse::error(400, $uploadedFile->getErrorMessage());
        }

        $tmpPath = $uploadedFile->getPathname();

        try {
            $result = $this->backupService->restore($tmpPath);

            return ApiResponse::success($result);
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'Restore failed: ' . $e->getMessage());
        }
    }
}
