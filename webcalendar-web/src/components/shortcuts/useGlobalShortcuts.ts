import { useEffect } from 'react';

interface GlobalShortcutHandlers {
  onHelp: () => void;
  onNewEvent: () => void;
}

/**
 * Handles a global keyboard shortcut.
 * Exported for unit testing.
 */
export function handleGlobalShortcut(event: KeyboardEvent, handlers: GlobalShortcutHandlers): void {
  const target = event.target;
  if (
    target instanceof HTMLInputElement ||
    target instanceof HTMLTextAreaElement ||
    target instanceof HTMLSelectElement
  ) {
    return;
  }

  switch (event.key) {
    case '?':
      handlers.onHelp();
      break;
    case 'n':
      handlers.onNewEvent();
      break;
  }
}

/**
 * Hook that registers global keyboard shortcuts.
 */
export function useGlobalShortcuts(handlers: GlobalShortcutHandlers): void {
  useEffect(() => {
    const listener = (event: KeyboardEvent) => handleGlobalShortcut(event, handlers);
    document.addEventListener('keydown', listener);
    return () => document.removeEventListener('keydown', listener);
  }, [handlers]);
}
