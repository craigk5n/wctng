import type { EventInput } from '@fullcalendar/core';

export interface ApiJournal {
  id: number;
  title: string;
  text: string;
  date: string; // YYYYMMDD
  type: string;
  created_by: string;
}

function formatDate(dateStr: string): string {
  return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
}

/**
 * Maps an API journal to a FullCalendar EventInput.
 */
export function mapJournalToFullCalendar(journal: ApiJournal): EventInput {
  return {
    id: `journal-${journal.id}`,
    title: `📓 ${journal.title}`,
    start: formatDate(journal.date),
    allDay: true,
    classNames: ['journal-entry'],
    extendedProps: {
      isJournal: true,
      journalId: journal.id,
      text: journal.text,
      created_by: journal.created_by,
    },
  };
}

export function mapJournalsToFullCalendar(journals: ApiJournal[]): EventInput[] {
  return journals.map(mapJournalToFullCalendar);
}
