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
