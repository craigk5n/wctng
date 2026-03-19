import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * WCAG 2.1 AA Accessibility tests using axe-core.
 *
 * These tests require the full application to be running.
 * Run with: npx playwright test e2e/accessibility.spec.ts
 *
 * Note: These tests are designed to be run as part of the E2E suite
 * with a running Docker environment. They can be skipped in CI if
 * the Docker environment is not available.
 */

const BASE_URL = process.env.BASE_URL ?? 'http://localhost:47180';

test.describe('Accessibility Audit (WCAG 2.1 AA)', () => {
  test.beforeEach(async ({ page }) => {
    // Login
    await page.goto(`${BASE_URL}/login`);
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/');
  });

  test('Calendar page has no critical violations', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForSelector('.fc'); // Wait for FullCalendar to render

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .exclude('.fc') // Exclude FullCalendar internals (third-party)
      .analyze();

    const critical = results.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    );

    expect(critical, `Found ${critical.length} critical/serious violations:\n${
      critical.map((v) => `  - ${v.id}: ${v.description} (${v.nodes.length} nodes)`).join('\n')
    }`).toHaveLength(0);
  });

  test('Settings page has no critical violations', async ({ page }) => {
    await page.goto(`${BASE_URL}/settings/preferences`);
    await page.waitForSelector('h2');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .analyze();

    const critical = results.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    );

    expect(critical, `Found ${critical.length} critical/serious violations:\n${
      critical.map((v) => `  - ${v.id}: ${v.description} (${v.nodes.length} nodes)`).join('\n')
    }`).toHaveLength(0);
  });

  test('Admin settings page has no critical violations', async ({ page }) => {
    await page.goto(`${BASE_URL}/admin/settings`);
    await page.waitForSelector('h2');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .analyze();

    const critical = results.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    );

    expect(critical, `Found ${critical.length} critical/serious violations:\n${
      critical.map((v) => `  - ${v.id}: ${v.description} (${v.nodes.length} nodes)`).join('\n')
    }`).toHaveLength(0);
  });
});
