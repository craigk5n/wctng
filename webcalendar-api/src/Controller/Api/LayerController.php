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
use WebCalendar\Core\Application\Service\LayerService;
use WebCalendar\Core\Domain\Entity\Layer;

final class LayerController
{
    public function __construct(
        private readonly LayerService $layerService,
        private readonly \PDO $pdo,
    ) {
    }

    #[Route('/api/v2/layers', name: 'api_layers_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $layers = $this->layerService->getLayersForUser($user->getUserIdentifier());
        $items = array_map(self::layerToArray(...), $layers);

        return ApiResponse::success(array_values($items));
    }

    #[Route('/api/v2/layers', name: 'api_layers_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $sourceUser = isset($data['source_user']) && \is_string($data['source_user']) ? $data['source_user'] : null;
        if ($sourceUser === null || $sourceUser === '') {
            return ApiResponse::error(400, 'Missing required field: source_user');
        }

        $color = isset($data['color']) && \is_string($data['color']) ? $data['color'] : '#3788d8';
        $id = random_int(100000, 2147483000);

        $layer = new Layer(
            id: $id,
            owner: $user->getUserIdentifier(),
            layerUser: $sourceUser,
            color: $color,
        );

        $this->layerService->addLayer($layer);

        // Core's save() doesn't include cal_layerid in INSERT — set it after
        $stmt = $this->pdo->prepare(
            'UPDATE webcal_user_layers SET cal_layerid = :id WHERE cal_login = :owner AND cal_layeruser = :layeruser',
        );
        $stmt->execute(['id' => $id, 'owner' => $user->getUserIdentifier(), 'layeruser' => $sourceUser]);

        return ApiResponse::success(self::layerToArray($layer), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/layers/{id}', name: 'api_layers_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        // Find the existing layer
        $layers = $this->layerService->getLayersForUser($user->getUserIdentifier());
        $existing = null;
        foreach ($layers as $l) {
            if ($l->id() === $id) {
                $existing = $l;
                break;
            }
        }

        if ($existing === null) {
            return ApiResponse::error(404, 'Layer not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $color = isset($data['color']) && \is_string($data['color']) ? $data['color'] : $existing->color();

        $updated = new Layer(
            id: $existing->id(),
            owner: $existing->owner(),
            layerUser: $existing->layerUser(),
            color: $color,
        );

        $this->layerService->addLayer($updated);

        return ApiResponse::success(self::layerToArray($updated));
    }

    #[Route('/api/v2/layers/{id}', name: 'api_layers_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $this->layerService->deleteLayer($id);

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function layerToArray(Layer $layer): array
    {
        return [
            'id' => $layer->id(),
            'source_user' => $layer->layerUser(),
            'color' => $layer->color(),
            'show_duplicates' => $layer->showDuplicates(),
        ];
    }
}
