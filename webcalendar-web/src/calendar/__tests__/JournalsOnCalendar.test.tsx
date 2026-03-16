import { describe, it, expect } from 'vitest';
import { mapJournalToFullCalendar, type ApiJournal } from '../journalMapper';

describe('Journals on Calendar', () => {
  it('maps a journal to a FullCalendar event with journal type', () => {
    const journal: ApiJournal = {
      id: 10,
      title: 'Morning Thoughts',
      text: 'Some reflections...',
      date: '20260320',
      type: 'J',
      created_by: 'admin',
    };

    const event = mapJournalToFullCalendar(journal);

    expect(event.id).toBe('journal-10');
    expect(event.title).toContain('Morning Thoughts');
    expect(event.title).toContain('📓');
    expect(event.start).toBe('2026-03-20');
    expect(event.allDay).toBe(true);
    expect(event.extendedProps?.isJournal).toBe(true);
    expect(event.extendedProps?.journalId).toBe(10);
  });

  it('has distinct className for journals', () => {
    const journal: ApiJournal = {
      id: 11,
      title: 'Notes',
      text: '',
      date: '20260321',
      type: 'J',
      created_by: 'admin',
    };

    const event = mapJournalToFullCalendar(journal);
    expect(event.classNames).toContain('journal-entry');
  });

  it('includes journal text in extendedProps', () => {
    const journal: ApiJournal = {
      id: 12,
      title: 'Daily Log',
      text: 'Deployed v2.0 today.',
      date: '20260322',
      type: 'J',
      created_by: 'admin',
    };

    const event = mapJournalToFullCalendar(journal);
    expect(event.extendedProps?.text).toBe('Deployed v2.0 today.');
  });
});
