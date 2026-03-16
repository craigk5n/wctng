import type { ApiEvent } from './eventMapper';

interface EventDialogValues {
  title: string;
  description: string;
  location: string;
  access: string;
  duration: number;
  start_date_display: string; // YYYY-MM-DD
  start_time_display: string; // HH:MM or ''
}

function formatDateDisplay(yyyymmdd: string): string {
  return `${yyyymmdd.slice(0, 4)}-${yyyymmdd.slice(4, 6)}-${yyyymmdd.slice(6, 8)}`;
}

function formatTimeDisplay(hhmmss: string | null): string {
  if (!hhmmss) return '';
  return `${hhmmss.slice(0, 2)}:${hhmmss.slice(2, 4)}`;
}

/**
 * Converts an API event to initial values for the EventDialog form.
 */
export function apiEventToInitialValues(event: ApiEvent): EventDialogValues {
  return {
    title: event.title,
    description: event.description,
    location: event.location,
    access: event.access,
    duration: event.duration,
    start_date_display: formatDateDisplay(event.start_date),
    start_time_display: formatTimeDisplay(event.start_time),
  };
}
