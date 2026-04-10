import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { useAuth } from '../auth/auth-context';

interface AccessEntry {
  login: string;
  can_view: boolean;
  can_edit: boolean;
  see_time_only: boolean;
}

interface UserListItem {
  login: string;
}

export function AccessSettings() {
  const [entries, setEntries] = useState<AccessEntry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const { toast } = useToast();
  const { user } = useAuth();

  const fetchData = useCallback(async () => {
    setIsLoading(true);

    // Fetch current access settings and all users in parallel
    const [accessResult, usersResult] = await Promise.all([
      apiFetch<AccessEntry[]>('/access/users'),
      apiFetch<UserListItem[]>('/users'),
    ]);

    const accessMap = new Map<string, AccessEntry>();
    for (const entry of accessResult.data ?? []) {
      accessMap.set(entry.login, entry);
    }

    // Build entries for all users except the current user
    const allUsers = usersResult.data ?? [];
    const merged = allUsers
      .filter((u) => u.login !== user?.login)
      .map((u) => accessMap.get(u.login) ?? {
        login: u.login,
        can_view: false,
        can_edit: false,
        see_time_only: false,
      });

    setEntries(merged);
    setIsLoading(false);
  }, [user?.login]);

  useEffect(() => {
    void fetchData();
  }, [fetchData]);

  const toggleField = (login: string, field: 'can_view' | 'can_edit') => {
    setEntries((prev) =>
      prev.map((e) => {
        if (e.login !== login) return e;
        const updated = { ...e, [field]: !e[field] };
        // If removing view, also remove edit
        if (field === 'can_view' && !updated.can_view) {
          updated.can_edit = false;
        }
        // If adding edit, also add view
        if (field === 'can_edit' && updated.can_edit) {
          updated.can_view = true;
        }
        return updated;
      }),
    );
  };

  const handleSave = async () => {
    setIsSaving(true);

    let hasError = false;
    for (const entry of entries) {
      if (entry.can_view || entry.can_edit) {
        const { error } = await apiFetch(`/access/users/${entry.login}`, {
          method: 'PUT',
          body: JSON.stringify({
            can_view: entry.can_view,
            can_edit: entry.can_edit,
          }),
        });
        if (error) hasError = true;
      }
    }

    setIsSaving(false);

    if (hasError) {
      toast({ title: 'Failed to save some permissions', variant: 'error' });
    } else {
      toast({ title: 'Permissions saved', variant: 'success' });
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Access Control</h2>
        <button
          onClick={() => void handleSave()}
          disabled={isSaving}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
        >
          {isSaving ? 'Saving...' : 'Save'}
        </button>
      </div>

      <p className="mt-2 text-sm text-muted-foreground">
        Control which users can view or edit events on your calendar.
      </p>

      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading...</p>
        ) : entries.length === 0 ? (
          <p className="text-sm text-muted-foreground">No other users found.</p>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left">
                <th className="px-4 py-2 font-medium">User</th>
                <th className="px-4 py-2 font-medium text-center">Can View</th>
                <th className="px-4 py-2 font-medium text-center">Can Edit</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr key={entry.login} className="border-b border-border hover:bg-muted/30">
                  <td className="px-4 py-3">{entry.login}</td>
                  <td className="px-4 py-3 text-center">
                    <input
                      type="checkbox"
                      checked={entry.can_view}
                      onChange={() => toggleField(entry.login, 'can_view')}
                      className="h-4 w-4 rounded border-input"
                      aria-label={`${entry.login} can view`}
                    />
                  </td>
                  <td className="px-4 py-3 text-center">
                    <input
                      type="checkbox"
                      checked={entry.can_edit}
                      onChange={() => toggleField(entry.login, 'can_edit')}
                      className="h-4 w-4 rounded border-input"
                      aria-label={`${entry.login} can edit`}
                    />
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
