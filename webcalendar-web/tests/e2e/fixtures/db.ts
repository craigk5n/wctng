import { type APIRequestContext } from '@playwright/test';

const BASE_URL = 'http://localhost:47180/api/v2';

/**
 * Seeds a test event via the API.
 * Returns the created event ID.
 */
export async function createTestEvent(
  request: APIRequestContext,
  token: string,
  data: Record<string, unknown> = {},
) {
  const defaults = {
    title: 'E2E Test Event',
    start_date: '20260315',
    start_time: '100000',
    duration: 60,
  };

  const response = await request.post(`${BASE_URL}/events`, {
    headers: { Authorization: `Bearer ${token}` },
    data: { ...defaults, ...data },
  });

  const body = await response.json();
  return body?.data?.id as number;
}

/**
 * Gets an admin JWT token via the API.
 */
export async function getAdminToken(request: APIRequestContext): Promise<string> {
  const response = await request.post(`${BASE_URL}/auth/login`, {
    data: { username: 'admin', password: 'admin' },
  });

  const body = await response.json();
  const token = body?.data?.token;

  if (!token) {
    throw new Error('Failed to get admin token');
  }

  return token as string;
}
