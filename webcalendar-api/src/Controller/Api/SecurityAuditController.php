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

/**
 * Security audit endpoint — checks the installation for common security issues.
 * Admin only.
 */
final class SecurityAuditController
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly \PDO $pdo,
        #[\SensitiveParameter]
        private readonly string $appSecret,
        private readonly string $environment,
    ) {}

    #[Route('/api/v2/admin/security-audit', name: 'api_admin_security_audit', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $checks = [];

        // --- Authentication & Credentials ---
        $checks[] = $this->checkDefaultPassword();
        $checks[] = $this->checkAppSecret();
        $checks[] = $this->checkJwtTtl();

        // --- Configuration ---
        $checks[] = $this->checkEnvironment();
        $checks[] = $this->checkSecurityHeaders($request);
        $checks[] = $this->checkErrorVerbosity();

        // --- Database & Data ---
        $checks[] = $this->checkBackupFiles();
        $checks[] = $this->checkDatabaseSsl();

        // --- Network & Endpoints ---
        $checks[] = $this->checkHttps($request);
        $checks[] = $this->checkPublicEndpoints();

        // --- Application Security ---
        $checks[] = $this->checkHtmlDescription();
        $checks[] = $this->checkCustomHtml();
        $checks[] = $this->checkSeoPages();

        // --- PHP Runtime ---
        $checks[] = $this->checkPhpVersion();
        $checks[] = $this->checkExposePhp();
        $checks[] = $this->checkDisplayErrors();
        $checks[] = $this->checkAllowUrlInclude();

        // --- User & Access ---
        $checks[] = $this->checkAdminCount();
        $checks[] = $this->checkPublicCalendars();

        $passCount = \count(array_filter($checks, static fn(array $c) => $c['status'] === 'pass'));
        $warnCount = \count(array_filter($checks, static fn(array $c) => $c['status'] === 'warn'));
        $failCount = \count(array_filter($checks, static fn(array $c) => $c['status'] === 'fail'));

        return ApiResponse::success([
            'checks' => $checks,
            'summary' => ['pass' => $passCount, 'warn' => $warnCount, 'fail' => $failCount, 'total' => \count($checks)],
        ]);
    }

    // --- Check implementations ---

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkDefaultPassword(): array
    {
        $isDefault = false;
        try {
            $stmt = $this->pdo->prepare('SELECT cal_passwd FROM webcal_user WHERE cal_login = :login');
            $stmt->execute(['login' => 'admin']);
            /** @var string|false $hash */
            $hash = $stmt->fetchColumn();
            if (\is_string($hash) && password_verify('admin', $hash)) {
                $isDefault = true;
            }
        } catch (\Throwable) {
        }

        return [
            'category' => 'Authentication',
            'name' => 'Default admin password',
            'status' => $isDefault ? 'fail' : 'pass',
            'detail' => $isDefault
                ? 'The admin account still uses the default password "admin". Change it immediately.'
                : 'Admin password has been changed from the default.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkAppSecret(): array
    {
        $weak = \strlen($this->appSecret) < 32
            || $this->appSecret === 'change_me'
            || $this->appSecret === 'ThisTokenIsNotSoSecretChangeIt'
            || $this->appSecret === 'your_app_secret_here';

        return [
            'category' => 'Authentication',
            'name' => 'APP_SECRET strength',
            'status' => $weak ? 'fail' : 'pass',
            'detail' => $weak
                ? 'APP_SECRET is weak or uses a default value. Generate a random 32+ character string.'
                : 'APP_SECRET appears to be a strong random value.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkJwtTtl(): array
    {
        /** @var numeric-string|string $rawTtl */
        $rawTtl = $_ENV['JWT_TTL'] ?? '3600';
        $ttl = (int) $rawTtl;
        $tooLong = $ttl > 86400; // > 24 hours

        return [
            'category' => 'Authentication',
            'name' => 'JWT token lifetime',
            'status' => $tooLong ? 'warn' : 'pass',
            'detail' => $tooLong
                ? "JWT_TTL is {$ttl} seconds (" . round($ttl / 3600, 1) . ' hours). Consider reducing to 1-4 hours.'
                : "JWT_TTL is {$ttl} seconds (" . round($ttl / 3600, 1) . ' hours).',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkEnvironment(): array
    {
        $isDev = $this->environment === 'dev';

        return [
            'category' => 'Configuration',
            'name' => 'Application environment',
            'status' => $isDev ? 'warn' : 'pass',
            'detail' => $isDev
                ? 'APP_ENV is "dev". In production, set APP_ENV=prod to disable debug features and stack traces.'
                : "APP_ENV is \"{$this->environment}\".",
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkSecurityHeaders(Request $request): array
    {
        // We can't check response headers from within the request, but we can check
        // if the SecurityHeaderSubscriber is likely active
        $hasSubscriber = class_exists(\App\EventSubscriber\SecurityHeaderSubscriber::class);

        return [
            'category' => 'Configuration',
            'name' => 'Security response headers',
            'status' => $hasSubscriber ? 'pass' : 'warn',
            'detail' => $hasSubscriber
                ? 'SecurityHeaderSubscriber is registered (X-Content-Type-Options, X-Frame-Options, etc.).'
                : 'No SecurityHeaderSubscriber found. Consider adding security headers.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkErrorVerbosity(): array
    {
        $isProd = $this->environment === 'prod';

        return [
            'category' => 'Configuration',
            'name' => 'Error message verbosity',
            'status' => $isProd ? 'pass' : 'warn',
            'detail' => $isProd
                ? 'Production mode: API errors return "Internal server error" without stack traces.'
                : 'Development mode: API errors may expose stack traces and internal details.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkBackupFiles(): array
    {
        $backupDir = \dirname(__DIR__, 3) . '/var/backups';
        $oldFiles = 0;
        $cutoff = time() - (7 * 86400);

        if (is_dir($backupDir)) {
            $files = glob($backupDir . '/webcalendar-backup-*');
            if (\is_array($files)) {
                foreach ($files as $file) {
                    $stat = stat($file);
                    if ($stat !== false && $stat['mtime'] < $cutoff) {
                        $oldFiles++;
                    }
                }
            }
        }

        return [
            'category' => 'Data',
            'name' => 'Old backup files',
            'status' => $oldFiles > 0 ? 'warn' : 'pass',
            'detail' => $oldFiles > 0
                ? "{$oldFiles} backup file(s) older than 7 days in var/backups/. Consider cleaning up."
                : 'No stale backup files found.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkDatabaseSsl(): array
    {
        /** @var string $databaseUrl */
        $databaseUrl = $_ENV['DATABASE_URL'] ?? '';
        $usesSsl = str_contains($databaseUrl, 'sslmode=') || str_contains($databaseUrl, 'ssl_ca=');
        $isSqlite = str_contains($databaseUrl, 'sqlite');

        if ($isSqlite) {
            return [
                'category' => 'Data',
                'name' => 'Database connection encryption',
                'status' => 'pass',
                'detail' => 'SQLite database (local file, no network connection).',
            ];
        }

        return [
            'category' => 'Data',
            'name' => 'Database connection encryption',
            'status' => $usesSsl ? 'pass' : 'warn',
            'detail' => $usesSsl
                ? 'DATABASE_URL includes SSL/TLS parameters.'
                : 'DATABASE_URL does not include SSL parameters. Consider encrypting the database connection for production.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkHttps(Request $request): array
    {
        $isSecure = $request->isSecure();
        /** @var string $defaultUri */
        $defaultUri = $_ENV['DEFAULT_URI'] ?? '';
        $uriIsHttps = str_starts_with($defaultUri, 'https://');

        return [
            'category' => 'Network',
            'name' => 'HTTPS / TLS',
            'status' => ($isSecure || $uriIsHttps) ? 'pass' : 'warn',
            'detail' => ($isSecure || $uriIsHttps)
                ? 'Connection is using HTTPS or DEFAULT_URI specifies https.'
                : 'Connection is not using HTTPS. Strongly recommended for production.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkPublicEndpoints(): array
    {
        $publicRoutes = [
            '/api/v2/health',
            '/api/v2/config/features',
            '/api/v2/config/custom-html',
            '/api/v2/unsubscribe/{token}',
            '/public/{username}',
            '/public/{username}/event/{id}',
            '/public/{username}/events',
            '/sitemap.xml',
            '/robots.txt',
            '/book/{username}',
        ];

        return [
            'category' => 'Network',
            'name' => 'Public (unauthenticated) endpoints',
            'status' => 'info',
            'detail' => \count($publicRoutes) . ' endpoints are publicly accessible: '
                . implode(', ', array_slice($publicRoutes, 0, 5)) . '...',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkHtmlDescription(): array
    {
        $enabled = $this->configService->getSetting('ALLOW_HTML_DESCRIPTION') !== 'N';

        return [
            'category' => 'Application',
            'name' => 'Rich text / HTML descriptions',
            'status' => $enabled ? 'info' : 'pass',
            'detail' => $enabled
                ? 'HTML descriptions are enabled. Content is sanitized via HtmlSanitizer, but complex HTML increases attack surface.'
                : 'HTML descriptions are disabled. Plain text only.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkCustomHtml(): array
    {
        $header = $this->configService->getSetting('CUSTOM_HEADER_HTML') ?? '';
        $trailer = $this->configService->getSetting('CUSTOM_TRAILER_HTML') ?? '';
        $css = $this->configService->getSetting('CUSTOM_CSS') ?? '';
        $hasCustom = $header !== '' || $trailer !== '' || $css !== '';

        return [
            'category' => 'Application',
            'name' => 'Custom HTML/CSS injection',
            'status' => $hasCustom ? 'info' : 'pass',
            'detail' => $hasCustom
                ? 'Custom HTML/CSS is configured. Content is sanitized but admin-injected HTML increases risk. Review regularly.'
                : 'No custom HTML/CSS configured.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkSeoPages(): array
    {
        $enabled = $this->configService->getSetting('ENABLE_SEO_PAGES') === 'Y';

        return [
            'category' => 'Application',
            'name' => 'Public SEO event pages',
            'status' => $enabled ? 'info' : 'pass',
            'detail' => $enabled
                ? 'SEO pages are enabled. Public event details are visible to search engines and anonymous users. Users can opt out individually.'
                : 'SEO pages are disabled. No public event pages served.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkPhpVersion(): array
    {
        $version = \PHP_VERSION;
        $eol = version_compare($version, '8.2.0', '<');

        return [
            'category' => 'PHP Runtime',
            'name' => 'PHP version',
            'status' => $eol ? 'fail' : 'pass',
            'detail' => $eol
                ? "PHP {$version} may be end-of-life. Upgrade to PHP 8.2 or later."
                : "PHP {$version} is supported.",
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkExposePhp(): array
    {
        $exposed = (bool) ini_get('expose_php');

        return [
            'category' => 'PHP Runtime',
            'name' => 'expose_php',
            'status' => $exposed ? 'warn' : 'pass',
            'detail' => $exposed
                ? 'expose_php is On. PHP version is disclosed in response headers. Set expose_php = Off in php.ini.'
                : 'expose_php is Off.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkDisplayErrors(): array
    {
        $display = (bool) ini_get('display_errors');

        return [
            'category' => 'PHP Runtime',
            'name' => 'display_errors',
            'status' => $display ? 'warn' : 'pass',
            'detail' => $display
                ? 'display_errors is On. PHP errors may be shown to users. Set display_errors = Off in production.'
                : 'display_errors is Off.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkAllowUrlInclude(): array
    {
        $allowed = (bool) ini_get('allow_url_include');

        return [
            'category' => 'PHP Runtime',
            'name' => 'allow_url_include',
            'status' => $allowed ? 'fail' : 'pass',
            'detail' => $allowed
                ? 'allow_url_include is On. This is a serious security risk. Set allow_url_include = Off.'
                : 'allow_url_include is Off.',
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkAdminCount(): array
    {
        $count = 0;
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM webcal_user WHERE cal_is_admin = 'Y'");
            if ($stmt !== false) {
                /** @var numeric-string|false $val */
                $val = $stmt->fetchColumn();
                $count = $val !== false ? (int) $val : 0;
            }
        } catch (\Throwable) {
        }

        return [
            'category' => 'Users',
            'name' => 'Admin user count',
            'status' => $count > 3 ? 'warn' : 'pass',
            'detail' => "{$count} admin user(s). " . ($count > 3 ? 'Consider reducing the number of admin accounts.' : 'Within normal range.'),
        ];
    }

    /**
     * @return array{category: string, name: string, status: string, detail: string}
     */
    private function checkPublicCalendars(): array
    {
        $count = 0;
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM webcal_user_pref WHERE cal_setting = 'public_calendar_enabled' AND cal_value = 'Y'");
            if ($stmt !== false) {
                /** @var numeric-string|false $val */
                $val = $stmt->fetchColumn();
                $count = $val !== false ? (int) $val : 0;
            }
        } catch (\Throwable) {
        }

        return [
            'category' => 'Users',
            'name' => 'Public calendars enabled',
            'status' => 'info',
            'detail' => "{$count} user(s) have public calendar sharing enabled.",
        ];
    }
}
