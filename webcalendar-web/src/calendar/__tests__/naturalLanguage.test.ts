import { describe, it, expect } from 'vitest';
import { parseNaturalLanguage } from '../naturalLanguageParser';

describe('naturalLanguageParser', () => {
  it('parses simple event with time', async () => {
    const result = await parseNaturalLanguage('Meeting tomorrow at 2pm');
    expect(result.title).toContain('Meeting');
    expect(result.startTime).toBeDefined();
  });

  it('parses event with location using "at"', async () => {
    const result = await parseNaturalLanguage('Lunch at Café Roma on Friday');
    expect(result.location).toBe('Café Roma');
  });

  it('parses event with time range', async () => {
    const result = await parseNaturalLanguage('Call 3pm-4pm');
    expect(result.startTime).toBeDefined();
    expect(result.duration).toBeGreaterThan(0);
  });

  it('parses event with "with" for participants', async () => {
    const result = await parseNaturalLanguage('Coffee with alice');
    expect(result.participants).toContain('alice');
  });

  it('returns title for plain text', async () => {
    const result = await parseNaturalLanguage('Team standup');
    expect(result.title).toBe('Team standup');
  });

  it('handles empty string', async () => {
    const result = await parseNaturalLanguage('');
    expect(result.title).toBe('');
  });

  it('extracts date from natural language', async () => {
    const result = await parseNaturalLanguage('Dentist appointment next Monday at 10am');
    expect(result.title).toContain('Dentist appointment');
    expect(result.startDate).toBeDefined();
    expect(result.startTime).toBeDefined();
  });
});
