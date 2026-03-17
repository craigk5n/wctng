import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useAuth } from '../auth/auth-context';

interface AssistantData {
  assistants: Array<{ login: string }>;
  bosses: Array<{ login: string }>;
}

export function AssistantSettings() {
  const { user } = useAuth();
  const login = user?.login ?? '';

  const [data, setData] = useState<AssistantData>({ assistants: [], bosses: [] });
  const [loading, setLoading] = useState(true);
  const [newAssistant, setNewAssistant] = useState('');

  const fetchData = useCallback(async () => {
    if (!login) return;
    const { data: result } = await apiFetch<AssistantData>(`/users/${login}/assistants`);
    if (result) setData(result);
    setLoading(false);
  }, [login]);

  useEffect(() => {
    void fetchData();
  }, [fetchData]);

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault();
    const asst = newAssistant.trim();
    if (!asst || !login) return;

    await apiFetch(`/users/${login}/assistants`, {
      method: 'POST',
      body: JSON.stringify({ assistant: asst }),
    });

    setNewAssistant('');
    void fetchData();
  };

  const handleRemove = async (assistant: string) => {
    if (!login) return;
    await apiFetch(`/users/${login}/assistants/${assistant}`, { method: 'DELETE' });
    void fetchData();
  };

  return (
    <div className="space-y-6">
      <h2 className="text-xl font-semibold">Assistant Management</h2>
      <p className="text-sm text-muted-foreground">
        Assistants can view and manage events on your calendar.
      </p>

      {loading && <p className="text-sm text-muted-foreground">Loading...</p>}

      {/* My assistants */}
      <div className="space-y-3">
        <h3 className="text-sm font-medium">My Assistants</h3>

        {data.assistants.length === 0 && !loading && (
          <p className="text-xs text-muted-foreground">No assistants assigned.</p>
        )}

        {data.assistants.length > 0 && (
          <div className="space-y-1">
            {data.assistants.map((a) => (
              <div key={a.login} className="flex items-center justify-between rounded border px-3 py-2 text-sm">
                <span className="font-medium">{a.login}</span>
                <button
                  onClick={() => void handleRemove(a.login)}
                  className="rounded border border-destructive px-2 py-0.5 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground"
                >
                  Remove
                </button>
              </div>
            ))}
          </div>
        )}

        <form onSubmit={handleAdd} className="flex gap-2">
          <input
            type="text"
            value={newAssistant}
            onChange={(e) => setNewAssistant(e.target.value)}
            placeholder="Enter username to add..."
            className="flex h-9 flex-1 rounded-md border border-input bg-background px-3 text-sm"
          />
          <button
            type="submit"
            className="rounded bg-primary px-4 py-1.5 text-sm text-primary-foreground hover:bg-primary/90"
          >
            Add
          </button>
        </form>
      </div>

      {/* Bosses I assist */}
      {data.bosses.length > 0 && (
        <div className="space-y-3">
          <h3 className="text-sm font-medium">Calendars You Assist</h3>
          <div className="space-y-1">
            {data.bosses.map((b) => (
              <div key={b.login} className="rounded border px-3 py-2 text-sm">
                <span className="font-medium">{b.login}</span>
                <span className="ml-2 text-xs text-muted-foreground">— their calendar appears in your layers</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
