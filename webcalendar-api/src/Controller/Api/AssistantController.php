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

final class AssistantController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

    #[Route('/api/v2/users/{login}/assistants', name: 'api_assistants_list', methods: ['GET'])]
    public function list(string $login, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $service = $this->coreServiceFactory->getAssistantService();
        $assistants = $service->getAssistantsForBoss($login);
        $bosses = $service->getBossesForAssistant($login);

        return ApiResponse::success([
            'assistants' => array_map(fn (string $a) => ['login' => $a], $assistants),
            'bosses' => array_map(fn (string $b) => ['login' => $b], $bosses),
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

        /** @var array{assistant?: string} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $asstLogin = $data['assistant'] ?? '';
        if ($asstLogin === '') {
            return ApiResponse::error(400, 'Missing required field: assistant');
        }

        $this->coreServiceFactory->getAssistantService()->assignAssistant($login, $asstLogin);

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

        $this->coreServiceFactory->getAssistantService()->removeAssistant($login, $assistant);

        return ApiResponse::noContent();
    }
}
