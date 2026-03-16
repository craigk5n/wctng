import { useEffect, useRef } from 'react';

export interface MercureMessage {
  type: string;
  [key: string]: unknown;
}

interface UseMercureOptions {
  hubUrl: string;
  topics: string[];
  onMessage: (message: MercureMessage) => void;
  enabled?: boolean;
}

const RECONNECT_DELAY_MS = 3000;

/**
 * React hook that subscribes to a Mercure hub via EventSource (SSE).
 * Automatically reconnects on connection loss.
 */
export function useMercure({ hubUrl, topics, onMessage, enabled = true }: UseMercureOptions): void {
  const onMessageRef = useRef(onMessage);
  onMessageRef.current = onMessage;

  useEffect(() => {
    if (!enabled || topics.length === 0 || !hubUrl || typeof EventSource === 'undefined') return;

    let eventSource: EventSource | null = null;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
    let isClosed = false;

    function connect() {
      if (isClosed) return;

      const url = new URL(hubUrl);
      for (const topic of topics) {
        url.searchParams.append('topic', topic);
      }

      eventSource = new EventSource(url.toString());

      eventSource.onmessage = (event: MessageEvent) => {
        try {
          const data = JSON.parse(event.data as string) as MercureMessage;
          onMessageRef.current(data);
        } catch {
          // Ignore malformed messages
        }
      };

      eventSource.onerror = () => {
        eventSource?.close();
        eventSource = null;

        if (!isClosed) {
          reconnectTimer = setTimeout(connect, RECONNECT_DELAY_MS);
        }
      };
    }

    connect();

    return () => {
      isClosed = true;
      eventSource?.close();
      if (reconnectTimer) clearTimeout(reconnectTimer);
    };
  }, [hubUrl, topics, enabled]);
}
