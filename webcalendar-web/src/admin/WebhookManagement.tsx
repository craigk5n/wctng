import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface Webhook {
  id: number;
  url: string;
  events: string;
  enabled: boolean;
}

export function WebhookManagement() {
  const [webhooks, setWebhooks] = useState<Webhook[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [newUrl, setNewUrl] = useState('');
  const [newEvents, setNewEvents] = useState('*');
  const { toast } = useToast();

  const fetchWebhooks = useCallback(async () => {
    setIsLoading(true);
    const { data } = await apiFetch<Webhook[]>('/admin/webhooks');
    setWebhooks(data ?? []);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchWebhooks();
  }, [fetchWebhooks]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newUrl.trim()) return;

    const { error } = await apiFetch('/admin/webhooks', {
      method: 'POST',
      body: JSON.stringify({ url: newUrl.trim(), events: newEvents }),
    });

    if (!error) {
      toast({ title: 'Webhook created', variant: 'success' });
      setNewUrl('');
      setNewEvents('*');
      setShowCreate(false);
      void fetchWebhooks();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleToggle = async (webhook: Webhook) => {
    await apiFetch(`/admin/webhooks/${webhook.id}`, {
      method: 'PUT',
      body: JSON.stringify({ enabled: !webhook.enabled }),
    });
    void fetchWebhooks();
  };

  const handleDelete = async (webhook: Webhook) => {
    const { error } = await apiFetch(`/admin/webhooks/${webhook.id}`, { method: 'DELETE' });
    if (!error) {
      toast({ title: 'Webhook deleted', variant: 'success' });
      void fetchWebhooks();
    }
  };

  const handleTest = async (webhook: Webhook) => {
    toast({ title: `Test payload sent to ${webhook.url}` });
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Webhooks</h2>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          {showCreate ? 'Cancel' : '+ New Webhook'}
        </button>
      </div>

      {showCreate && (
        <form onSubmit={handleCreate} className="mt-4 flex items-end gap-3 rounded-lg border border-border p-4">
          <div className="flex-1 space-y-1">
            <label htmlFor="wh-url" className="text-sm font-medium">URL</label>
            <input
              id="wh-url"
              type="url"
              required
              value={newUrl}
              onChange={(e) => setNewUrl(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="https://example.com/webhook"
            />
          </div>
          <div className="w-48 space-y-1">
            <label htmlFor="wh-events" className="text-sm font-medium">Events</label>
            <input
              id="wh-events"
              type="text"
              value={newEvents}
              onChange={(e) => setNewEvents(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="* or event.created,..."
            />
          </div>
          <button
            type="submit"
            className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
          >
            Create
          </button>
        </form>
      )}

      <div className="mt-6 space-y-2">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading...</p>
        ) : webhooks.length === 0 ? (
          <p className="text-sm text-muted-foreground">No webhooks configured.</p>
        ) : (
          webhooks.map((wh) => (
            <div key={wh.id} className="flex items-center justify-between rounded-lg border border-border px-4 py-3">
              <div className="min-w-0 flex-1">
                <div className="truncate font-mono text-sm">{wh.url}</div>
                <div className="text-xs text-muted-foreground">Events: {wh.events}</div>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => void handleTest(wh)}
                  className="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-accent"
                >
                  Test
                </button>
                <button
                  onClick={() => void handleToggle(wh)}
                  className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                    wh.enabled
                      ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                      : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
                  }`}
                >
                  {wh.enabled ? 'Enabled' : 'Disabled'}
                </button>
                <button
                  onClick={() => void handleDelete(wh)}
                  className="rounded px-2 py-1 text-xs text-destructive hover:bg-destructive/10"
                >
                  Delete
                </button>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
