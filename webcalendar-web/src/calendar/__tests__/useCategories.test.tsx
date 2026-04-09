import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useCategories, DEFAULT_EVENT_COLOR, getEventColor, getEventIcon, type ApiCategory } from '../useCategories';

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
      { id: 1, name: 'Work', color: '#FF0000', icon: null, is_global: true, owner: null },
      { id: 2, name: 'Personal', color: '#00FF00', icon: null, is_global: false, owner: 'admin' },
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
    { id: 1, name: 'Work', color: '#FF0000', icon: null, is_global: true, owner: null },
    { id: 2, name: 'Personal', color: '#00FF00', icon: null, is_global: false, owner: 'admin' },
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

describe('getEventIcon', () => {
  const categories: ApiCategory[] = [
    { id: 1, name: 'Birthday', color: '#FF0000', icon: '🎂', is_global: true, owner: null },
    { id: 2, name: 'Work', color: '#00FF00', icon: null, is_global: true, owner: null },
    { id: 3, name: 'Travel', color: '#0000FF', icon: '✈️', is_global: true, owner: null },
  ];

  it('returns the icon of the first matching category', () => {
    expect(getEventIcon([1], categories)).toBe('🎂');
  });

  it('returns null for uncategorized events', () => {
    expect(getEventIcon([], categories)).toBeNull();
  });

  it('returns null when the category is unknown', () => {
    expect(getEventIcon([999], categories)).toBeNull();
  });

  it('returns null when the first category has no icon', () => {
    // Respects primary-category precedence — does not fall through to later
    // categories even if they happen to have icons.
    expect(getEventIcon([2], categories)).toBeNull();
  });

  it('skips categories with no icon and picks the next one that has one', () => {
    // When the event references multiple categories, the first one with
    // a non-null icon wins.
    expect(getEventIcon([2, 3], categories)).toBe('✈️');
  });

  it('primary category wins when multiple have icons', () => {
    expect(getEventIcon([3, 1], categories)).toBe('✈️');
  });
});
