import { describe, it, expect, vi } from 'vitest';
import { handleGlobalShortcut } from '../useGlobalShortcuts';

describe('handleGlobalShortcut', () => {
  it('calls onHelp for ? key', () => {
    const handlers = { onHelp: vi.fn(), onNewEvent: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: '?' });
    handleGlobalShortcut(event, handlers);
    expect(handlers.onHelp).toHaveBeenCalled();
  });

  it('calls onNewEvent for N key', () => {
    const handlers = { onHelp: vi.fn(), onNewEvent: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: 'n' });
    handleGlobalShortcut(event, handlers);
    expect(handlers.onNewEvent).toHaveBeenCalled();
  });

  it('ignores shortcuts when typing in input', () => {
    const handlers = { onHelp: vi.fn(), onNewEvent: vi.fn() };
    const input = document.createElement('input');
    const event = new KeyboardEvent('keydown', { key: '?' });
    Object.defineProperty(event, 'target', { value: input });
    handleGlobalShortcut(event, handlers);
    expect(handlers.onHelp).not.toHaveBeenCalled();
  });

  it('ignores shortcuts when typing in textarea', () => {
    const handlers = { onHelp: vi.fn(), onNewEvent: vi.fn() };
    const textarea = document.createElement('textarea');
    const event = new KeyboardEvent('keydown', { key: 'n' });
    Object.defineProperty(event, 'target', { value: textarea });
    handleGlobalShortcut(event, handlers);
    expect(handlers.onNewEvent).not.toHaveBeenCalled();
  });
});
