import { describe, it, expect } from 'vitest';
import { queryClient } from '../queryClient';

describe('queryClient', () => {
  it('is configured with stale time of 60 seconds', () => {
    const defaults = queryClient.getDefaultOptions();
    expect(defaults.queries?.staleTime).toBe(60_000);
  });

  it('is configured with retry of 1', () => {
    const defaults = queryClient.getDefaultOptions();
    expect(defaults.queries?.retry).toBe(1);
  });

  it('is a valid QueryClient', () => {
    expect(queryClient).toBeDefined();
    expect(queryClient.getQueryCache).toBeDefined();
  });
});
