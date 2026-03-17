<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Entity\Blob;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\BlobRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\BlobType;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class AttachmentController
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const MAX_ATTACHMENTS = 10;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv', 'text/html',
        'application/zip', 'application/gzip',
    ];

    private readonly EventRepositoryInterface $eventRepo;
    private readonly BlobRepositoryInterface $blobRepo;

    public function __construct(CoreServiceFactory $factory)
    {
        $this->eventRepo = $factory->getEventRepository();
        $this->blobRepo = $factory->getBlobRepository();
    }

    public static function createForTest(
        EventRepositoryInterface $eventRepo,
        BlobRepositoryInterface $blobRepo,
    ): self {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(self::class, 'eventRepo');
        $ref->setValue($instance, $eventRepo);
        $ref = new \ReflectionProperty(self::class, 'blobRepo');
        $ref->setValue($instance, $blobRepo);
        return $instance;
    }

    #[Route('/api/v2/events/{eventId}/attachments', name: 'api_attachments_list', methods: ['GET'])]
    public function list(
        int $eventId,
        Request $request,
        #[CurrentUser] WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        if ($this->getLogin($actorOrUser) === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->eventRepo->findById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $blobs = $this->blobRepo->findByEvent($eventId, BlobType::ATTACHMENT);
        $items = array_map([$this, 'formatBlob'], $blobs);

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/events/{eventId}/attachments', name: 'api_attachments_upload', methods: ['POST'])]
    public function upload(
        int $eventId,
        Request $request,
        #[CurrentUser] WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $login = $this->getLogin($actorOrUser);
        if ($login === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->eventRepo->findById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        // Check attachment limit
        $existing = $this->blobRepo->findByEvent($eventId, BlobType::ATTACHMENT);
        if (\count($existing) >= self::MAX_ATTACHMENTS) {
            return ApiResponse::error(400, sprintf('Maximum %d attachments per event', self::MAX_ATTACHMENTS));
        }

        $file = $request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return ApiResponse::error(400, 'No file uploaded. Use multipart/form-data with field name "file"');
        }

        // Validate size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return ApiResponse::error(400, sprintf('File too large. Maximum size: %dMB', self::MAX_FILE_SIZE / 1024 / 1024));
        }

        // Validate MIME type
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ApiResponse::error(400, 'File type not allowed: ' . $mimeType);
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            return ApiResponse::error(500, 'Failed to read uploaded file');
        }

        $blob = new Blob(
            id: 0,
            eventId: $eventId,
            login: $login,
            name: $file->getClientOriginalName(),
            description: '',
            size: (int) $file->getSize(),
            mimeType: $mimeType,
            type: BlobType::ATTACHMENT,
            date: new \DateTimeImmutable(),
            content: $content,
        );

        $this->blobRepo->save($blob);

        return ApiResponse::success($this->formatBlob($blob), null, 201);
    }

    #[Route('/api/v2/events/{eventId}/attachments/{attachmentId}', name: 'api_attachments_download', methods: ['GET'])]
    public function download(
        int $eventId,
        int $attachmentId,
        #[CurrentUser] WebCalendarUser|User|null $actorOrUser = null,
    ): Response {
        if ($this->getLogin($actorOrUser) === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->eventRepo->findById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $blob = $this->blobRepo->findById($attachmentId);
        if ($blob === null || $blob->eventId() !== $eventId) {
            return ApiResponse::error(404, 'Attachment not found');
        }

        return new Response($blob->content(), 200, [
            'Content-Type' => $blob->mimeType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $blob->name()),
            'Content-Length' => (string) $blob->size(),
        ]);
    }

    #[Route('/api/v2/events/{eventId}/attachments/{attachmentId}', name: 'api_attachments_delete', methods: ['DELETE'])]
    public function delete(
        int $eventId,
        int $attachmentId,
        Request $request,
        #[CurrentUser] WebCalendarUser|User|null $actorOrUser = null,
    ): Response {
        $login = $this->getLogin($actorOrUser);
        if ($login === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->eventRepo->findById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $blob = $this->blobRepo->findById($attachmentId);
        if ($blob === null || $blob->eventId() !== $eventId) {
            return ApiResponse::error(404, 'Attachment not found');
        }

        $this->blobRepo->delete($attachmentId);

        return ApiResponse::noContent();
    }

    private function getLogin(WebCalendarUser|User|null $actorOrUser): ?string
    {
        if ($actorOrUser instanceof WebCalendarUser) {
            return $actorOrUser->getUserIdentifier();
        }
        if ($actorOrUser instanceof User) {
            return $actorOrUser->login();
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBlob(Blob $blob): array
    {
        return [
            'id' => $blob->id(),
            'filename' => $blob->name(),
            'mime_type' => $blob->mimeType(),
            'size' => $blob->size(),
            'created_at' => $blob->date()->format('Y-m-d\TH:i:s'),
            'uploaded_by' => $blob->login(),
        ];
    }
}
