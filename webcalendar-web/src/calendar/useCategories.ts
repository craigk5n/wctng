import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

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
 */
export function useCategories() {
  const { data, isLoading } = useQuery({
    queryKey: ['categories'],
    queryFn: async (): Promise<ApiCategory[]> => {
      const { data } = await apiFetch<ApiCategory[]>('/categories');
      return data ?? [];
    },
    staleTime: 5 * 60 * 1000,
  });

  return {
    categories: data ?? [],
    isLoading,
  };
}

/**
 * Returns the color for an event based on its category IDs.
 */
export function getEventColor(categoryIds: number[], categories: ApiCategory[]): string {
  if (categoryIds.length === 0) return DEFAULT_EVENT_COLOR;
  const category = categories.find((c) => c.id === categoryIds[0]);
  return category?.color ?? DEFAULT_EVENT_COLOR;
}
