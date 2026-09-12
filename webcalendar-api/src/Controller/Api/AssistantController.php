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
use WebCalendar\Core\Application\Service\AssistantService;

final class AssistantController
{
    /** cal_assistant is VARCHAR(60). */
    private const MAX_LOGIN_LENGTH = 60;

    public function __construct(
        private readonly AssistantService $assistantService,
    ) {}

    #[Route('/api/v2/users/{login}/assistants', name: 'api_assistants_list', methods: ['GET'])]
    public function list(string $login, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        // add() and remove() below both refuse this. Without it here, any
        // account could read any other's delegation graph -- who acts for
        // whom across the whole installation -- from a route whose two
        // siblings say you may only manage your own.
        $actor = $user->getCoreUser();
        if ($login !== $actor->login() && !$actor->isAdmin()) {
            return ApiResponse::error(403, 'You can only manage your own assistants');
        }

        $service = $this->assistantService;
        $assistants = $service->getAssistantsForBoss($login);
        $bosses = $service->getBossesForAssistant($login);

        return ApiResponse::success([
            'assistants' => array_map(fn(string $a) => ['login' => $a], $assistants),
            'bosses' => array_map(fn(string $b) => ['login' => $b], $bosses),
        ]);
    }

    #[Route('/api/v2/users/{login}/assistants', name: 'api_assistants_add', methods: ['POST'])]
    public function add(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        // Only the boss or an admin can add assistants
        $actor = $user->getCoreUser();
        if ($login !== $actor->login() && !$actor->isAdmin()) {
            return ApiResponse::error(403, 'You can only manage your own assistants');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        // The annotation that stood here promised a string the client never
        // had to send. A number, an array or a boolean reached a typed
        // parameter and a whitespace-only name reached the entity's own
        // emptiness guard; either way a route that answers in JSON answered
        // with a 500. Trimmed, because a login with spaces around it is one
        // the entity already calls empty and no account could ever match.
        // isset() before is_string() on the offset itself, not on a `?? null`
        // expression: refining the latter leaves the re-read still mixed.
        $asstLogin = isset($data['assistant']) && \is_string($data['assistant'])
            ? trim($data['assistant'])
            : '';
        if ($asstLogin === '') {
            return ApiResponse::error(400, 'Missing required field: assistant');
        }

        // SQLite stores whatever it is given. MySQL runs with
        // STRICT_TRANS_TABLES and answers 1406 Data too long, so past the
        // column width this is a 500 in the deployment that counts and a
        // silent success in the one the tests run against.
        if (mb_strlen($asstLogin) > self::MAX_LOGIN_LENGTH) {
            return ApiResponse::error(400, 'assistant is too long');
        }

        $this->assistantService->assignAssistant($login, $asstLogin);

        return ApiResponse::success(['boss' => $login, 'assistant' => $asstLogin], null, 201);
    }

    #[Route('/api/v2/users/{login}/assistants/{assistant}', name: 'api_assistants_remove', methods: ['DELETE'])]
    public function remove(string $login, string $assistant, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $actor = $user->getCoreUser();
        if ($login !== $actor->login() && !$actor->isAdmin()) {
            return ApiResponse::error(403, 'You can only manage your own assistants');
        }

        $this->assistantService->removeAssistant($login, $assistant);

        return ApiResponse::noContent();
    }
}
