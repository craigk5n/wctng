import { describe, it, expect } from 'vitest';
import { mapSubscriptionEventsToFullCalendar, type SubscriptionEvent } from '../subscriptionMapper';

describe('subscriptionMapper', () => {
  it('maps all-day subscription event', () => {
    const events: SubscriptionEvent[] = [
      { title: 'New Year', start: '20260101', end: '20260102', all_day: true, description: '', location: '', source: 'US Holidays', color: '#ff0000', read_only: true },
    ];

    const result = mapSubscriptionEventsToFullCalendar(events);
    expect(result).toHaveLength(1);
    expect(result[0].title).toBe('New Year');
    expect(result[0].allDay).toBe(true);
    expect(result[0].backgroundColor).toBe('#ff0000');
    expect(result[0].editable).toBe(false);
  });

  it('maps timed subscription event', () => {
    const events: SubscriptionEvent[] = [
      { title: 'Meeting', start: '20260401T140000Z', end: '20260401T150000Z', all_day: false, description: 'Desc', location: 'Room A', source: 'Work Cal', color: '#0000ff', read_only: true },
    ];

    const result = mapSubscriptionEventsToFullCalendar(events);
    expect(result).toHaveLength(1);
    expect(result[0].allDay).toBe(false);
    expect(result[0].extendedProps?.isSubscription).toBe(true);
    expect(result[0].extendedProps?.source).toBe('Work Cal');
  });

  it('sets all events as non-editable', () => {
    const events: SubscriptionEvent[] = [
      { title: 'Holiday', start: '20260704', end: '', all_day: true, description: '', location: '', source: 'Holidays', color: '#f00', read_only: true },
    ];

    const result = mapSubscriptionEventsToFullCalendar(events);
    expect(result[0].editable).toBe(false);
  });
});
