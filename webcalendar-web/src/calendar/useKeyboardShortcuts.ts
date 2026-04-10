import { useEffect } from 'react';

interface KeyboardHandlers {
  onPrev: () => void;
  onNext: () => void;
  onToday: () => void;
  onViewChange: (view: string) => void;
}

/**
 * Handles a single keydown event for calendar shortcuts.
 * Exported for unit testing.
 */
export function handleCalendarKeydown(event: KeyboardEvent, handlers: KeyboardHandlers): void {
  // Don't handle shortcuts when typing in form fields. Includes
  // contenteditable elements because rich-text editors (TipTap,
  // ProseMirror, CKEditor) render the edit surface as
  // `<div contenteditable>`, not a textarea — without this guard,
  // typing a "d" in a description field would trigger the global
  // "switch to day view" shortcut.
  const target = event.target;
  if (
    target instanceof HTMLInputElement ||
    target instanceof HTMLTextAreaElement ||
    target instanceof HTMLSelectElement
  ) {
    return;
  }
  if (target instanceof HTMLElement && target.isContentEditable) {
    return;
  }

  switch (event.key) {
    case 'ArrowLeft':
      handlers.onPrev();
      break;
    case 'ArrowRight':
      handlers.onNext();
      break;
    case 't':
      handlers.onToday();
      break;
    case 'm':
      handlers.onViewChange('dayGridMonth');
      break;
    case 'w':
      handlers.onViewChange('timeGridWeek');
      break;
    case 'd':
      handlers.onViewChange('timeGridDay');
      break;
    case 'y':
      handlers.onViewChange('multiMonthYear');
      break;
  }
}

/**
 * Hook that registers keyboard shortcuts for calendar navigation.
 *
 * - ←/→: prev/next period
 * - T: jump to today
 * - M/W/D: switch to month/week/day view
 */
export function useKeyboardShortcuts(handlers: KeyboardHandlers): void {
  useEffect(() => {
    const listener = (event: KeyboardEvent) => handleCalendarKeydown(event, handlers);
    document.addEventListener('keydown', listener);
    return () => document.removeEventListener('keydown', listener);
  }, [handlers]);
}
