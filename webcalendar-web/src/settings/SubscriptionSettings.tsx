import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface Subscription {
  id: number;
  user_login: string;
  url: string;
  name: string;
  color: string;
  refresh_interval: number;
  last_fetched: string | null;
  etag: string | null;
}

const POPULAR_CALENDARS = [
  { name: 'US Holidays', url: 'https://calendar.google.com/calendar/ical/en.usa%23holiday%40group.v.calendar.google.com/public/basic.ics', color: '#e74c3c' },
  { name: 'UK Holidays', url: 'https://calendar.google.com/calendar/ical/en.uk%23holiday%40group.v.calendar.google.com/public/basic.ics', color: '#3498db' },
  { name: 'Canadian Holidays', url: 'https://calendar.google.com/calendar/ical/en.canadian%23holiday%40group.v.calendar.google.com/public/basic.ics', color: '#e67e22' },
  { name: 'German Holidays', url: 'https://calendar.google.com/calendar/ical/en.german%23holiday%40group.v.calendar.google.com/public/basic.ics', color: '#2ecc71' },
  { name: 'French Holidays', url: 'https://calendar.google.com/calendar/ical/en.french%23holiday%40group.v.calendar.google.com/public/basic.ics', color: '#9b59b6' },
];

export function SubscriptionSettings() {
  const [subs, setSubs] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAdd, setShowAdd] = useState(false);
  const [newUrl, setNewUrl] = useState('');
  const [newName, setNewName] = useState('');
  const [newColor, setNewColor] = useState('#3788d8');
  const [saving, setSaving] = useState(false);
  const { toast } = useToast();

  const fetchSubs = useCallback(async () => {
    const { data } = await apiFetch<Subscription[]>('/calendars/subscriptions');
    setSubs(data ?? []);
    setLoading(false);
  }, []);

  useEffect(() => {
    void fetchSubs();
  }, [fetchSubs]);

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newUrl.trim() || !newName.trim()) return;
    setSaving(true);

    const { error } = await apiFetch('/calendars/subscribe', {
      method: 'POST',
      body: JSON.stringify({ url: newUrl.trim(), name: newName.trim(), color: newColor }),
    });

    setSaving(false);
    if (!error) {
      toast({ title: 'Subscription added', variant: 'success' });
      setNewUrl('');
      setNewName('');
      setShowAdd(false);
      void fetchSubs();
    } else {
      toast({ title: error.message || 'Failed to add', variant: 'error' });
    }
  };

  const handleQuickAdd = async (cal: typeof POPULAR_CALENDARS[0]) => {
    setSaving(true);
    const { error } = await apiFetch('/calendars/subscribe', {
      method: 'POST',
      body: JSON.stringify({ url: cal.url, name: cal.name, color: cal.color }),
    });
    setSaving(false);
    if (!error) {
      toast({ title: `${cal.name} added`, variant: 'success' });
      void fetchSubs();
    }
  };

  const handleDelete = async (id: number) => {
    await apiFetch(`/calendars/subscriptions/${id}`, { method: 'DELETE' });
    void fetchSubs();
  };

  const subscribedUrls = new Set(subs.map((s) => s.url));

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold">Calendar Subscriptions</h2>
        <button
          onClick={() => setShowAdd(!showAdd)}
          className="rounded bg-primary px-4 py-2 text-sm text-primary-foreground hover:bg-primary/90"
        >
          Add Subscription
        </button>
      </div>

      <p className="text-sm text-muted-foreground">
        Subscribe to external ICS calendar feeds to display holidays, shared calendars, and more.
      </p>

      {/* Add form */}
      {showAdd && (
        <form onSubmit={handleAdd} className="space-y-3 rounded-lg border p-4">
          <div className="space-y-1">
            <label htmlFor="sub-url" className="text-sm font-medium">Calendar URL</label>
            <input
              id="sub-url"
              type="url"
              required
              value={newUrl}
              onChange={(e) => setNewUrl(e.target.value)}
              placeholder="https://example.com/calendar.ics"
              className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
          </div>
          <div className="flex gap-3">
            <div className="flex-1 space-y-1">
              <label htmlFor="sub-name" className="text-sm font-medium">Display Name</label>
              <input
                id="sub-name"
                type="text"
                required
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Display name"
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              />
            </div>
            <div className="w-20 space-y-1">
              <label htmlFor="sub-color" className="text-sm font-medium">Color</label>
              <input
                id="sub-color"
                type="color"
                value={newColor}
                onChange={(e) => setNewColor(e.target.value)}
                className="h-9 w-full cursor-pointer rounded-md border border-input"
              />
            </div>
          </div>
          <div className="flex gap-2">
            <button type="submit" disabled={saving} className="rounded bg-primary px-4 py-1.5 text-sm text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
              {saving ? 'Adding...' : 'Subscribe'}
            </button>
            <button type="button" onClick={() => setShowAdd(false)} className="rounded border px-4 py-1.5 text-sm hover:bg-accent">Cancel</button>
          </div>
        </form>
      )}

      {/* Current subscriptions */}
      {loading && <p className="text-sm text-muted-foreground">Loading...</p>}

      {!loading && subs.length > 0 && (
        <div className="space-y-2">
          {subs.map((sub) => (
            <div key={sub.id} className="flex items-center gap-3 rounded-lg border p-3">
              <span className="h-4 w-4 rounded-full" style={{ backgroundColor: sub.color }} />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{sub.name}</p>
                <p className="truncate text-xs text-muted-foreground">{sub.url}</p>
                {sub.last_fetched && (
                  <p className="text-xs text-muted-foreground">Last synced: {new Date(sub.last_fetched).toLocaleString()}</p>
                )}
              </div>
              <button
                onClick={() => void handleDelete(sub.id)}
                className="rounded border border-destructive px-2 py-0.5 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground"
              >
                Remove
              </button>
            </div>
          ))}
        </div>
      )}

      {!loading && subs.length === 0 && (
        <p className="text-sm text-muted-foreground">No subscriptions yet.</p>
      )}

      {/* Popular calendars */}
      <div className="space-y-3">
        <h3 className="text-sm font-medium">Popular Calendars</h3>
        <div className="grid gap-2 sm:grid-cols-2">
          {POPULAR_CALENDARS.map((cal) => {
            const alreadyAdded = subscribedUrls.has(cal.url);
            return (
              <button
                key={cal.url}
                disabled={alreadyAdded || saving}
                onClick={() => void handleQuickAdd(cal)}
                className="flex items-center gap-2 rounded-lg border p-3 text-left text-sm hover:bg-accent disabled:opacity-50"
              >
                <span className="h-3 w-3 rounded-full" style={{ backgroundColor: cal.color }} />
                <span className="font-medium">{cal.name}</span>
                {alreadyAdded && <span className="ml-auto text-xs text-muted-foreground">Added</span>}
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}
