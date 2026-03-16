import { test, expect } from '@playwright/test';

test('login with valid credentials redirects to calendar', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL('/');
  await expect(page.getByText('admin')).toBeVisible();
});

test('login with invalid credentials shows error', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('wrongpassword');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByText(/invalid|incorrect|error/i)).toBeVisible();
  await expect(page).toHaveURL(/.*login/);
});

test('logout returns to login page', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL('/');
  await page.getByRole('button', { name: /log\s*out/i }).click();
  await expect(page).toHaveURL(/.*login/);
});

test('protected route redirects to login', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/.*login/);
});

test('session persists across page reload', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL('/');
  await page.reload();
  await expect(page).toHaveURL('/');
  await expect(page.getByText('admin')).toBeVisible();
});
