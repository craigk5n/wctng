import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface ActivityEntry { date: string; count: number }
interface BusyHourEntry { hour: number; count: number }
interface CategoryEntry { category_id: number; category_name: string; count: number }

function formatDate(yyyymmdd: string): string {
  if (yyyymmdd.length !== 8) return yyyymmdd;
  return `${yyyymmdd.slice(4, 6)}/${yyyymmdd.slice(6, 8)}`;
}

export function ReportsPage() {
  const [activity, setActivity] = useState<ActivityEntry[]>([]);
  const [busyHours, setBusyHours] = useState<BusyHourEntry[]>([]);
  const [categories, setCategories] = useState<CategoryEntry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [startDate, setStartDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10).replace(/-/g, '');
  });
  const [endDate, setEndDate] = useState(() => new Date().toISOString().slice(0, 10).replace(/-/g, ''));

  const fetchReports = useCallback(async () => {
    setIsLoading(true);
    const [actRes, busyRes, catRes] = await Promise.all([
      apiFetch<ActivityEntry[]>(`/reports/activity?start=${startDate}&end=${endDate}`),
      apiFetch<BusyHourEntry[]>(`/reports/busy-hours?start=${startDate}&end=${endDate}`),
      apiFetch<CategoryEntry[]>(`/reports/categories?start=${startDate}&end=${endDate}`),
    ]);
    setActivity(actRes.data ?? []);
    setBusyHours(busyRes.data ?? []);
    setCategories(catRes.data ?? []);
    setIsLoading(false);
  }, [startDate, endDate]);

  useEffect(() => {
    void fetchReports();
  }, [fetchReports]);

  const maxActivity = Math.max(1, ...activity.map((a) => a.count));
  const maxBusy = Math.max(1, ...busyHours.map((b) => b.count));
  const maxCat = Math.max(1, ...categories.map((c) => c.count));

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Reports</h2>
        <div className="flex items-center gap-2 text-sm">
          <label htmlFor="report-start">From</label>
          <input
            id="report-start"
            type="date"
            value={`${startDate.slice(0, 4)}-${startDate.slice(4, 6)}-${startDate.slice(6, 8)}`}
            onChange={(e) => setStartDate(e.target.value.replace(/-/g, ''))}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
          />
          <label htmlFor="report-end">To</label>
          <input
            id="report-end"
            type="date"
            value={`${endDate.slice(0, 4)}-${endDate.slice(4, 6)}-${endDate.slice(6, 8)}`}
            onChange={(e) => setEndDate(e.target.value.replace(/-/g, ''))}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
          />
        </div>
      </div>

      {isLoading ? (
        <p className="mt-4 text-sm text-muted-foreground">Loading reports...</p>
      ) : (
        <div className="mt-6 grid gap-6 md:grid-cols-2">
          {/* Activity Bar Chart */}
          <div className="rounded-lg border border-border p-4">
            <h3 className="text-sm font-semibold uppercase text-muted-foreground">Activity</h3>
            <p className="text-xs text-muted-foreground">Events per day</p>
            <div className="mt-3 flex items-end gap-px" style={{ height: 120 }}>
              {activity.length === 0 ? (
                <p className="text-xs text-muted-foreground">No data</p>
              ) : (
                activity.map((a) => (
                  <div
                    key={a.date}
                    className="flex-1 rounded-t bg-primary transition-all hover:bg-primary/80"
                    style={{ height: `${(a.count / maxActivity) * 100}%`, minWidth: 4 }}
                    title={`${formatDate(a.date)}: ${a.count} events`}
                  />
                ))
              )}
            </div>
          </div>

          {/* Busy Hours */}
          <div className="rounded-lg border border-border p-4">
            <h3 className="text-sm font-semibold uppercase text-muted-foreground">Busy Hours</h3>
            <p className="text-xs text-muted-foreground">Events by hour of day</p>
            <div className="mt-3 space-y-1">
              {busyHours.length === 0 ? (
                <p className="text-xs text-muted-foreground">No data</p>
              ) : (
                busyHours.map((b) => (
                  <div key={b.hour} className="flex items-center gap-2 text-xs">
                    <span className="w-8 text-right text-muted-foreground">{String(b.hour).padStart(2, '0')}:00</span>
                    <div
                      className="h-4 rounded bg-primary/70"
                      style={{ width: `${(b.count / maxBusy) * 100}%`, minWidth: 4 }}
                    />
                    <span className="text-muted-foreground">{b.count}</span>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Category Breakdown */}
          <div className="rounded-lg border border-border p-4 md:col-span-2">
            <h3 className="text-sm font-semibold uppercase text-muted-foreground">Categories</h3>
            <p className="text-xs text-muted-foreground">Events by category</p>
            <div className="mt-3 space-y-2">
              {categories.length === 0 ? (
                <p className="text-xs text-muted-foreground">No categorized events</p>
              ) : (
                categories.map((c) => (
                  <div key={c.category_id} className="flex items-center gap-3">
                    <span className="w-24 truncate text-sm">{c.category_name}</span>
                    <div className="flex-1">
                      <div
                        className="h-5 rounded bg-primary/60"
                        style={{ width: `${(c.count / maxCat) * 100}%`, minWidth: 8 }}
                      />
                    </div>
                    <span className="text-sm font-medium">{c.count}</span>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
