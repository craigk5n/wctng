import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface Resource {
  login: string;
  name: string;
  admin: string;
  is_public: boolean;
  url: string | null;
}

export function ResourceManagement() {
  const [resources, setResources] = useState<Resource[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [newLogin, setNewLogin] = useState('');
  const [newName, setNewName] = useState('');
  const [newPublic, setNewPublic] = useState(false);
  const [saving, setSaving] = useState(false);
  const { toast } = useToast();

  const fetchResources = useCallback(async () => {
    const { data } = await apiFetch<Resource[]>('/admin/resources');
    setResources(data ?? []);
    setLoading(false);
  }, []);

  useEffect(() => {
    void fetchResources();
  }, [fetchResources]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newLogin.trim() || !newName.trim()) return;
    setSaving(true);

    const { error } = await apiFetch('/admin/resources', {
      method: 'POST',
      body: JSON.stringify({
        login: newLogin.trim().toLowerCase().replace(/\s+/g, '-'),
        name: newName.trim(),
        is_public: newPublic,
      }),
    });

    setSaving(false);
    if (error) {
      toast({ title: error.message ?? 'Failed to create resource', variant: 'error' });
      return;
    }
    toast({ title: 'Resource created', variant: 'success' });
    setNewLogin('');
    setNewName('');
    setNewPublic(false);
    setShowCreate(false);
    void fetchResources();
  };

  const handleDelete = async (login: string) => {
    if (!confirm(`Delete resource "${login}"?`)) return;
    const { error } = await apiFetch(`/admin/resources/${login}`, { method: 'DELETE' });
    if (error) {
      toast({ title: error.message ?? 'Failed to delete resource', variant: 'error' });
      return;
    }
    toast({ title: 'Resource deleted', variant: 'success' });
    void fetchResources();
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Rooms & Resources</h2>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="rounded bg-primary px-4 py-2 text-sm text-primary-foreground hover:bg-primary/90"
        >
          Add Resource
        </button>
      </div>

      <p className="mt-2 text-sm text-muted-foreground">
        Manage rooms, equipment, and other shared resources that can be booked for events.
      </p>

      {showCreate && (
        <form onSubmit={handleCreate} className="mt-4 space-y-3 rounded-lg border p-4">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <label htmlFor="res-login" className="text-sm font-medium">Resource ID</label>
              <input
                id="res-login"
                type="text"
                required
                value={newLogin}
                onChange={(e) => setNewLogin(e.target.value)}
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                placeholder="room-a"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="res-name" className="text-sm font-medium">Display Name</label>
              <input
                id="res-name"
                type="text"
                required
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                placeholder="Conference Room A"
              />
            </div>
          </div>
          <div className="flex items-center gap-2">
            <input
              id="res-public"
              type="checkbox"
              checked={newPublic}
              onChange={(e) => setNewPublic(e.target.checked)}
              className="h-4 w-4"
            />
            <label htmlFor="res-public" className="text-sm">Public (visible to all users)</label>
          </div>
          <div className="flex gap-2">
            <button type="submit" disabled={saving} className="rounded bg-primary px-4 py-1.5 text-sm text-primary-foreground disabled:opacity-50">
              {saving ? 'Creating...' : 'Create'}
            </button>
            <button type="button" onClick={() => setShowCreate(false)} className="rounded border px-4 py-1.5 text-sm hover:bg-accent">Cancel</button>
          </div>
        </form>
      )}

      <div className="mt-4">
        {loading && <p className="text-sm text-muted-foreground">Loading...</p>}

        {!loading && resources.length === 0 && (
          <p className="text-sm text-muted-foreground">No resources defined yet.</p>
        )}

        {resources.length > 0 && (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                <th className="pb-2 pr-4">ID</th>
                <th className="pb-2 pr-4">Name</th>
                <th className="pb-2 pr-4">Admin</th>
                <th className="pb-2 pr-4">Public</th>
                <th className="pb-2"></th>
              </tr>
            </thead>
            <tbody>
              {resources.map((r) => (
                <tr key={r.login} className="border-b border-border/50">
                  <td className="py-2 pr-4 font-mono text-xs">{r.login}</td>
                  <td className="py-2 pr-4 font-medium">{r.name}</td>
                  <td className="py-2 pr-4 text-muted-foreground">{r.admin}</td>
                  <td className="py-2 pr-4">{r.is_public ? 'Yes' : 'No'}</td>
                  <td className="py-2 text-right">
                    <button
                      onClick={() => void handleDelete(r.login)}
                      className="rounded border border-destructive px-2 py-0.5 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground"
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
