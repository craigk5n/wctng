/**
 * Build a multi-line plain-text tooltip string for a FullCalendar event.
 *
 * Used via FullCalendar's `eventDidMount` to populate the native `title`
 * attribute on event elements. The native tooltip is accessible by default
 * (screen readers announce it, keyboard focus triggers it, mobile long-press
 * works), requires zero additional dependencies, and lets users see the
 * full title + context for truncated events in month/week/day views.
 *
 * A richer, styled tooltip (Radix / Floating UI) with category icon and
 * formatted layout is tracked as a follow-up story.
 */

export interface TooltipEventInput {
  title: string;
  start: Date | null;
  end: Date | null;
  allDay: boolean;
  location?: string;
  description?: string;
}

const MAX_DESCRIPTION_CHARS = 120;

export function buildEventTooltip(event: TooltipEventInput): string {
  const lines: string[] = [];

  if (event.title) {
    lines.push(event.title);
  }

  const timeLine = formatTimeRange(event);
  if (timeLine) {
    lines.push(timeLine);
  }

  if (event.location && event.location.trim() !== '') {
    lines.push(`📍 ${event.location.trim()}`);
  }

  if (event.description) {
    const snippet = stripHtmlToSnippet(event.description, MAX_DESCRIPTION_CHARS);
    if (snippet !== '') {
      lines.push('');
      lines.push(snippet);
    }
  }

  return lines.join('\n');
}

function formatTimeRange(event: TooltipEventInput): string {
  if (event.allDay) {
    return 'All day';
  }
  if (!event.start) {
    return '';
  }
  const startStr = formatTime(event.start);
  if (!event.end) {
    return startStr;
  }
  return `${startStr} – ${formatTime(event.end)}`;
}

function formatTime(date: Date): string {
  return date.toLocaleTimeString(undefined, {
    hour: 'numeric',
    minute: '2-digit',
  });
}

/**
 * Strip HTML tags, decode a handful of common entities, collapse whitespace,
 * and truncate with an ellipsis. Kept intentionally lightweight — rich text
 * descriptions are sanitized upstream so this is only defensive formatting
 * for the tooltip preview.
 */
export function stripHtmlToSnippet(html: string, maxChars: number): string {
  const stripped = html
    .replace(/<\s*br\s*\/?\s*>/gi, ' ')
    .replace(/<\/(p|div|li|h[1-6])>/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")
    .replace(/\s+/g, ' ')
    .trim();

  if (stripped.length <= maxChars) {
    return stripped;
  }
  return stripped.slice(0, maxChars - 1).trimEnd() + '…';
}
