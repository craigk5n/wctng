import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';

export const DEFAULT_EVENT_COLOR = '#3788d8';

export interface ApiCategory {
  id: number;
  name: string;
  color: string | null;
  is_global: boolean;
  owner: string | null;
}

/**
 * Hook that fetches and caches categories.
 * Categories are fetched once and cached for the session.
 */
export function useCategories() {
  const { data, isLoading } = useQuery({
    queryKey: ['categories'],
    queryFn: async () => {
      const result = await api.GET('/categories' as never);
      const parsed = result.data as { data: ApiCategory[] } | undefined;
      return parsed?.data ?? [];
    },
    staleTime: 5 * 60 * 1000, // 5 minutes
  });

  return {
    categories: data ?? [],
    isLoading,
  };
}

/**
 * Returns the color for an event based on its category IDs.
 * Uses the first category's color, or a default if none match.
 */
export function getEventColor(categoryIds: number[], categories: ApiCategory[]): string {
  if (categoryIds.length === 0) {
    return DEFAULT_EVENT_COLOR;
  }

  const firstCatId = categoryIds[0];
  const category = categories.find((c) => c.id === firstCatId);

  return category?.color ?? DEFAULT_EVENT_COLOR;
}
