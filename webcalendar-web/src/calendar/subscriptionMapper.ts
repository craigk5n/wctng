import type { EventInput } from '@fullcalendar/core';

export interface SubscriptionEvent {
  title: string;
  start: string;
  end: string;
  all_day: boolean;
  description: string;
  location: string;
  source: string;
  color: string;
  read_only: boolean;
}

/**
 * Formats an ICS date string (YYYYMMDD or YYYYMMDDTHHMMSSZ) to ISO format.
 */
function formatIcsDate(dateStr: string): string {
  if (dateStr.length === 8) {
    // YYYYMMDD → YYYY-MM-DD
    return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
  }
  if (dateStr.length >= 15) {
    // YYYYMMDDTHHMMSS[Z] → YYYY-MM-DDTHH:MM:SS
    return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}T${dateStr.slice(9, 11)}:${dateStr.slice(11, 13)}:${dateStr.slice(13, 15)}`;
  }
  return dateStr;
}

export function mapSubscriptionEventsToFullCalendar(events: SubscriptionEvent[]): EventInput[] {
  return events.map((event, index) => ({
    id: `sub-${event.source}-${index}`,
    title: event.title,
    start: formatIcsDate(event.start),
    end: event.end ? formatIcsDate(event.end) : undefined,
    allDay: event.all_day,
    backgroundColor: event.color,
    borderColor: event.color,
    editable: false,
    extendedProps: {
      isSubscription: true,
      source: event.source,
      description: event.description,
      location: event.location,
      read_only: true,
    },
  }));
}
