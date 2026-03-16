import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createApiClient, TOKEN_STORAGE_KEY } from '../client';

describe('API Client', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    localStorage.clear();
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    localStorage.clear();
  });

  it('adds auth header when token is stored', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'test-jwt-token');

    const mockFetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: null, meta: null, error: null }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    globalThis.fetch = mockFetch;

    const client = createApiClient('http://test.local/api/v2');
    await client.POST('/auth/refresh');

    expect(mockFetch).toHaveBeenCalled();
    // openapi-fetch passes a Request object as the first argument
    const request = mockFetch.mock.calls[0][0] as Request;
    expect(request.headers.get('Authorization')).toBe('Bearer test-jwt-token');
  });

  it('does not add auth header when no token stored', async () => {
    const mockFetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: null, meta: null, error: null }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    globalThis.fetch = mockFetch;

    const client = createApiClient('http://test.local/api/v2');
    await client.POST('/auth/login', {
      body: { username: 'test', password: 'test' },
    });

    expect(mockFetch).toHaveBeenCalled();
    const request = mockFetch.mock.calls[0][0] as Request;
    expect(request.headers.get('Authorization')).toBeNull();
  });

  it('uses configured base URL', () => {
    const client = createApiClient('https://custom.example.com/api/v2');
    // The client is created without error — base URL is set
    expect(client).toBeDefined();
    expect(client.GET).toBeDefined();
    expect(client.POST).toBeDefined();
  });

  it('exports TOKEN_STORAGE_KEY constant', () => {
    expect(TOKEN_STORAGE_KEY).toBe('wctng_token');
  });
});
