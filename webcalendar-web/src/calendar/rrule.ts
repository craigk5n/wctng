/**
 * RRULE parsing and formatting, split out of RecurrenceEditor.tsx so that file
 * exports only its component — react-refresh/only-export-components warns
 * otherwise, since a mixed module breaks Fast Refresh.
 */

export type EndType = 'never' | 'count' | 'until';

export const DAYS = [
  { key: 'MO', label: 'Mon' },
  { key: 'TU', label: 'Tue' },
  { key: 'WE', label: 'Wed' },
  { key: 'TH', label: 'Thu' },
  { key: 'FR', label: 'Fri' },
  { key: 'SA', label: 'Sat' },
  { key: 'SU', label: 'Sun' },
];

export function parseRrule(rrule: string): { freq: string; interval: number; byDay: string[]; endType: EndType; count: number; until: string } {
  const parts: Record<string, string> = {};
  for (const part of rrule.split(';')) {
    const [k, v] = part.split('=');
    if (k && v) parts[k.toUpperCase()] = v;
  }
  return {
    freq: parts['FREQ'] ?? 'WEEKLY',
    interval: parseInt(parts['INTERVAL'] ?? '1', 10) || 1,
    byDay: parts['BYDAY'] ? parts['BYDAY'].split(',') : [],
    endType: parts['COUNT'] ? 'count' : parts['UNTIL'] ? 'until' : 'never',
    count: parseInt(parts['COUNT'] ?? '10', 10) || 10,
    until: parts['UNTIL'] ? formatUntilForInput(parts['UNTIL']) : '',
  };
}

function formatUntilForInput(until: string): string {
  // Convert YYYYMMDD to YYYY-MM-DD
  if (until.length >= 8) {
    return `${until.slice(0, 4)}-${until.slice(4, 6)}-${until.slice(6, 8)}`;
  }
  return until;
}

export function rruleToHuman(rrule: string): string {
  if (!rrule) return '';
  const parsed = parseRrule(rrule);
  const freqLabels: Record<string, string> = {
    DAILY: 'day', WEEKLY: 'week', MONTHLY: 'month', YEARLY: 'year',
  };
  const freqLabel = freqLabels[parsed.freq] ?? parsed.freq.toLowerCase();

  let desc = parsed.interval > 1
    ? `Every ${parsed.interval} ${freqLabel}s`
    : `Every ${freqLabel}`;

  if (parsed.byDay.length > 0) {
    const dayNames = parsed.byDay.map((d) => DAYS.find((dd) => dd.key === d)?.label ?? d);
    desc += ` on ${dayNames.join(', ')}`;
  }

  if (parsed.endType === 'count') {
    desc += `, ${parsed.count} times`;
  } else if (parsed.endType === 'until' && parsed.until) {
    desc += `, until ${parsed.until}`;
  }

  return desc;
}
