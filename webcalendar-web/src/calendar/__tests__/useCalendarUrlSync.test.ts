import { describe, it, expect } from 'vitest';
import { parseCalendarParams, buildCalendarUrl } from '../useCalendarUrlSync';

describe('Calendar URL Sync', () => {
  it('parses view from URL search params', () => {
    const params = new URLSearchParams('?view=timeGridWeek&date=2026-03-15');
    const result = parseCalendarParams(params);

    expect(result.view).toBe('timeGridWeek');
    expect(result.date).toBe('2026-03-15');
  });

  it('returns defaults when params are empty', () => {
    const params = new URLSearchParams('');
    const result = parseCalendarParams(params);

    expect(result.view).toBe('dayGridMonth');
    expect(result.date).toBeNull();
  });

  it('builds URL with view and date', () => {
    const url = buildCalendarUrl('timeGridDay', '2026-04-01');
    expect(url).toBe('?view=timeGridDay&date=2026-04-01');
  });

  it('builds URL with view only when date is null', () => {
    const url = buildCalendarUrl('dayGridMonth', null);
    expect(url).toBe('?view=dayGridMonth');
  });

  it('validates view names', () => {
    const params = new URLSearchParams('?view=invalidView');
    const result = parseCalendarParams(params);
    expect(result.view).toBe('dayGridMonth'); // falls back to default
  });
});
