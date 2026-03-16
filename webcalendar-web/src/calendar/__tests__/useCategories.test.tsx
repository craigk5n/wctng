import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useCategories, DEFAULT_EVENT_COLOR, getEventColor, type ApiCategory } from '../useCategories';

vi.mock('../../api/client', () => ({
  api: {
    GET: vi.fn(),
  },
  TOKEN_STORAGE_KEY: 'wctng_token',
  createApiClient: vi.fn(),
}));

import { api } from '../../api/client';

const mockGet = vi.mocked(api.GET);

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: 0 } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };
}

describe('useCategories', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('fetches categories and returns them', async () => {
    const cats: ApiCategory[] = [
      { id: 1, name: 'Work', color: '#FF0000', is_global: true, owner: null },
      { id: 2, name: 'Personal', color: '#00FF00', is_global: false, owner: 'admin' },
    ];

    mockGet.mockResolvedValue({
      data: { data: cats },
      error: undefined,
      response: new Response(),
    } as never);

    const { result } = renderHook(() => useCategories(), { wrapper: createWrapper() });

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.categories).toHaveLength(2);
    expect(result.current.categories[0].name).toBe('Work');
  });

  it('returns empty array on error', async () => {
    mockGet.mockResolvedValue({
      data: undefined,
      error: { code: 500, message: 'Error' },
      response: new Response(),
    } as never);

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
    const color = getEventColor([1], categories);
    expect(color).toBe('#FF0000');
  });

  it('returns default color for uncategorized events', () => {
    const color = getEventColor([], categories);
    expect(color).toBe(DEFAULT_EVENT_COLOR);
  });

  it('returns default color when category not found', () => {
    const color = getEventColor([999], categories);
    expect(color).toBe(DEFAULT_EVENT_COLOR);
  });

  it('uses first category color when event has multiple categories', () => {
    const color = getEventColor([2, 1], categories);
    expect(color).toBe('#00FF00');
  });
});
