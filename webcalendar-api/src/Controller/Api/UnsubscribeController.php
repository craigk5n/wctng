<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * One-click email unsubscribe endpoint. No authentication required.
 * Token is HMAC-SHA256 of the user login with APP_SECRET.
 */
final class UnsubscribeController
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        #[\SensitiveParameter]
        private readonly string $appSecret,
    ) {
    }

    #[Route('/api/v2/unsubscribe/{token}', name: 'api_unsubscribe', methods: ['GET'])]
    public function unsubscribe(#[\SensitiveParameter] string $token): Response
    {
        // Find the user this token belongs to
        $login = $this->findLoginByToken($token);
        if ($login === null) {
            return $this->renderPage('Invalid Link', 'This unsubscribe link is invalid or expired.', false);
        }

        // Disable all automated emails for this user
        $this->userRepository->savePreference($login, new UserPreference('REMINDER_MINUTES', '0'));
        $this->userRepository->savePreference($login, new UserPreference('daily_agenda_enabled', 'N'));
        $this->userRepository->savePreference($login, new UserPreference('EMAIL_INVITATION', 'N'));
        $this->userRepository->savePreference($login, new UserPreference('EMAIL_UPDATE', 'N'));

        return $this->renderPage(
            'Unsubscribed',
            'You have been unsubscribed from all WebCalendar email notifications. You can re-enable them in your preferences.',
            true,
        );
    }

    /**
     * Generate a deterministic unsubscribe token for a user.
     */
    public static function generateToken(string $login, #[\SensitiveParameter] string $appSecret): string
    {
        return hash_hmac('sha256', $login, $appSecret);
    }

    private function findLoginByToken(#[\SensitiveParameter] string $token): ?string
    {
        // Check all users to find whose token matches
        try {
            $users = $this->userRepository->findAll();
            foreach ($users as $user) {
                if (self::generateToken($user->login(), $this->appSecret) === $token) {
                    return $user->login();
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function renderPage(string $title, string $message, bool $success): Response
    {
        $icon = $success ? '&#10003;' : '&#10007;';
        $color = $success ? '#22c55e' : '#ef4444';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — WebCalendar</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f8f9fa; color: #1a1a1a; }
        .card { background: white; border-radius: 12px; padding: 2rem; max-width: 400px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .icon { font-size: 3rem; color: {$color}; }
        h1 { margin: 1rem 0 0.5rem; font-size: 1.5rem; }
        p { color: #555; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{$icon}</div>
        <h1>{$title}</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
