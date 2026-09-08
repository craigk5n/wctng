import { describe, it, expect } from 'vitest';
import { rruleToHuman } from '../rrule';

describe('rruleToHuman', () => {
  it('returns empty for no rrule', () => {
    expect(rruleToHuman('')).toBe('');
  });

  it('describes daily', () => {
    expect(rruleToHuman('FREQ=DAILY')).toBe('Every day');
  });

  it('describes weekly', () => {
    expect(rruleToHuman('FREQ=WEEKLY')).toBe('Every week');
  });

  it('describes monthly', () => {
    expect(rruleToHuman('FREQ=MONTHLY')).toBe('Every month');
  });

  it('describes yearly', () => {
    expect(rruleToHuman('FREQ=YEARLY')).toBe('Every year');
  });

  it('describes interval', () => {
    expect(rruleToHuman('FREQ=WEEKLY;INTERVAL=2')).toBe('Every 2 weeks');
  });

  it('describes by day', () => {
    const result = rruleToHuman('FREQ=WEEKLY;BYDAY=MO,WE,FR');
    expect(result).toContain('Mon');
    expect(result).toContain('Wed');
    expect(result).toContain('Fri');
  });

  it('describes count', () => {
    expect(rruleToHuman('FREQ=DAILY;COUNT=5')).toContain('5 times');
  });

  it('describes until', () => {
    const result = rruleToHuman('FREQ=WEEKLY;UNTIL=20261231');
    expect(result).toContain('until');
  });
});
