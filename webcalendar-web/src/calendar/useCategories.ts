import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export const DEFAULT_EVENT_COLOR = '#3788d8';

export interface ApiCategory {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
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

/**
 * Returns the first non-null emoji icon among the event's categories, or null.
 * Categories are searched in the order provided, so the "primary" category
 * (first id on the event) wins when multiple categories have icons.
 */
export function getEventIcon(categoryIds: number[], categories: ApiCategory[]): string | null {
  for (const id of categoryIds) {
    const cat = categories.find((c) => c.id === id);
    if (cat?.icon) {
      return cat.icon;
    }
  }
  return null;
}
