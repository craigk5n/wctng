import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useApiQuery } from '../useApi';

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };
}

describe('useApiQuery', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('returns loading state initially', () => {
    globalThis.fetch = vi.fn().mockImplementation(
      () => new Promise(() => {}), // never resolves
    );

    const { result } = renderHook(
      () => useApiQuery(['test'], () => Promise.resolve({ data: 'hello', error: undefined })),
      { wrapper: createWrapper() },
    );

    expect(result.current.isLoading).toBe(true);
    expect(result.current.data).toBeUndefined();
  });

  it('returns data on success', async () => {
    const { result } = renderHook(
      () =>
        useApiQuery(['test-success'], () =>
          Promise.resolve({ data: { items: [1, 2, 3] }, error: undefined }),
        ),
      { wrapper: createWrapper() },
    );

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.data).toEqual({ items: [1, 2, 3] });
    expect(result.current.error).toBeNull();
  });

  it('returns error on failure', async () => {
    const { result } = renderHook(
      () =>
        useApiQuery(['test-error'], () =>
          Promise.resolve({ data: undefined, error: { code: 500, message: 'Server error' } }),
        ),
      { wrapper: createWrapper() },
    );

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.data).toBeUndefined();
    expect(result.current.error).toBeTruthy();
  });
});
