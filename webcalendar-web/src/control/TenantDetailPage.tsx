import { useCallback, useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { controlApiFetch } from './control-auth';

interface TenantDetail {
  slug: string;
  name: string;
  plan: string;
  status: string;
  db_host: string;
  created_at: string | null;
  updated_at: string | null;
  user_count: number | null;
  event_count: number | null;
}

interface TenantStats {
  slug: string;
  user_count: number;
  event_count: number;
  task_count: number;
  last_activity: string | null;
}

const STATUS_STYLES: Record<string, string> = {
  active: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
  suspended: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
  pending: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
};

export function TenantDetailPage() {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const [tenant, setTenant] = useState<TenantDetail | null>(null);
  const [stats, setStats] = useState<TenantStats | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [editName, setEditName] = useState('');
  const [editPlan, setEditPlan] = useState('');
  const [isEditing, setIsEditing] = useState(false);

  const fetchData = useCallback(async () => {
    if (!slug) return;
    setIsLoading(true);

    const [detailRes, statsRes] = await Promise.all([
      controlApiFetch<TenantDetail>(`/tenants/${slug}`),
      controlApiFetch<TenantStats>(`/tenants/${slug}/stats`),
    ]);

    if (detailRes.data) {
      setTenant(detailRes.data);
      setEditName(detailRes.data.name);
      setEditPlan(detailRes.data.plan);
    }
    if (statsRes.data) {
      setStats(statsRes.data);
    }
    setIsLoading(false);
  }, [slug]);

  useEffect(() => {
    void fetchData();
  }, [fetchData]);

  const handleSave = async () => {
    if (!slug) return;
    await controlApiFetch(`/tenants/${slug}`, {
      method: 'PUT',
      body: JSON.stringify({ name: editName, plan: editPlan }),
    });
    setIsEditing(false);
    void fetchData();
  };

  const handleToggleStatus = async () => {
    if (!slug || !tenant) return;
    const newStatus = tenant.status === 'active' ? 'suspended' : 'active';
    await controlApiFetch(`/tenants/${slug}`, {
      method: 'PUT',
      body: JSON.stringify({ status: newStatus }),
    });
    void fetchData();
  };

  const handleDelete = async () => {
    if (!slug || !tenant) return;
    if (!confirm(`Delete tenant "${tenant.name}" (${slug})? This cannot be undone.`)) return;
    await controlApiFetch(`/tenants/${slug}?confirm=true`, { method: 'DELETE' });
    navigate('/control');
  };

  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Loading...</p>;
  }

  if (!tenant) {
    return <p className="text-sm text-destructive">Tenant not found.</p>;
  }

  return (
    <div>
      <div className="flex items-center gap-3">
        <button onClick={() => navigate('/control')} className="text-sm text-muted-foreground hover:text-foreground">
          &larr; Back
        </button>
        <h2 className="text-2xl font-bold">{tenant.name}</h2>
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[tenant.status] ?? STATUS_STYLES.pending}`}>
          {tenant.status}
        </span>
      </div>

      <div className="mt-6 grid gap-6 md:grid-cols-2">
        {/* Details */}
        <div className="rounded-lg border border-border p-4">
          <h3 className="text-sm font-semibold uppercase text-muted-foreground">Details</h3>
          {isEditing ? (
            <div className="mt-3 space-y-3">
              <div className="space-y-1">
                <label className="text-sm font-medium">Name</label>
                <input
                  type="text"
                  value={editName}
                  onChange={(e) => setEditName(e.target.value)}
                  className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                />
              </div>
              <div className="space-y-1">
                <label className="text-sm font-medium">Plan</label>
                <select
                  value={editPlan}
                  onChange={(e) => setEditPlan(e.target.value)}
                  className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="free">Free</option>
                  <option value="pro">Pro</option>
                  <option value="enterprise">Enterprise</option>
                </select>
              </div>
              <div className="flex gap-2">
                <button onClick={() => void handleSave()} className="rounded bg-primary px-3 py-1 text-xs text-primary-foreground hover:bg-primary/90">Save</button>
                <button onClick={() => setIsEditing(false)} className="rounded px-3 py-1 text-xs text-muted-foreground hover:bg-accent">Cancel</button>
              </div>
            </div>
          ) : (
            <div className="mt-3 space-y-2 text-sm">
              <div className="flex justify-between"><span className="text-muted-foreground">Slug</span><span className="font-mono">{tenant.slug}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Name</span><span>{tenant.name}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Plan</span><span>{tenant.plan}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">DB Host</span><span className="font-mono text-xs">{tenant.db_host || '—'}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Created</span><span>{tenant.created_at ? new Date(tenant.created_at).toLocaleDateString() : '—'}</span></div>
              <button onClick={() => setIsEditing(true)} className="mt-2 rounded border border-input px-3 py-1 text-xs hover:bg-accent">Edit</button>
            </div>
          )}
        </div>

        {/* Stats */}
        <div className="rounded-lg border border-border p-4">
          <h3 className="text-sm font-semibold uppercase text-muted-foreground">Usage</h3>
          <div className="mt-3 grid grid-cols-2 gap-4">
            <div className="rounded-md bg-muted/50 p-3 text-center">
              <div className="text-2xl font-bold">{stats?.user_count ?? tenant.user_count ?? '—'}</div>
              <div className="text-xs text-muted-foreground">Users</div>
            </div>
            <div className="rounded-md bg-muted/50 p-3 text-center">
              <div className="text-2xl font-bold">{stats?.event_count ?? tenant.event_count ?? '—'}</div>
              <div className="text-xs text-muted-foreground">Events</div>
            </div>
            <div className="rounded-md bg-muted/50 p-3 text-center">
              <div className="text-2xl font-bold">{stats?.task_count ?? '—'}</div>
              <div className="text-xs text-muted-foreground">Tasks</div>
            </div>
            <div className="rounded-md bg-muted/50 p-3 text-center">
              <div className="text-sm font-medium">{stats?.last_activity ?? '—'}</div>
              <div className="text-xs text-muted-foreground">Last Activity</div>
            </div>
          </div>
        </div>
      </div>

      {/* Actions */}
      <div className="mt-6 rounded-lg border border-border p-4">
        <h3 className="text-sm font-semibold uppercase text-muted-foreground">Actions</h3>
        <div className="mt-3 flex flex-wrap gap-2">
          <button
            onClick={() => void handleToggleStatus()}
            className="rounded border border-input px-3 py-1.5 text-sm hover:bg-accent"
          >
            {tenant.status === 'active' ? 'Suspend Tenant' : 'Activate Tenant'}
          </button>
          <button
            onClick={() => void handleDelete()}
            className="rounded bg-destructive px-3 py-1.5 text-sm text-destructive-foreground hover:bg-destructive/90"
          >
            Delete Tenant
          </button>
        </div>
      </div>
    </div>
  );
}
