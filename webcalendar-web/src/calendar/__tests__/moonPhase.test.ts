import { describe, it, expect } from 'vitest';
import { getMoonPhase } from '../moonPhase';

describe('getMoonPhase', () => {
  it('returns a known new moon date correctly', () => {
    // 2000-01-07 is solidly in the new moon phase
    // (reference new moon is 2000-01-06 18:14 UTC)
    const phase = getMoonPhase(new Date(2000, 0, 7));
    expect(phase.index).toBe(0);
    expect(phase.emoji).toBe('🌑');
    expect(phase.name).toBe('New Moon');
  });

  it('returns full moon ~14.7 days after new moon', () => {
    // ~14.7 days after 2000-01-06 = 2000-01-21
    const phase = getMoonPhase(new Date(2000, 0, 21));
    // Should be full (4) or waxing gibbous (3) — within 1 day tolerance
    expect([3, 4]).toContain(phase.index);
  });

  it('returns a valid phase for any date', () => {
    const phase = getMoonPhase(new Date(2026, 3, 10));
    expect(phase.index).toBeGreaterThanOrEqual(0);
    expect(phase.index).toBeLessThanOrEqual(7);
    expect(phase.emoji).toBeTruthy();
    expect(phase.name).toBeTruthy();
  });

  it('phases cycle over a full synodic month', () => {
    const phases = new Set<number>();
    // Sample every ~3.7 days over 30 days from a known new moon
    for (let d = 0; d < 30; d += 3.7) {
      const date = new Date(2000, 0, 6 + Math.floor(d));
      phases.add(getMoonPhase(date).index);
    }
    // Should hit at least 5 distinct phases
    expect(phases.size).toBeGreaterThanOrEqual(5);
  });

  it('handles dates far in the past', () => {
    const phase = getMoonPhase(new Date(1969, 6, 20)); // Moon landing!
    expect(phase.index).toBeGreaterThanOrEqual(0);
    expect(phase.index).toBeLessThanOrEqual(7);
  });
});
