import type { ApiEvent } from './eventMapper';

/**
 * Generates and downloads a single-event ICS file.
 */
export function exportEventAsIcs(event: ApiEvent): void {
  const lines: string[] = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//WebCalendar//WCTNG//EN',
    'BEGIN:VEVENT',
  ];

  if (event.uid) {
    lines.push(`UID:${event.uid}`);
  } else {
    lines.push(`UID:wctng-${event.id}@webcalendar`);
  }

  lines.push(`SUMMARY:${escapeIcsText(event.title)}`);

  // Date/time
  if (event.all_day || !event.start_time) {
    lines.push(`DTSTART;VALUE=DATE:${event.start_date}`);
  } else {
    lines.push(`DTSTART:${event.start_date}T${event.start_time}`);
    if (event.end_date && event.end_time) {
      lines.push(`DTEND:${event.end_date}T${event.end_time}`);
    }
  }

  if (event.duration > 0) {
    lines.push(`DURATION:PT${event.duration}M`);
  }

  if (event.description) {
    lines.push(`DESCRIPTION:${escapeIcsText(event.description)}`);
  }

  if (event.location) {
    lines.push(`LOCATION:${escapeIcsText(event.location)}`);
  }

  lines.push('END:VEVENT');
  lines.push('END:VCALENDAR');

  const icsContent = lines.join('\r\n');
  const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${slugify(event.title)}.ics`;
  a.click();
  URL.revokeObjectURL(url);
}

function escapeIcsText(text: string): string {
  return text
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\n/g, '\\n');
}

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 50) || 'event';
}
