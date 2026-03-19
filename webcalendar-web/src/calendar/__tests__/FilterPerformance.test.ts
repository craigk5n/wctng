import { describe, it, expect } from 'vitest';

/**
 * Performance test: verify category filter derivation is fast with large event sets.
 */
describe('Category Filter Performance', () => {
  it('filters 1000 events by category in under 10ms', () => {
    // Simulate 1000 events with random categories
    const events = Array.from({ length: 1000 }, (_, i) => ({
      id: String(i),
      title: `Event ${i}`,
      start: '2026-06-15',
      extendedProps: {
        categories: i % 5 === 0 ? [] : [((i % 10) + 1)], // 20% uncategorized
      },
    }));

    const activeCategoryIds = [1, 3, 5, 7, 9]; // 5 of 10 categories active
    const activeSet = new Set(activeCategoryIds);
    const showUncategorized = true;

    const start = performance.now();

    const filtered = events.filter((e) => {
      const catIds = (e.extendedProps?.categories as number[]) ?? [];
      if (catIds.length === 0) return showUncategorized;
      return catIds.some((id) => activeSet.has(id));
    });

    const elapsed = performance.now() - start;

    expect(filtered.length).toBeGreaterThan(0);
    expect(filtered.length).toBeLessThan(1000);
    expect(elapsed).toBeLessThan(10); // Must complete in under 10ms
  });

  it('filters 5000 events in under 50ms', () => {
    const events = Array.from({ length: 5000 }, (_, i) => ({
      id: String(i),
      title: `Event ${i}`,
      start: '2026-06-15',
      extendedProps: {
        categories: [((i % 20) + 1)],
      },
    }));

    const activeCategoryIds = [1, 2, 3, 4, 5];
    const activeSet = new Set(activeCategoryIds);

    const start = performance.now();

    const filtered = events.filter((e) => {
      const catIds = (e.extendedProps?.categories as number[]) ?? [];
      if (catIds.length === 0) return true;
      return catIds.some((id) => activeSet.has(id));
    });

    const elapsed = performance.now() - start;

    expect(filtered.length).toBeGreaterThan(0);
    expect(elapsed).toBeLessThan(50);
  });
});
