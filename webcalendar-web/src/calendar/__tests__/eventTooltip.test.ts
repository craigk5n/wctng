import { describe, it, expect } from 'vitest';
import { buildEventTooltip, stripHtmlToSnippet } from '../eventTooltip';

describe('buildEventTooltip', () => {
  it('includes the title on the first line', () => {
    const tip = buildEventTooltip({
      title: 'Team standup',
      start: new Date('2026-04-08T09:00:00'),
      end: new Date('2026-04-08T09:30:00'),
      allDay: false,
    });
    expect(tip.split('\n')[0]).toBe('Team standup');
  });

  it('shows "All day" for all-day events', () => {
    const tip = buildEventTooltip({
      title: 'Conference',
      start: new Date('2026-04-08T00:00:00'),
      end: null,
      allDay: true,
    });
    expect(tip).toContain('All day');
  });

  it('shows time range for timed events', () => {
    const tip = buildEventTooltip({
      title: 'Meeting',
      start: new Date('2026-04-08T09:00:00'),
      end: new Date('2026-04-08T10:00:00'),
      allDay: false,
    });
    // Locale-independent: just check separator presence
    expect(tip).toMatch(/–/);
  });

  it('includes location with pin prefix when present', () => {
    const tip = buildEventTooltip({
      title: 'Offsite',
      start: new Date('2026-04-08T09:00:00'),
      end: null,
      allDay: false,
      location: 'Conference Room B',
    });
    expect(tip).toContain('📍 Conference Room B');
  });

  it('omits location line when empty or whitespace', () => {
    const tip = buildEventTooltip({
      title: 'X',
      start: new Date('2026-04-08T09:00:00'),
      end: null,
      allDay: false,
      location: '   ',
    });
    expect(tip).not.toContain('📍');
  });

  it('appends description snippet separated by blank line', () => {
    const tip = buildEventTooltip({
      title: 'Planning',
      start: new Date('2026-04-08T09:00:00'),
      end: null,
      allDay: false,
      description: 'Quarterly planning session',
    });
    expect(tip).toContain('\n\nQuarterly planning session');
  });

  it('omits description when empty', () => {
    const tip = buildEventTooltip({
      title: 'X',
      start: new Date('2026-04-08T09:00:00'),
      end: null,
      allDay: false,
      description: '',
    });
    expect(tip.split('\n\n').length).toBe(1);
  });

  it('handles missing start gracefully', () => {
    const tip = buildEventTooltip({
      title: 'Orphan',
      start: null,
      end: null,
      allDay: false,
    });
    expect(tip).toContain('Orphan');
  });
});

describe('stripHtmlToSnippet', () => {
  it('strips simple tags', () => {
    expect(stripHtmlToSnippet('<p>Hello <b>world</b></p>', 120)).toBe('Hello world');
  });

  it('decodes common entities', () => {
    expect(stripHtmlToSnippet('Tom &amp; Jerry &lt;3', 120)).toBe('Tom & Jerry <3');
  });

  it('collapses whitespace', () => {
    expect(stripHtmlToSnippet('a\n\n\nb    c', 120)).toBe('a b c');
  });

  it('truncates with ellipsis', () => {
    const long = 'a'.repeat(200);
    const result = stripHtmlToSnippet(long, 50);
    expect(result).toHaveLength(50);
    expect(result.endsWith('…')).toBe(true);
  });

  it('does not truncate when within limit', () => {
    expect(stripHtmlToSnippet('short', 50)).toBe('short');
  });

  it('replaces <br> with space', () => {
    expect(stripHtmlToSnippet('line1<br>line2<br/>line3', 120)).toBe('line1 line2 line3');
  });

  it('returns empty string for tag-only input', () => {
    expect(stripHtmlToSnippet('<p></p><div></div>', 120)).toBe('');
  });
});
