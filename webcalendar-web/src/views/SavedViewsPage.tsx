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
  category_ids?: number[];
}

interface UserOption {
  login: string;
  fullName: string;
  enabled: boolean;
}

interface CategoryOption {
  id: number;
  name: string;
  color: string | null;
}

export function SavedViewsPage() {
  const [views, setViews] = useState<SavedView[]>([]);
  const [allUsers, setAllUsers] = useState<UserOption[]>([]);
  const [newName, setNewName] = useState('');
  const [selectedUsers, setSelectedUsers] = useState<string[]>([]);
  const [allCategories, setAllCategories] = useState<CategoryOption[]>([]);
  const [selectedCategoryIds, setSelectedCategoryIds] = useState<number[]>([]);
  const [isGlobal, setIsGlobal] = useState(false);
  const [loading, setLoading] = useState(true);
  const [editingView, setEditingView] = useState<SavedView | null>(null);
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
      // Fetch ALL users so we can show disabled indicators on existing views
      const { data } =
        await apiFetch<
          Array<{ login: string; firstname: string; lastname: string; enabled: boolean }>
        >('/users');
      if (data) {
        setAllUsers(
          data.map((u) => ({
            login: u.login,
            fullName: `${u.firstname} ${u.lastname}`.trim() || u.login,
            enabled: u.enabled,
          })),
        );
      }
      const { data: cats } = await apiFetch<CategoryOption[]>('/categories');
      if (cats) setAllCategories(cats);
    })();
  }, [fetchViews]);

  const enabledUsers = allUsers.filter((u) => u.enabled);

  const userDisplayName = (login: string) => {
    const u = allUsers.find((u) => u.login === login);
    return u?.fullName ?? login;
  };

  const isUserDisabled = (login: string) => {
    const u = allUsers.find((u) => u.login === login);
    return u !== undefined && !u.enabled;
  };

  const resetForm = () => {
    setNewName('');
    setSelectedUsers([]);
    setSelectedCategoryIds([]);
    setIsGlobal(false);
    setEditingView(null);
  };

  const handleCreate = useCallback(async () => {
    if (!newName.trim() || selectedUsers.length === 0) return;

    const { error } = await apiFetch('/views', {
      method: 'POST',
      body: JSON.stringify({
        name: newName.trim(),
        user_logins: selectedUsers,
        is_global: isGlobal,
        category_ids: selectedCategoryIds,
      }),
    });

    if (!error) {
      toast({ title: `View "${newName}" created`, variant: 'success' });
      resetForm();
      void fetchViews();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  }, [newName, selectedUsers, isGlobal, selectedCategoryIds, toast, fetchViews]);

  const handleUpdate = useCallback(async () => {
    if (!editingView || !newName.trim() || selectedUsers.length === 0) return;

    const { error } = await apiFetch(`/views/${editingView.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        name: newName.trim(),
        user_logins: selectedUsers,
        is_global: isGlobal,
        category_ids: selectedCategoryIds,
      }),
    });

    if (!error) {
      toast({ title: `View "${newName}" updated`, variant: 'success' });
      resetForm();
      void fetchViews();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  }, [editingView, newName, selectedUsers, isGlobal, selectedCategoryIds, toast, fetchViews]);

  const handleDelete = useCallback(
    async (view: SavedView) => {
      const { error } = await apiFetch(`/views/${view.id}`, { method: 'DELETE' });
      if (!error) {
        toast({ title: `View "${view.name}" deleted`, variant: 'success' });
        if (editingView?.id === view.id) resetForm();
        void fetchViews();
      } else {
        toast({ title: error.message, variant: 'error' });
      }
    },
    [toast, fetchViews, editingView],
  );

  const handleEdit = (view: SavedView) => {
    setEditingView(view);
    setNewName(view.name);
    setSelectedUsers(view.user_logins);
    setSelectedCategoryIds(view.category_ids ?? []);
    setIsGlobal(view.is_global ?? false);
  };

  const handleActivate = useCallback(
    async (view: SavedView) => {
      let failed = false;

      // Remove existing layers
      const { data: currentLayers } = await apiFetch<Array<{ id: number }>>('/layers');
      if (currentLayers) {
        for (const layer of currentLayers) {
          const { error } = await apiFetch(`/layers/${layer.id}`, { method: 'DELETE' });
          if (error) failed = true;
        }
      }

      // Add view's users as layers
      const colors = [
        '#3788d8',
        '#e53935',
        '#43a047',
        '#fb8c00',
        '#8e24aa',
        '#00acc1',
        '#6d4c41',
        '#546e7a',
      ];
      for (let i = 0; i < view.user_logins.length; i++) {
        const { error } = await apiFetch('/layers', {
          method: 'POST',
          body: JSON.stringify({
            source_user: view.user_logins[i],
            color: colors[i % colors.length],
          }),
        });
        if (error) failed = true;
      }

      if (failed) {
        toast({ title: 'Some layers failed to update', variant: 'error' });
        return;
      }

      // Apply category filter if view has one
      if (view.category_ids && view.category_ids.length > 0) {
        localStorage.setItem('wctng_category_filter', JSON.stringify(view.category_ids));
        window.dispatchEvent(new CustomEvent('category-filter-change'));
      }

      toast({
        title: `View "${view.name}" activated — reload calendar to see changes`,
        variant: 'success',
      });
    },
    [toast],
  );

  const toggleUser = (login: string) => {
    setSelectedUsers((prev) =>
      prev.includes(login) ? prev.filter((l) => l !== login) : [...prev, login],
    );
  };

  if (loading) return <p className="text-muted-foreground">Loading...</p>;

  // When editing, show all users (enabled + disabled already in the view); when creating, show only enabled
  const usersForPicker = editingView
    ? allUsers.filter((u) => u.enabled || editingView.user_logins.includes(u.login))
    : enabledUsers;

  return (
    <div>
      <h2 className="text-2xl font-bold">Saved Views</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Create named views that show multiple users' calendars together. Activating a view sets up
        layers for the selected users.
      </p>

      {/* Existing views */}
      <div className="mt-6 max-w-lg space-y-3">
        {views.length === 0 ? (
          <p className="text-sm text-muted-foreground">No saved views yet.</p>
        ) : (
          views.map((view) => {
            const hasDisabledUsers = view.user_logins.some(isUserDisabled);
            return (
              <div key={view.id} className="rounded-lg border p-3">
                <div className="flex items-center justify-between">
                  <div className="min-w-0 flex-1">
                    <span className="font-medium">
                      {view.name}
                      {view.is_global && (
                        <span className="ml-2 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                          Global
                        </span>
                      )}
                    </span>
                    <div className="mt-1 flex flex-wrap gap-1">
                      {view.user_logins.map((login) => {
                        const disabled = isUserDisabled(login);
                        return (
                          <span
                            key={login}
                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs ${
                              disabled
                                ? 'bg-amber-100 text-amber-800 line-through dark:bg-amber-900/30 dark:text-amber-400'
                                : 'bg-muted text-muted-foreground'
                            }`}
                            title={
                              disabled
                                ? `${userDisplayName(login)} (disabled)`
                                : userDisplayName(login)
                            }
                          >
                            {userDisplayName(login)}
                            {disabled && (
                              <span className="ml-1 no-underline" aria-label="disabled user">
                                &#x26D4;
                              </span>
                            )}
                          </span>
                        );
                      })}
                    </div>
                    {view.category_ids && view.category_ids.length > 0 && (
                      <p className="mt-0.5 text-xs text-blue-600 dark:text-blue-400">
                        + {view.category_ids.length} category filter
                        {view.category_ids.length !== 1 ? 's' : ''}
                      </p>
                    )}
                    {hasDisabledUsers && (
                      <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        Contains disabled users — edit to update
                      </p>
                    )}
                  </div>
                  <div className="ml-3 flex flex-shrink-0 gap-2">
                    <button
                      onClick={() => void handleActivate(view)}
                      className="rounded-md bg-primary px-3 py-1 text-xs font-medium text-primary-foreground hover:bg-primary/90"
                    >
                      Activate
                    </button>
                    <button
                      onClick={() => handleEdit(view)}
                      className="rounded-md border border-border px-3 py-1 text-xs font-medium hover:bg-muted"
                    >
                      Edit
                    </button>
                    <button
                      onClick={() => void handleDelete(view)}
                      className="rounded-md border border-destructive px-3 py-1 text-xs font-medium text-destructive hover:bg-destructive/10"
                    >
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            );
          })
        )}
      </div>

      {/* Create / Edit view */}
      <div className="mt-8 max-w-lg rounded-lg border p-4">
        <h3 className="text-lg font-semibold">
          {editingView ? `Edit View: ${editingView.name}` : 'Create New View'}
        </h3>

        <div className="mt-4 space-y-4">
          <div className="space-y-1">
            <label htmlFor="view-name" className="text-sm font-medium">
              View Name
            </label>
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
            <div className="max-h-48 space-y-1 overflow-y-auto rounded-md border p-2">
              {usersForPicker.map((u) => (
                <label
                  key={u.login}
                  className={`flex cursor-pointer items-center gap-2 rounded px-2 py-1 hover:bg-accent/50 ${
                    !u.enabled ? 'opacity-60' : ''
                  }`}
                >
                  <input
                    type="checkbox"
                    checked={selectedUsers.includes(u.login)}
                    onChange={() => toggleUser(u.login)}
                    className="h-4 w-4 rounded border-input"
                  />
                  <span className={`text-sm ${!u.enabled ? 'line-through' : ''}`}>
                    {u.fullName}
                  </span>
                  <span className="text-xs text-muted-foreground">({u.login})</span>
                  {!u.enabled && (
                    <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                      Disabled
                    </span>
                  )}
                </label>
              ))}
            </div>
            {selectedUsers.length > 0 && (
              <p className="text-xs text-muted-foreground">{selectedUsers.length} selected</p>
            )}
          </div>

          {allCategories.length > 0 && (
            <div className="space-y-1">
              <label className="text-sm font-medium">Filter by Categories (optional)</label>
              <div className="max-h-32 space-y-1 overflow-y-auto rounded-md border p-2">
                {allCategories.map((cat) => (
                  <label
                    key={cat.id}
                    className="flex cursor-pointer items-center gap-2 rounded px-2 py-0.5 hover:bg-accent/50"
                  >
                    <input
                      type="checkbox"
                      checked={selectedCategoryIds.includes(cat.id)}
                      onChange={() =>
                        setSelectedCategoryIds((prev) =>
                          prev.includes(cat.id)
                            ? prev.filter((id) => id !== cat.id)
                            : [...prev, cat.id],
                        )
                      }
                      className="h-4 w-4 rounded border-input"
                    />
                    <span
                      className="h-2.5 w-2.5 rounded-full"
                      style={{ backgroundColor: cat.color ?? '#888' }}
                    />
                    <span className="text-sm">{cat.name}</span>
                  </label>
                ))}
              </div>
              {selectedCategoryIds.length > 0 && (
                <p className="text-xs text-muted-foreground">
                  {selectedCategoryIds.length} category filter(s)
                </p>
              )}
            </div>
          )}

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

          <div className="flex gap-2">
            {editingView ? (
              <>
                <button
                  onClick={() => void handleUpdate()}
                  disabled={!newName.trim() || selectedUsers.length === 0}
                  className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                >
                  Save Changes
                </button>
                <button
                  onClick={resetForm}
                  className="inline-flex h-9 items-center rounded-md border border-border px-4 text-sm font-medium hover:bg-muted"
                >
                  Cancel
                </button>
              </>
            ) : (
              <button
                onClick={() => void handleCreate()}
                disabled={!newName.trim() || selectedUsers.length === 0}
                className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
              >
                Create View
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
