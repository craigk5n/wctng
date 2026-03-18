import type { EventInput } from '@fullcalendar/core';

/**
 * API event shape from GET /api/v2/events
 */
export interface ApiEvent {
  id: number;
  title: string;
  description: string;
  start_date: string; // YYYYMMDD
  start_time: string | null; // HHMMSS or null for all-day
  end_date: string; // YYYYMMDD
  end_time: string | null; // HHMMSS or null for all-day
  duration: number;
  location: string;
  access: string;
  type: string;
  created_by: string;
  all_day: boolean;
  uid?: string;
  sequence?: number;
  status?: string | null;
  rrule?: string | null;
  categories?: number[];
  participants?: Array<{ login: string; status: string }>;
  latitude?: number;
  longitude?: number;
}

/**
 * Parses YYYYMMDD string to YYYY-MM-DD
 */
function formatDate(dateStr: string): string {
  return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
}

/**
 * Parses HHMMSS string to HH:MM:SS
 */
function formatTime(timeStr: string): string {
  return `${timeStr.slice(0, 2)}:${timeStr.slice(2, 4)}:${timeStr.slice(4, 6)}`;
}

/**
 * Maps an API event to a FullCalendar EventInput object.
 */
export function mapApiEventToFullCalendar(event: ApiEvent): EventInput {
  const isAllDay = event.all_day || !event.start_time;

  let start: string;
  let end: string | undefined;

  if (isAllDay) {
    start = formatDate(event.start_date);
    end = undefined;
  } else {
    start = `${formatDate(event.start_date)}T${formatTime(event.start_time!)}`;
    if (event.end_time) {
      end = `${formatDate(event.end_date)}T${formatTime(event.end_time)}`;
    }
  }

  const isRecurring = event.type === 'M' || !!event.rrule;

  return {
    id: String(event.id),
    title: isRecurring ? `🔁 ${event.title}` : event.title,
    start,
    end,
    allDay: isAllDay,
    extendedProps: {
      description: event.description,
      location: event.location,
      access: event.access,
      type: event.type,
      created_by: event.created_by,
      categories: event.categories ?? [],
      apiEvent: event,
    },
  };
}

/**
 * Maps an array of API events to FullCalendar EventInput objects.
 */
export function mapApiEventsToFullCalendar(events: ApiEvent[]): EventInput[] {
  return events.map(mapApiEventToFullCalendar);
}
