import { describe, it, expect } from 'vitest';
import { mapApiEventToFullCalendar, type ApiEvent } from '../eventMapper';

describe('eventMapper', () => {
  it('maps a timed event to FullCalendar format', () => {
    const apiEvent: ApiEvent = {
      id: 1,
      title: 'Meeting',
      description: 'Team sync',
      start_date: '20260315',
      start_time: '100000',
      end_date: '20260315',
      end_time: '110000',
      duration: 60,
      location: 'Room A',
      access: 'P',
      type: 'E',
      created_by: 'admin',
      all_day: false,
    };

    const result = mapApiEventToFullCalendar(apiEvent);

    expect(result.id).toBe('1');
    expect(result.title).toBe('Meeting');
    expect(result.start).toBe('2026-03-15T10:00:00');
    expect(result.end).toBe('2026-03-15T11:00:00');
    expect(result.allDay).toBe(false);
    expect(result.extendedProps?.description).toBe('Team sync');
    expect(result.extendedProps?.location).toBe('Room A');
  });

  it('maps an all-day event', () => {
    const apiEvent: ApiEvent = {
      id: 2,
      title: 'Holiday',
      description: '',
      start_date: '20260401',
      start_time: null,
      end_date: '20260401',
      end_time: null,
      duration: 0,
      location: '',
      access: 'P',
      type: 'E',
      created_by: 'admin',
      all_day: true,
    };

    const result = mapApiEventToFullCalendar(apiEvent);

    expect(result.start).toBe('2026-04-01');
    expect(result.end).toBeUndefined();
    expect(result.allDay).toBe(true);
  });

  it('includes the original API event in extendedProps', () => {
    const apiEvent: ApiEvent = {
      id: 3,
      title: 'Test',
      description: '',
      start_date: '20260501',
      start_time: '090000',
      end_date: '20260501',
      end_time: '093000',
      duration: 30,
      location: '',
      access: 'R',
      type: 'E',
      created_by: 'user1',
      all_day: false,
    };

    const result = mapApiEventToFullCalendar(apiEvent);

    expect(result.extendedProps?.apiEvent).toEqual(apiEvent);
    expect(result.extendedProps?.access).toBe('R');
  });
});
