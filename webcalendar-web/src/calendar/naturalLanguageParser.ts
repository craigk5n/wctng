import * as chrono from 'chrono-node';

export interface ParsedEvent {
  title: string;
  startDate?: string; // YYYY-MM-DD
  startTime?: string; // HH:MM
  duration?: number; // minutes
  location?: string;
  participants: string[];
}

/**
 * Parses natural language text into event fields.
 * Uses chrono-node for date/time extraction and regex for location/participants.
 *
 * Examples:
 *   "Lunch with alice tomorrow at noon" → title: "Lunch", participant: "alice", date: tomorrow, time: 12:00
 *   "Meeting at Room A Friday 2pm-3pm" → title: "Meeting", location: "Room A", date: Friday, time: 14:00, duration: 60
 *   "Dentist next Monday at 10am" → title: "Dentist", date: next Monday, time: 10:00
 */
export function parseNaturalLanguage(text: string): ParsedEvent {
  if (!text.trim()) {
    return { title: '', participants: [] };
  }

  const result: ParsedEvent = { title: '', participants: [] };

  // Extract participants ("with alice", "with bob and carol")
  const withMatch = text.match(/\bwith\s+([a-z][a-z0-9_,\s]+?)(?:\s+(?:at|on|tomorrow|today|next|this|\d)|\s*$)/i);
  if (withMatch) {
    const names = withMatch[1].split(/[,\s]+and\s+|[,\s]+/).map((n) => n.trim().toLowerCase()).filter(Boolean);
    result.participants = names;
  }

  // Extract location ("at Room A", "at Café Roma") — but not "at 2pm"
  const atMatch = text.match(/\bat\s+([A-Z][A-Za-zÀ-ÿ\s']+?)(?:\s+(?:on|tomorrow|today|next|this|\d)|\s*$)/);
  if (atMatch) {
    result.location = atMatch[1].trim();
  }

  // Extract date/time using chrono-node
  const parsed = chrono.parse(text);
  if (parsed.length > 0) {
    const ref = parsed[0];
    const startDate = ref.start.date();
    result.startDate = formatDate(startDate);

    if (ref.start.isCertain('hour')) {
      result.startTime = formatTime(startDate);
    }

    // Check for end time (time range)
    if (ref.end) {
      const endDate = ref.end.date();
      const diffMinutes = Math.round((endDate.getTime() - startDate.getTime()) / 60000);
      if (diffMinutes > 0) {
        result.duration = diffMinutes;
      }
    }
  }

  // Build title: remove date/time phrases, "with X", "at Location"
  let title = text;

  // Remove chrono-parsed date/time text
  for (const p of parsed) {
    title = title.replace(p.text, '');
  }

  // Remove "with X" clause
  if (withMatch) {
    title = title.replace(withMatch[0], '');
  }

  // Remove "at Location" clause (but keep "at" before times)
  if (atMatch) {
    title = title.replace(atMatch[0], '');
  }

  // Clean up
  title = title
    .replace(/\s+/g, ' ')
    .replace(/^[\s,\-–]+/, '')
    .replace(/[\s,\-–]+$/, '')
    .trim();

  result.title = title || text.trim();

  return result;
}

function formatDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function formatTime(date: Date): string {
  const h = String(date.getHours()).padStart(2, '0');
  const m = String(date.getMinutes()).padStart(2, '0');
  return `${h}:${m}`;
}
