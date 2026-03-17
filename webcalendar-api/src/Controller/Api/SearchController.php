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

        $filters = [];
        $start = $request->query->getString('start', '');
        $end = $request->query->getString('end', '');
        $categoryId = $request->query->getString('category_id', '');
        $participant = $request->query->getString('participant', '');
        if ($start !== '') { $filters['start'] = $start; }
        if ($end !== '') { $filters['end'] = $end; }
        if ($categoryId !== '') { $filters['category_id'] = $categoryId; }
        if ($participant !== '') { $filters['participant'] = $participant; }

        $result = $this->searchService->search(
            $keyword,
            $user->getUserIdentifier(),
            $type !== '' ? $type : null,
            $limit,
            $offset,
            $filters,
        );

        return ApiResponse::success($result['results'], [
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    #[Route('/api/v2/search/suggest', name: 'api_search_suggest', methods: ['GET'])]
    public function suggest(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $prefix = $request->query->getString('q', '');
        if (\strlen($prefix) < 2) {
            return ApiResponse::success([]);
        }

        $suggestions = $this->searchService->suggest($prefix, $user->getUserIdentifier(), 5);

        return ApiResponse::success($suggestions);
    }
}
