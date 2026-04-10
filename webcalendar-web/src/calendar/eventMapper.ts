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
  exdates?: string[];
  categories?: number[];
  participants?: Array<{ login: string; status: string }>;
  ext_participants?: Array<{ name: string; email: string | null }>;
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

  const result: EventInput = {
    id: String(event.id),
    title: isRecurring ? `🔁 ${event.title}` : event.title,
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

  // For recurring events with an RRULE, let FullCalendar's rrule plugin
  // handle expansion so every occurrence renders on the calendar.
  if (isRecurring && event.rrule) {
    // Build the rrule string with DTSTART prefix
    const dtstart = isAllDay
      ? `DTSTART;VALUE=DATE:${event.start_date}`
      : `DTSTART:${event.start_date}T${event.start_time ?? '000000'}`;
    // Build full iCal recurrence block: DTSTART + RRULE + EXDATEs
    let rruleBlock = `${dtstart}\nRRULE:${event.rrule}`;
    if (event.exdates && event.exdates.length > 0) {
      const exdateStr = event.exdates
        .map((d) => (isAllDay ? d : `${d}T${event.start_time ?? '000000'}`))
        .join(',');
      rruleBlock += `\nEXDATE${isAllDay ? ';VALUE=DATE' : ''}:${exdateStr}`;
    }
    result.rrule = rruleBlock;

    // Duration for FullCalendar to size each occurrence
    const hours = Math.floor(event.duration / 60);
    const mins = event.duration % 60;
    result.duration = `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;

    // Don't set start/end — rrule plugin generates them
  } else {
    result.start = start;
    result.end = end;
  }

  return result;
}

/**
 * Maps an array of API events to FullCalendar EventInput objects.
 */
export function mapApiEventsToFullCalendar(events: ApiEvent[]): EventInput[] {
  return events.map(mapApiEventToFullCalendar);
}
