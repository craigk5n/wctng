import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useCategories, DEFAULT_EVENT_COLOR, getEventColor, type ApiCategory } from '../useCategories';

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: 0 } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };
}

describe('useCategories', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('fetches categories and returns them', async () => {
    const cats: ApiCategory[] = [
      { id: 1, name: 'Work', color: '#FF0000', is_global: true, owner: null },
      { id: 2, name: 'Personal', color: '#00FF00', is_global: false, owner: 'admin' },
    ];

    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: cats }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const { result } = renderHook(() => useCategories(), { wrapper: createWrapper() });

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.categories).toHaveLength(2);
    expect(result.current.categories[0].name).toBe('Work');
  });

  it('returns empty array on error', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response('Error', { status: 500 }),
    );

    const { result } = renderHook(() => useCategories(), { wrapper: createWrapper() });

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.categories).toEqual([]);
  });
});

describe('getEventColor', () => {
  const categories: ApiCategory[] = [
    { id: 1, name: 'Work', color: '#FF0000', is_global: true, owner: null },
    { id: 2, name: 'Personal', color: '#00FF00', is_global: false, owner: 'admin' },
  ];

  it('returns category color when event has matching category', () => {
    expect(getEventColor([1], categories)).toBe('#FF0000');
  });

  it('returns default color for uncategorized events', () => {
    expect(getEventColor([], categories)).toBe(DEFAULT_EVENT_COLOR);
  });

  it('returns default color when category not found', () => {
    expect(getEventColor([999], categories)).toBe(DEFAULT_EVENT_COLOR);
  });

  it('uses first category color when event has multiple categories', () => {
    expect(getEventColor([2, 1], categories)).toBe('#00FF00');
  });
});
