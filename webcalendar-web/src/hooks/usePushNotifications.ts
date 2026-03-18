import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

export function usePushNotifications() {
  const [permission, setPermission] = useState<NotificationPermission>(
    typeof Notification !== 'undefined' ? Notification.permission : 'denied',
  );
  const [subscribed, setSubscribed] = useState(false);
  const [supported] = useState(() =>
    typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window,
  );

  useEffect(() => {
    if (!supported) return;
    // Check if already subscribed
    void navigator.serviceWorker.ready.then(async (reg) => {
      const sub = await reg.pushManager.getSubscription();
      setSubscribed(sub !== null);
    });
  }, [supported]);

  const subscribe = useCallback(async () => {
    if (!supported) return false;

    // Request notification permission
    const perm = await Notification.requestPermission();
    setPermission(perm);
    if (perm !== 'granted') return false;

    // Get VAPID public key
    const { data: vapid } = await apiFetch<{ publicKey: string }>('/push/vapid-key');
    if (!vapid?.publicKey) return false;

    // Subscribe via push manager
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapid.publicKey) as BufferSource,
    });

    // Send subscription to server
    const subJson = sub.toJSON();
    await apiFetch('/push/subscribe', {
      method: 'POST',
      body: JSON.stringify({
        endpoint: subJson.endpoint,
        keys: subJson.keys,
      }),
    });

    setSubscribed(true);
    return true;
  }, [supported]);

  const unsubscribe = useCallback(async () => {
    if (!supported) return;

    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    if (sub) {
      await apiFetch('/push/unsubscribe', {
        method: 'POST',
        body: JSON.stringify({ endpoint: sub.endpoint }),
      });
      await sub.unsubscribe();
    }
    setSubscribed(false);
  }, [supported]);

  return { supported, permission, subscribed, subscribe, unsubscribe };
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}
