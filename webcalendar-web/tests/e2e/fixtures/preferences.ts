import { type Page } from '@playwright/test';

/**
 * Opens the preferences page and waits until its form holds the saved values.
 *
 * The form starts from defaults and is filled when GET /preferences answers.
 * A field changed before that is overwritten by the answer, and Save then
 * sends the stored value back, so the change is silently lost. Waiting for
 * one GET is not enough: the dev build (StrictMode) sends two, and on a slow
 * Vite dev server the second can land after the test has changed a field.
 */
export async function openPreferences(page: Page) {
  await page.goto('/settings/preferences');
  await preferencesLoaded(page);
}

/** Reloads the preferences page and waits for the form like openPreferences(). */
export async function reloadPreferences(page: Page) {
  await page.reload();
  await preferencesLoaded(page);
}

/**
 * Saves the preferences form and waits for the PUT to succeed.
 */
export async function savePreferences(page: Page) {
  const saved = page.waitForResponse(
    (r) => r.url().includes('/preferences') && r.request().method() === 'PUT' && r.ok(),
    { timeout: 10000 },
  );
  await page.getByRole('button', { name: /save/i }).click();
  await saved;
}

async function preferencesLoaded(page: Page) {
  // networkidle covers every load request however many the build sends; the
  // Vite HMR websocket does not count against it.
  await page.waitForLoadState('networkidle');
}
