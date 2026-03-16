import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface User {
  login: string;
  firstname: string;
  lastname: string;
  email: string;
  is_admin: boolean;
  enabled: boolean;
}

interface CreateUserForm {
  login: string;
  password: string;
  email: string;
  firstname: string;
  lastname: string;
}

export function UserManagement() {
  const [users, setUsers] = useState<User[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [createForm, setCreateForm] = useState<CreateUserForm>({
    login: '',
    password: '',
    email: '',
    firstname: '',
    lastname: '',
  });
  const [createError, setCreateError] = useState<string | null>(null);
  const [isCreating, setIsCreating] = useState(false);
  const { toast } = useToast();

  const fetchUsers = useCallback(async () => {
    setIsLoading(true);
    const { data } = await apiFetch<User[]>('/users');
    setUsers(data ?? []);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchUsers();
  }, [fetchUsers]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setCreateError(null);
    setIsCreating(true);

    const { error } = await apiFetch('/users', {
      method: 'POST',
      body: JSON.stringify(createForm),
    });

    setIsCreating(false);

    if (!error) {
      toast({ title: `User "${createForm.login}" created`, variant: 'success' });
      setShowCreateForm(false);
      setCreateForm({ login: '', password: '', email: '', firstname: '', lastname: '' });
      void fetchUsers();
    } else {
      setCreateError(error.message);
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">User Management</h2>
        <button
          onClick={() => setShowCreateForm(!showCreateForm)}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          {showCreateForm ? 'Cancel' : '+ New User'}
        </button>
      </div>

      {/* Create User Form */}
      {showCreateForm && (
        <div className="mt-4 rounded-lg border border-border p-4">
          <h3 className="text-lg font-semibold">Create User</h3>
          <form onSubmit={handleCreate} className="mt-3 grid grid-cols-2 gap-4">
            {createError && (
              <div role="alert" className="col-span-2 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                {createError}
              </div>
            )}
            <div className="space-y-1">
              <label htmlFor="new-login" className="text-sm font-medium">Login</label>
              <input
                id="new-login"
                type="text"
                required
                value={createForm.login}
                onChange={(e) => setCreateForm((f) => ({ ...f, login: e.target.value }))}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="new-email" className="text-sm font-medium">Email</label>
              <input
                id="new-email"
                type="email"
                required
                value={createForm.email}
                onChange={(e) => setCreateForm((f) => ({ ...f, email: e.target.value }))}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="new-password" className="text-sm font-medium">Password</label>
              <input
                id="new-password"
                type="password"
                required
                value={createForm.password}
                onChange={(e) => setCreateForm((f) => ({ ...f, password: e.target.value }))}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="new-firstname" className="text-sm font-medium">First Name</label>
              <input
                id="new-firstname"
                type="text"
                value={createForm.firstname}
                onChange={(e) => setCreateForm((f) => ({ ...f, firstname: e.target.value }))}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="new-lastname" className="text-sm font-medium">Last Name</label>
              <input
                id="new-lastname"
                type="text"
                value={createForm.lastname}
                onChange={(e) => setCreateForm((f) => ({ ...f, lastname: e.target.value }))}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="flex items-end">
              <button
                type="submit"
                disabled={isCreating}
                className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
              >
                {isCreating ? 'Creating...' : 'Create User'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Users Table */}
      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading users...</p>
        ) : users.length === 0 ? (
          <p className="text-sm text-muted-foreground">No users found.</p>
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full text-sm">
              <thead className="border-b border-border bg-muted/50">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">Login</th>
                  <th className="px-4 py-3 text-left font-medium">Name</th>
                  <th className="px-4 py-3 text-left font-medium">Email</th>
                  <th className="px-4 py-3 text-left font-medium">Role</th>
                  <th className="px-4 py-3 text-left font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {users.map((u) => (
                  <tr key={u.login} className="hover:bg-muted/30">
                    <td className="px-4 py-3 font-medium">{u.login}</td>
                    <td className="px-4 py-3">
                      {u.firstname} {u.lastname}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{u.email}</td>
                    <td className="px-4 py-3">
                      {u.is_admin ? (
                        <span className="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                          Admin
                        </span>
                      ) : (
                        <span className="text-muted-foreground">User</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      {u.enabled ? (
                        <span className="text-green-600">Active</span>
                      ) : (
                        <span className="text-muted-foreground">Disabled</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
