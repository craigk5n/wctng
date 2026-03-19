import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { useAuth } from '../auth/auth-context';

interface SavedView {
  id: number;
  name: string;
  user_logins: string[];
  is_global?: boolean;
  owner?: string;
}

interface UserOption {
  login: string;
  fullName: string;
}

export function SavedViewsPage() {
  const [views, setViews] = useState<SavedView[]>([]);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [newName, setNewName] = useState('');
  const [selectedUsers, setSelectedUsers] = useState<string[]>([]);
  const [isGlobal, setIsGlobal] = useState(false);
  const [loading, setLoading] = useState(true);
  const { toast } = useToast();
  const { user: authUser } = useAuth();
  const isAdmin = authUser?.is_admin ?? false;

  const fetchViews = useCallback(async () => {
    const { data } = await apiFetch<SavedView[]>('/views');
    if (data) setViews(data);
    setLoading(false);
  }, []);

  useEffect(() => {
    void fetchViews();
    void (async () => {
      const { data } = await apiFetch<Array<{ login: string; first_name: string; last_name: string }>>('/users');
      if (data) {
        setUsers(data.map((u) => ({
          login: u.login,
          fullName: `${u.first_name} ${u.last_name}`.trim() || u.login,
        })));
      }
    })();
  }, [fetchViews]);

  const handleCreate = useCallback(async () => {
    if (!newName.trim() || selectedUsers.length === 0) return;

    const { error } = await apiFetch('/views', {
      method: 'POST',
      body: JSON.stringify({ name: newName.trim(), user_logins: selectedUsers, is_global: isGlobal }),
    });

    if (!error) {
      toast({ title: `View "${newName}" created`, variant: 'success' });
      setNewName('');
      setSelectedUsers([]);
      setIsGlobal(false);
      void fetchViews();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  }, [newName, selectedUsers, toast, fetchViews]);

  const handleDelete = useCallback(async (view: SavedView) => {
    const { error } = await apiFetch(`/views/${view.id}`, { method: 'DELETE' });
    if (!error) {
      toast({ title: `View "${view.name}" deleted`, variant: 'success' });
      void fetchViews();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  }, [toast, fetchViews]);

  const handleActivate = useCallback(async (view: SavedView) => {
    // Remove existing layers and add the view's users as layers
    // First get current layers
    const { data: currentLayers } = await apiFetch<Array<{ id: number }>>('/layers');
    if (currentLayers) {
      // Delete all existing layers
      for (const layer of currentLayers) {
        await apiFetch(`/layers/${layer.id}`, { method: 'DELETE' });
      }
    }

    // Add view's users as layers
    const colors = ['#3788d8', '#e53935', '#43a047', '#fb8c00', '#8e24aa', '#00acc1', '#6d4c41', '#546e7a'];
    for (let i = 0; i < view.user_logins.length; i++) {
      await apiFetch('/layers', {
        method: 'POST',
        body: JSON.stringify({
          source_user: view.user_logins[i],
          color: colors[i % colors.length],
        }),
      });
    }

    toast({ title: `View "${view.name}" activated — reload calendar to see changes`, variant: 'success' });
  }, [toast]);

  const toggleUser = (login: string) => {
    setSelectedUsers((prev) =>
      prev.includes(login) ? prev.filter((l) => l !== login) : [...prev, login],
    );
  };

  if (loading) return <p className="text-muted-foreground">Loading...</p>;

  return (
    <div>
      <h2 className="text-2xl font-bold">Saved Views</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Create named views that show multiple users' calendars together.
        Activating a view sets up layers for the selected users.
      </p>

      {/* Existing views */}
      <div className="mt-6 max-w-lg space-y-3">
        {views.length === 0 ? (
          <p className="text-sm text-muted-foreground">No saved views yet.</p>
        ) : (
          views.map((view) => (
            <div key={view.id} className="flex items-center justify-between rounded-lg border p-3">
              <div>
                <span className="font-medium">
                  {view.name}
                  {view.is_global && (
                    <span className="ml-2 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                      Global
                    </span>
                  )}
                </span>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {view.user_logins.length} user{view.user_logins.length !== 1 ? 's' : ''}: {view.user_logins.join(', ')}
                </p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => void handleActivate(view)}
                  className="rounded-md bg-primary px-3 py-1 text-xs font-medium text-primary-foreground hover:bg-primary/90"
                >
                  Activate
                </button>
                <button
                  onClick={() => void handleDelete(view)}
                  className="rounded-md border border-destructive px-3 py-1 text-xs font-medium text-destructive hover:bg-destructive/10"
                >
                  Delete
                </button>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Create new view */}
      <div className="mt-8 max-w-lg rounded-lg border p-4">
        <h3 className="text-lg font-semibold">Create New View</h3>

        <div className="mt-4 space-y-4">
          <div className="space-y-1">
            <label htmlFor="view-name" className="text-sm font-medium">View Name</label>
            <input
              id="view-name"
              type="text"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              placeholder="e.g. Engineering Team"
              className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
          </div>

          <div className="space-y-1">
            <label className="text-sm font-medium">Select Users</label>
            <div className="max-h-48 overflow-y-auto rounded-md border p-2 space-y-1">
              {users.map((u) => (
                <label key={u.login} className="flex items-center gap-2 cursor-pointer rounded px-2 py-1 hover:bg-accent/50">
                  <input
                    type="checkbox"
                    checked={selectedUsers.includes(u.login)}
                    onChange={() => toggleUser(u.login)}
                    className="h-4 w-4 rounded border-input"
                  />
                  <span className="text-sm">{u.fullName}</span>
                  <span className="text-xs text-muted-foreground">({u.login})</span>
                </label>
              ))}
            </div>
            {selectedUsers.length > 0 && (
              <p className="text-xs text-muted-foreground">{selectedUsers.length} selected</p>
            )}
          </div>

          {isAdmin && (
            <div className="flex items-center gap-2">
              <input
                id="view-global"
                type="checkbox"
                checked={isGlobal}
                onChange={(e) => setIsGlobal(e.target.checked)}
                className="h-4 w-4"
              />
              <label htmlFor="view-global" className="text-sm font-medium">
                Make this a global view (visible to all users)
              </label>
            </div>
          )}

          <button
            onClick={() => void handleCreate()}
            disabled={!newName.trim() || selectedUsers.length === 0}
            className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            Create View
          </button>
        </div>
      </div>
    </div>
  );
}
