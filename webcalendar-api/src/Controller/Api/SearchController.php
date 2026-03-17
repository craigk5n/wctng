<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\SearchIndexService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SearchController
{
    public function __construct(
        private readonly SearchIndexService $searchService,
    ) {
    }

    #[Route('/api/v2/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $keyword = $request->query->getString('q', '');
        if ($keyword === '') {
            return ApiResponse::error(400, 'Missing required query param: q');
        }

        $type = $request->query->getString('type', '');
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $result = $this->searchService->search(
            $keyword,
            $user->getUserIdentifier(),
            $type !== '' ? $type : null,
            $limit,
            $offset,
        );

        return ApiResponse::success($result['results'], [
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}
