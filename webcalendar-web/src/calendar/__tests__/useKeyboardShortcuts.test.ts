import { describe, it, expect, vi } from 'vitest';
import { handleCalendarKeydown } from '../useKeyboardShortcuts';

describe('Calendar Keyboard Shortcuts', () => {
  it('calls onPrev for ArrowLeft', () => {
    const handlers = { onPrev: vi.fn(), onNext: vi.fn(), onToday: vi.fn(), onViewChange: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: 'ArrowLeft' });

    handleCalendarKeydown(event, handlers);
    expect(handlers.onPrev).toHaveBeenCalled();
  });

  it('calls onNext for ArrowRight', () => {
    const handlers = { onPrev: vi.fn(), onNext: vi.fn(), onToday: vi.fn(), onViewChange: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: 'ArrowRight' });

    handleCalendarKeydown(event, handlers);
    expect(handlers.onNext).toHaveBeenCalled();
  });

  it('calls onToday for T key', () => {
    const handlers = { onPrev: vi.fn(), onNext: vi.fn(), onToday: vi.fn(), onViewChange: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: 't' });

    handleCalendarKeydown(event, handlers);
    expect(handlers.onToday).toHaveBeenCalled();
  });

  it('calls onViewChange with dayGridMonth for M key', () => {
    const handlers = { onPrev: vi.fn(), onNext: vi.fn(), onToday: vi.fn(), onViewChange: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: 'm' });

    handleCalendarKeydown(event, handlers);
    expect(handlers.onViewChange).toHaveBeenCalledWith('dayGridMonth');
  });

  it('calls onViewChange with timeGridWeek for W key', () => {
    const handlers = { onPrev: vi.fn(), onNext: vi.fn(), onToday: vi.fn(), onViewChange: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: 'w' });

    handleCalendarKeydown(event, handlers);
    expect(handlers.onViewChange).toHaveBeenCalledWith('timeGridWeek');
  });

  it('calls onViewChange with timeGridDay for D key', () => {
    const handlers = { onPrev: vi.fn(), onNext: vi.fn(), onToday: vi.fn(), onViewChange: vi.fn() };
    const event = new KeyboardEvent('keydown', { key: 'd' });

    handleCalendarKeydown(event, handlers);
    expect(handlers.onViewChange).toHaveBeenCalledWith('timeGridDay');
  });

  it('ignores shortcuts when typing in an input', () => {
    const handlers = { onPrev: vi.fn(), onNext: vi.fn(), onToday: vi.fn(), onViewChange: vi.fn() };
    const input = document.createElement('input');
    const event = new KeyboardEvent('keydown', { key: 't' });
    Object.defineProperty(event, 'target', { value: input });

    handleCalendarKeydown(event, handlers);
    expect(handlers.onToday).not.toHaveBeenCalled();
  });
});
