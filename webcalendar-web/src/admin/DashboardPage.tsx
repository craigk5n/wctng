import { useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface DashboardData {
  users: { total: number; active_7d: number; created_7d: number };
  events: { total: number; created_7d: number; upcoming_7d: number };
  system: { db_size_mb: number | null; php_version: string; db_driver: string; recent_errors: number };
  email: { reminders_sent_7d: number; agenda_sent_7d: number };
}

function StatCard({ label, value, sub }: { label: string; value: string | number; sub?: string }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className="mt-1 text-2xl font-bold">{value}</p>
      {sub && <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>}
    </div>
  );
}

export function DashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchData = async () => {
    const { data: d } = await apiFetch<DashboardData>('/admin/dashboard');
    if (d) setData(d);
    setLoading(false);
  };

  useEffect(() => {
    void fetchData();
    const interval = setInterval(() => void fetchData(), 60000);
    return () => clearInterval(interval);
  }, []);

  if (loading) {
    return <p className="text-muted-foreground">Loading dashboard...</p>;
  }

  if (!data) {
    return <p className="text-destructive">Failed to load dashboard data.</p>;
  }

  return (
    <div>
      <h2 className="text-2xl font-bold">System Dashboard</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Overview of system health and usage. Auto-refreshes every 60 seconds.
      </p>

      {/* Stat cards */}
      <div className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="Total Users" value={data.users.total} sub={`${data.users.active_7d} active this week`} />
        <StatCard label="Total Events" value={data.events.total} sub={`${data.events.created_7d} created this week`} />
        <StatCard label="Upcoming (7d)" value={data.events.upcoming_7d} />
        <StatCard
          label="Errors (7d)"
          value={data.system.recent_errors}
          sub={data.system.recent_errors === 0 ? 'All clear' : undefined}
        />
      </div>

      {/* Email stats */}
      <div className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="Reminders Sent (7d)" value={data.email.reminders_sent_7d} />
        <StatCard label="Agendas Sent (7d)" value={data.email.agenda_sent_7d} />
      </div>

      {/* System info */}
      <div className="mt-6">
        <h3 className="text-lg font-semibold">System Information</h3>
        <div className="mt-3 max-w-md space-y-2 text-sm">
          <div className="flex justify-between border-b pb-1">
            <span className="text-muted-foreground">PHP Version</span>
            <span className="font-mono">{data.system.php_version}</span>
          </div>
          <div className="flex justify-between border-b pb-1">
            <span className="text-muted-foreground">Database Driver</span>
            <span className="font-mono">{data.system.db_driver}</span>
          </div>
          {data.system.db_size_mb !== null && (
            <div className="flex justify-between border-b pb-1">
              <span className="text-muted-foreground">Database Size</span>
              <span className="font-mono">{data.system.db_size_mb} MB</span>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
