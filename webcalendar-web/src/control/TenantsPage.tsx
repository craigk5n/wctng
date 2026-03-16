import { useCallback, useEffect, useState } from 'react';
import { controlApiFetch } from './control-auth';

interface TenantListItem {
  slug: string;
  name: string;
  plan: string;
  status: string;
  created_at: string | null;
}

const STATUS_STYLES: Record<string, string> = {
  active: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
  suspended: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
  pending: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
};

export function TenantsPage() {
  const [tenants, setTenants] = useState<TenantListItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [search, setSearch] = useState('');

  const fetchTenants = useCallback(async () => {
    setIsLoading(true);
    const { data } = await controlApiFetch<TenantListItem[]>('/tenants');
    setTenants(data ?? []);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchTenants();
  }, [fetchTenants]);

  const handleSuspendToggle = async (tenant: TenantListItem) => {
    const newStatus = tenant.status === 'active' ? 'suspended' : 'active';
    await controlApiFetch(`/tenants/${tenant.slug}`, {
      method: 'PUT',
      body: JSON.stringify({ status: newStatus }),
    });
    void fetchTenants();
  };

  const handleDelete = async (tenant: TenantListItem) => {
    if (!confirm(`Delete tenant "${tenant.name}" (${tenant.slug})? This cannot be undone.`)) {
      return;
    }
    await controlApiFetch(`/tenants/${tenant.slug}?confirm=true`, { method: 'DELETE' });
    void fetchTenants();
  };

  const filtered = tenants.filter((t) => {
    if (!search) return true;
    const q = search.toLowerCase();
    return t.slug.toLowerCase().includes(q) || t.name.toLowerCase().includes(q);
  });

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Tenants</h2>
        <span className="text-sm text-muted-foreground">{tenants.length} total</span>
      </div>

      {/* Search */}
      <div className="mt-4">
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search by name or slug..."
          className="flex h-10 w-full max-w-sm rounded-md border border-input bg-background px-3 py-2 text-sm"
        />
      </div>

      {/* Table */}
      <div className="mt-4 overflow-x-auto">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading tenants...</p>
        ) : filtered.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {search ? 'No tenants match your search.' : 'No tenants found.'}
          </p>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left">
                <th className="px-4 py-2 font-medium">Slug</th>
                <th className="px-4 py-2 font-medium">Name</th>
                <th className="px-4 py-2 font-medium">Plan</th>
                <th className="px-4 py-2 font-medium">Status</th>
                <th className="px-4 py-2 font-medium">Created</th>
                <th className="px-4 py-2 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((tenant) => (
                <tr key={tenant.slug} className="border-b border-border hover:bg-muted/30">
                  <td className="px-4 py-3 font-mono text-xs">{tenant.slug}</td>
                  <td className="px-4 py-3">{tenant.name}</td>
                  <td className="px-4 py-3">{tenant.plan}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[tenant.status] ?? STATUS_STYLES.pending}`}>
                      {tenant.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">
                    {tenant.created_at ? new Date(tenant.created_at).toLocaleDateString() : '—'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-1">
                      <button
                        onClick={() => void handleSuspendToggle(tenant)}
                        className="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                      >
                        {tenant.status === 'active' ? 'Suspend' : 'Activate'}
                      </button>
                      <button
                        onClick={() => void handleDelete(tenant)}
                        className="rounded px-2 py-1 text-xs text-destructive hover:bg-destructive/10"
                      >
                        Delete
                      </button>
                    </div>
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
