<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ErrorMetricsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * Admin-only dashboard with system statistics.
 */
final class DashboardController
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly \PDO $pdo,
        private readonly ?ErrorMetricsService $errorMetrics = null,
    ) {
    }

    #[Route('/api/v2/admin/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        return ApiResponse::success([
            'users' => $this->getUserStats(),
            'events' => $this->getEventStats(),
            'system' => $this->getSystemInfo(),
            'email' => $this->getEmailStats(),
        ]);
    }

    /**
     * @return array{total: int, active_7d: int, created_7d: int}
     */
    private function getUserStats(): array
    {
        $total = 0;
        try {
            $total = \count($this->userRepository->findAll());
        } catch (\Throwable) {
        }

        // Active users: distinct creators in last 7 days
        $active7d = 0;
        try {
            $cutoff = (new \DateTimeImmutable('-7 days'))->format('Ymd');
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT cal_create_by) FROM webcal_entry WHERE cal_date >= :cutoff',
            );
            $stmt->execute(['cutoff' => $cutoff]);
            /** @var numeric-string|false $val */
            $val = $stmt->fetchColumn();
            $active7d = $val !== false ? (int) $val : 0;
        } catch (\Throwable) {
        }

        return ['total' => $total, 'active_7d' => $active7d, 'created_7d' => $this->getEventsCreated7d()];
    }

    /**
     * @return array{total: int, created_7d: int, upcoming_7d: int}
     */
    private function getEventStats(): array
    {
        $total = 0;
        $upcoming7d = 0;

        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM webcal_entry');
            if ($stmt !== false) {
                /** @var numeric-string|false $val */
                $val = $stmt->fetchColumn();
                $total = $val !== false ? (int) $val : 0;
            }
        } catch (\Throwable) {
        }

        try {
            $today = (new \DateTimeImmutable())->format('Ymd');
            $nextWeek = (new \DateTimeImmutable('+7 days'))->format('Ymd');
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM webcal_entry WHERE cal_date >= :today AND cal_date <= :next',
            );
            $stmt->execute(['today' => $today, 'next' => $nextWeek]);
            /** @var numeric-string|false $val */
            $val = $stmt->fetchColumn();
            $upcoming7d = $val !== false ? (int) $val : 0;
        } catch (\Throwable) {
        }

        return ['total' => $total, 'created_7d' => $this->getEventsCreated7d(), 'upcoming_7d' => $upcoming7d];
    }

    private function getEventsCreated7d(): int
    {
        try {
            $cutoff = (new \DateTimeImmutable('-7 days'))->format('Ymd');
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM webcal_entry WHERE cal_mod_date >= :cutoff',
            );
            $stmt->execute(['cutoff' => $cutoff]);
            /** @var numeric-string|false $val */
            $val = $stmt->fetchColumn();
            return $val !== false ? (int) $val : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{db_size_mb: float|null, php_version: string, db_driver: string, recent_errors: int}
     */
    private function getSystemInfo(): array
    {
        $dbSize = null;
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'mysql') {
                $stmt = $this->pdo->query(
                    'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) FROM information_schema.TABLES WHERE table_schema = DATABASE()',
                );
                if ($stmt !== false) {
                    /** @var numeric-string|false $val */
                    $val = $stmt->fetchColumn();
                    $dbSize = $val !== false ? (float) $val : null;
                }
            } elseif ($driver === 'sqlite') {
                $stmt = $this->pdo->query('PRAGMA page_count');
                $pageCount = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
                $stmt2 = $this->pdo->query('PRAGMA page_size');
                $pageSize = $stmt2 !== false ? (int) $stmt2->fetchColumn() : 0;
                $dbSize = round(($pageCount * $pageSize) / 1024 / 1024, 1);
            }
        } catch (\Throwable) {
        }

        $recentErrors = 0;
        try {
            $recentErrors = $this->errorMetrics?->getRecentErrorCount(7) ?? 0;
        } catch (\Throwable) {
        }

        return [
            'db_size_mb' => $dbSize,
            'php_version' => \PHP_VERSION,
            'db_driver' => \is_string($driver) ? $driver : 'unknown',
            'recent_errors' => $recentErrors,
        ];
    }

    /**
     * @return array{reminders_sent_7d: int, agenda_sent_7d: int}
     */
    private function getEmailStats(): array
    {
        $remindersSent = 0;
        $agendaSent = 0;

        try {
            $cutoff = time() - (7 * 86400);
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM reminder_sent WHERE sent_at >= :cutoff');
            $stmt->execute(['cutoff' => $cutoff]);
            /** @var numeric-string|false $val */
            $val = $stmt->fetchColumn();
            $remindersSent = $val !== false ? (int) $val : 0;
        } catch (\Throwable) {
        }

        try {
            $cutoff = time() - (7 * 86400);
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM daily_agenda_sent WHERE sent_at >= :cutoff');
            $stmt->execute(['cutoff' => $cutoff]);
            /** @var numeric-string|false $val */
            $val = $stmt->fetchColumn();
            $agendaSent = $val !== false ? (int) $val : 0;
        } catch (\Throwable) {
        }

        return ['reminders_sent_7d' => $remindersSent, 'agenda_sent_7d' => $agendaSent];
    }
}
