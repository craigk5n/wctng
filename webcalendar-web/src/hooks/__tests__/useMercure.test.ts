import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useMercure } from '../useMercure';

// Mock EventSource
class MockEventSource {
  static instances: MockEventSource[] = [];
  url: string;
  onmessage: ((event: MessageEvent) => void) | null = null;
  onerror: (() => void) | null = null;
  readyState = 0; // CONNECTING

  constructor(url: string) {
    this.url = url;
    MockEventSource.instances.push(this);
    // Simulate connection
    setTimeout(() => {
      this.readyState = 1; // OPEN
    }, 0);
  }

  close = vi.fn();

  // Helper to simulate a message
  simulateMessage(data: string) {
    if (this.onmessage) {
      this.onmessage(new MessageEvent('message', { data }));
    }
  }

  // Helper to simulate an error
  simulateError() {
    this.readyState = 2; // CLOSED
    if (this.onerror) {
      this.onerror();
    }
  }
}

describe('useMercure', () => {
  const originalEventSource = globalThis.EventSource;

  beforeEach(() => {
    MockEventSource.instances = [];
    // @ts-expect-error - mock EventSource
    globalThis.EventSource = MockEventSource;
  });

  afterEach(() => {
    globalThis.EventSource = originalEventSource;
  });

  it('subscribes to Mercure hub with correct topic URL', () => {
    const onMessage = vi.fn();

    renderHook(() =>
      useMercure({
        hubUrl: 'http://localhost:47181/.well-known/mercure',
        topics: ['/calendars/events'],
        onMessage,
      }),
    );

    expect(MockEventSource.instances).toHaveLength(1);
    expect(MockEventSource.instances[0].url).toContain('http://localhost:47181/.well-known/mercure');
    expect(MockEventSource.instances[0].url).toContain('topic=%2Fcalendars%2Fevents');
  });

  it('calls onMessage when event.created is received', () => {
    const onMessage = vi.fn();

    renderHook(() =>
      useMercure({
        hubUrl: 'http://localhost:47181/.well-known/mercure',
        topics: ['/calendars/events'],
        onMessage,
      }),
    );

    const instance = MockEventSource.instances[0];
    const payload = JSON.stringify({ type: 'event.created', event: { id: 1, title: 'Test' } });

    act(() => {
      instance.simulateMessage(payload);
    });

    expect(onMessage).toHaveBeenCalledWith({
      type: 'event.created',
      event: { id: 1, title: 'Test' },
    });
  });

  it('calls onMessage for event.updated', () => {
    const onMessage = vi.fn();

    renderHook(() =>
      useMercure({
        hubUrl: 'http://localhost:47181/.well-known/mercure',
        topics: ['/calendars/events'],
        onMessage,
      }),
    );

    const instance = MockEventSource.instances[0];
    const payload = JSON.stringify({ type: 'event.updated', event: { id: 2, title: 'Updated' } });

    act(() => {
      instance.simulateMessage(payload);
    });

    expect(onMessage).toHaveBeenCalledWith({
      type: 'event.updated',
      event: { id: 2, title: 'Updated' },
    });
  });

  it('calls onMessage for event.deleted', () => {
    const onMessage = vi.fn();

    renderHook(() =>
      useMercure({
        hubUrl: 'http://localhost:47181/.well-known/mercure',
        topics: ['/calendars/events'],
        onMessage,
      }),
    );

    const instance = MockEventSource.instances[0];
    const payload = JSON.stringify({ type: 'event.deleted', eventId: 5 });

    act(() => {
      instance.simulateMessage(payload);
    });

    expect(onMessage).toHaveBeenCalledWith({ type: 'event.deleted', eventId: 5 });
  });

  it('closes EventSource on unmount', () => {
    const onMessage = vi.fn();

    const { unmount } = renderHook(() =>
      useMercure({
        hubUrl: 'http://localhost:47181/.well-known/mercure',
        topics: ['/calendars/events'],
        onMessage,
      }),
    );

    const instance = MockEventSource.instances[0];
    unmount();

    expect(instance.close).toHaveBeenCalled();
  });

  it('reconnects on error', async () => {
    vi.useFakeTimers();
    const onMessage = vi.fn();

    renderHook(() =>
      useMercure({
        hubUrl: 'http://localhost:47181/.well-known/mercure',
        topics: ['/calendars/events'],
        onMessage,
      }),
    );

    expect(MockEventSource.instances).toHaveLength(1);

    // Simulate an error
    act(() => {
      MockEventSource.instances[0].simulateError();
    });

    // Advance time to trigger reconnect
    await act(async () => {
      vi.advanceTimersByTime(3000);
    });

    // Should have created a new EventSource
    expect(MockEventSource.instances.length).toBeGreaterThanOrEqual(2);

    vi.useRealTimers();
  });
});
