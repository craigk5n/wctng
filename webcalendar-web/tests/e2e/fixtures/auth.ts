import { type Page } from '@playwright/test';

/**
 * Logs in as the admin user via the login form.
 */
export async function loginAsAdmin(page: Page) {
  await loginAsUser(page, 'admin', 'admin');
}

/**
 * Logs in as a specific user via the login form.
 */
export async function loginAsUser(page: Page, username: string, password: string) {
  await page.goto('/login');
  await page.getByLabel(/username/i).fill(username);
  await page.getByLabel(/password/i).fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();

  // Wait for redirect away from login page
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 10000 });
}

/**
 * Logs in via the API and sets the token in localStorage.
 * Faster than form-based login — use for test setup.
 */
export async function loginViaApi(page: Page, username = 'admin', password = 'admin') {
  const baseUrl = 'http://localhost:47180';
  const response = await page.request.post(`${baseUrl}/api/v2/auth/login`, {
    data: { username, password },
  });

  const body = await response.json();
  const token = body?.data?.token;

  if (!token) {
    throw new Error(`API login failed for ${username}: ${JSON.stringify(body)}`);
  }

  // Set token in localStorage before navigating
  await page.goto('/login');
  await page.evaluate((t) => localStorage.setItem('wctng_token', t), token);
}
