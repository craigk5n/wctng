import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from './schema';

export const TOKEN_STORAGE_KEY = 'wctng_token';

/**
 * Auth middleware that injects the stored JWT token into every request.
 */
const authMiddleware: Middleware = {
  async onRequest({ request }) {
    const token = localStorage.getItem(TOKEN_STORAGE_KEY);
    if (token) {
      request.headers.set('Authorization', `Bearer ${token}`);
    }
    return request;
  },
  async onResponse({ request, response }) {
    // On 401, redirect to login (token expired or invalid)
    // Skip redirect for login endpoint — it handles 401 itself
    if (response.status === 401 && !request.url.includes('/auth/login')) {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
      if (typeof window !== 'undefined' && !window.location.pathname.includes('/login')) {
        window.location.href = '/login';
      }
    }
    return response;
  },
};

/**
 * Creates a configured openapi-fetch client for the WebCalendar API.
 *
 * @param baseUrl - Override the API base URL (defaults to VITE_API_URL env var)
 */
export function createApiClient(baseUrl?: string) {
  const client = createClient<paths>({
    baseUrl: baseUrl ?? import.meta.env.VITE_API_URL ?? '/api/v2',
  });

  client.use(authMiddleware);

  return client;
}

/**
 * Default API client instance for use throughout the app.
 */
export const api = createApiClient();

/**
 * Returns the API base URL.
 */
export function getApiBaseUrl(): string {
  return import.meta.env.VITE_API_URL ?? '/api/v2';
}

/**
 * Returns auth headers with the stored JWT token.
 */
export function getAuthHeaders(): Record<string, string> {
  const token = localStorage.getItem(TOKEN_STORAGE_KEY);
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;
  return headers;
}

/**
 * Handles a 401 response by clearing the stored token and sending the user
 * back to the login page. Mirrors the openapi-fetch `authMiddleware` so that
 * plain `apiFetch` callers get the same expired-token handling.
 *
 * Skips the redirect for /auth/login itself (which surfaces bad-credential
 * errors inline) and when we're already on the /login route.
 */
function handleUnauthorized(path: string): void {
  if (path.includes('/auth/login')) return;
  localStorage.removeItem(TOKEN_STORAGE_KEY);
  if (typeof window !== 'undefined' && !window.location.pathname.includes('/login')) {
    window.location.href = '/login';
  }
}

/**
 * Typed fetch wrapper for the WebCalendar API.
 * Uses plain fetch (reliable) with the standard envelope response format.
 */
export async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<{ data: T | null; error: { code: number; message: string } | null }> {
  const baseUrl = getApiBaseUrl();
  const headers = { ...getAuthHeaders(), ...options.headers };

  try {
    const res = await fetch(`${baseUrl}${path}`, { ...options, headers });

    if (res.status === 401) {
      handleUnauthorized(path);
      // Still parse the body so callers that want to surface a specific
      // auth-error message (e.g. the login form) can do so.
      let body: unknown = null;
      try {
        body = await res.json();
      } catch {
        // ignore — response may be empty
      }
      const parsed = body as { error?: { code?: number; message?: string } } | null;
      return {
        data: null,
        error: {
          code: 401,
          message: parsed?.error?.message ?? 'Your session has expired. Please log in again.',
        },
      };
    }

    if (res.status === 204) {
      return { data: null, error: null };
    }

    const body = await res.json();

    if (!res.ok) {
      return { data: null, error: body?.error ?? { code: res.status, message: 'Request failed' } };
    }

    return { data: body?.data as T, error: null };
  } catch (err) {
    return {
      data: null,
      error: { code: 0, message: err instanceof Error ? err.message : 'Network error' },
    };
  }
}
