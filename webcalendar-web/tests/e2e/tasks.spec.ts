import { test, expect } from '@playwright/test';

// Helper: login as admin
async function login(page: import('@playwright/test').Page) {
  await page.goto('/login');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL('/');
}

// Helper: navigate to tasks page
async function goToTasks(page: import('@playwright/test').Page) {
  await page.getByRole('link', { name: /tasks/i }).click();
  await expect(page).toHaveURL('/tasks');
}

// Generate unique task title
function uniqueTitle(prefix: string) {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
}

// Helper: find the task row containing specific text
function taskRow(page: import('@playwright/test').Page, title: string) {
  return page.locator('.space-y-1 > div').filter({ hasText: title });
}

test('create task via UI and verify it appears in list', async ({ page }) => {
  await login(page);
  await goToTasks(page);

  const title = uniqueTitle('E2E_Create');

  await page.getByRole('button', { name: /new task/i }).click();
  await page.getByLabel('Title').fill(title);
  await page.getByLabel('Due Date').fill('2026-06-15');
  await page.getByRole('button', { name: /^create$/i }).click();

  await expect(page.getByText(title)).toBeVisible({ timeout: 10000 });
});

test('mark task complete and verify status changes', async ({ page }) => {
  await login(page);
  await goToTasks(page);

  const title = uniqueTitle('E2E_Complete');

  await page.getByRole('button', { name: /new task/i }).click();
  await page.getByLabel('Title').fill(title);
  await page.getByRole('button', { name: /^create$/i }).click();
  await expect(page.getByText(title)).toBeVisible({ timeout: 5000 });

  // Click the checkbox to toggle completion
  const row = taskRow(page, title);
  await row.getByRole('checkbox').click();

  // Wait for the API call and refetch — verify strikethrough appears
  await expect(page.locator('.line-through', { hasText: title })).toBeVisible({ timeout: 10000 });
});

test('edit task title', async ({ page }) => {
  await login(page);
  await goToTasks(page);

  const title = uniqueTitle('E2E_Edit');
  const newTitle = uniqueTitle('E2E_Edited');

  await page.getByRole('button', { name: /new task/i }).click();
  await page.getByLabel('Title').fill(title);
  await page.getByRole('button', { name: /^create$/i }).click();
  await expect(page.getByText(title)).toBeVisible({ timeout: 5000 });

  // Click Edit on this specific task row
  await taskRow(page, title).getByRole('button', { name: 'Edit' }).click();

  // Wait for edit input to appear, then change the title
  const editInput = page.getByLabel('Edit task title');
  await expect(editInput).toBeVisible({ timeout: 3000 });
  await editInput.clear();
  await editInput.fill(newTitle);
  await page.getByRole('button', { name: 'Save' }).click();

  // Verify new title appears
  await expect(page.getByText(newTitle)).toBeVisible({ timeout: 5000 });
  await expect(page.getByText(title)).not.toBeVisible();
});

test('delete task', async ({ page }) => {
  await login(page);
  await goToTasks(page);

  const title = uniqueTitle('E2E_Delete');

  await page.getByRole('button', { name: /new task/i }).click();
  await page.getByLabel('Title').fill(title);
  await page.getByRole('button', { name: /^create$/i }).click();
  await expect(page.getByText(title)).toBeVisible({ timeout: 5000 });

  // Delete this specific task
  const row = taskRow(page, title);
  await row.getByRole('button', { name: 'Delete' }).click();

  // Verify task is removed
  await expect(page.getByText(title)).not.toBeVisible({ timeout: 5000 });
});
