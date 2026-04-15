<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;

/**
 * Event comments — stored as activity log entries of type COMMENT.
 */
final class CommentController
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly ConfigService $configService,
        private readonly \PDO $pdo,
    ) {}

    #[Route('/api/v2/events/{eventId}/comments', name: 'api_event_comments_list', methods: ['GET'])]
    public function list(int $eventId, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        if ($this->isCommentsDisabled()) {
            return ApiResponse::success([]);
        }

        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, event_id, user_login, comment_text, created_at FROM event_comments WHERE event_id = :eid ORDER BY created_at ASC',
        );
        $stmt->execute(['eid' => $eventId]);

        $comments = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row)) {
                /** @var array<string, string|int|null> $row */
                $comments[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'event_id' => (int) ($row['event_id'] ?? 0),
                    'user_login' => (string) ($row['user_login'] ?? ''),
                    'text' => (string) ($row['comment_text'] ?? ''),
                    'created_at' => date('c', (int) ($row['created_at'] ?? 0)),
                ];
            }
        }

        return ApiResponse::success($comments);
    }

    #[Route('/api/v2/events/{eventId}/comments', name: 'api_event_comments_create', methods: ['POST'])]
    public function create(int $eventId, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        if ($this->isCommentsDisabled()) {
            return ApiResponse::error(403, 'Comments are disabled');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array{text?: string} $data */
        $data = $decoded;
        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            return ApiResponse::error(400, 'Comment text is required');
        }

        $this->ensureTable();

        $now = time();
        $login = $user->getUserIdentifier();

        $this->pdo->prepare(
            'INSERT INTO event_comments (event_id, user_login, comment_text, created_at) VALUES (:eid, :login, :text, :now)',
        )->execute([
            'eid' => $eventId,
            'login' => $login,
            'text' => $text,
            'now' => $now,
        ]);

        $commentId = (int) $this->pdo->lastInsertId();

        // Also log to activity log
        try {
            $this->activityLogService->log(
                $eventId,
                $login,
                null,
                ActivityLogType::UPDATE,
                'Comment: ' . mb_substr($text, 0, 100),
            );
        } catch (\Throwable) {
        }

        return ApiResponse::success([
            'id' => $commentId,
            'event_id' => $eventId,
            'user_login' => $login,
            'text' => $text,
            'created_at' => date('c', $now),
        ], null, 201);
    }

    #[Route('/api/v2/events/{eventId}/comments/{commentId}', name: 'api_event_comments_delete', methods: ['DELETE'])]
    public function delete(int $eventId, int $commentId, #[CurrentUser] ?WebCalendarUser $user): JsonResponse|Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $login = $user->getUserIdentifier();
        $isAdmin = $user->getCoreUser()->isAdmin();

        // Only comment author or admin can delete
        $stmt = $this->pdo->prepare('SELECT user_login FROM event_comments WHERE id = :id AND event_id = :eid');
        $stmt->execute(['id' => $commentId, 'eid' => $eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return ApiResponse::error(404, 'Comment not found');
        }

        /** @var array<string, string|int|null> $row */
        if ((string) ($row['user_login'] ?? '') !== $login && !$isAdmin) {
            return ApiResponse::error(403, 'You can only delete your own comments');
        }

        $this->pdo->prepare('DELETE FROM event_comments WHERE id = :id')->execute(['id' => $commentId]);

        return ApiResponse::noContent();
    }

    private function isCommentsDisabled(): bool
    {
        return $this->configService->getSetting('DISABLE_COMMENTS') === 'Y';
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS event_comments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_id INTEGER NOT NULL,
                    user_login VARCHAR(60) NOT NULL,
                    comment_text TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )',
            );
        } else {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS event_comments (
                    id INTEGER PRIMARY KEY AUTO_INCREMENT,
                    event_id INTEGER NOT NULL,
                    user_login VARCHAR(60) NOT NULL,
                    comment_text TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )',
            );
        }
    }
}
