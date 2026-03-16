import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { useAuth } from '../auth/auth-context';

interface Pref {
  key: string;
  value: string;
}

export function PreferencesPage() {
  const [defaultView, setDefaultView] = useState('dayGridMonth');
  const [timezone, setTimezone] = useState('');
  const [workDayStart, setWorkDayStart] = useState('09:00');
  const [workDayEnd, setWorkDayEnd] = useState('17:00');
  const [isSaving, setIsSaving] = useState(false);
  const { toast } = useToast();
  const { user } = useAuth();

  const login = user?.login ?? '';

  useEffect(() => {
    if (!login) return;
    void (async () => {
      const { data } = await apiFetch<Pref[]>(`/users/${login}/preferences`);
      if (data) {
        for (const p of data) {
          if (p.key === 'STARTVIEW') setDefaultView(p.value);
          if (p.key === 'TIMEZONE') setTimezone(p.value);
          if (p.key === 'WORK_DAY_START') setWorkDayStart(p.value);
          if (p.key === 'WORK_DAY_END') setWorkDayEnd(p.value);
        }
      }
    })();
  }, [login]);

  const handleSave = useCallback(async () => {
    if (!login) return;
    setIsSaving(true);

    const prefs: Record<string, string> = {
      STARTVIEW: defaultView,
      WORK_DAY_START: workDayStart,
      WORK_DAY_END: workDayEnd,
    };
    if (timezone) prefs['TIMEZONE'] = timezone;

    const { error } = await apiFetch(`/users/${login}/preferences`, {
      method: 'PUT',
      body: JSON.stringify(prefs),
    });

    setIsSaving(false);
    if (!error) {
      toast({ title: 'Preferences saved', variant: 'success' });
    } else {
      toast({ title: 'Failed to save', variant: 'error' });
    }
  }, [login, defaultView, timezone, workDayStart, workDayEnd, toast]);

  return (
    <div>
      <h2 className="text-2xl font-bold">Preferences</h2>

      <div className="mt-6 max-w-lg space-y-6">
        <div className="space-y-2">
          <label htmlFor="default-view" className="text-sm font-medium">Default View</label>
          <select
            id="default-view"
            value={defaultView}
            onChange={(e) => setDefaultView(e.target.value)}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          >
            <option value="dayGridMonth">Month</option>
            <option value="timeGridWeek">Week</option>
            <option value="timeGridDay">Day</option>
            <option value="listWeek">List</option>
          </select>
        </div>

        <div className="space-y-2">
          <label htmlFor="timezone" className="text-sm font-medium">Timezone</label>
          <input
            id="timezone"
            type="text"
            value={timezone}
            onChange={(e) => setTimezone(e.target.value)}
            placeholder="e.g. America/New_York"
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <label htmlFor="work-start" className="text-sm font-medium">Work Day Start</label>
            <input
              id="work-start"
              type="time"
              value={workDayStart}
              onChange={(e) => setWorkDayStart(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            />
          </div>
          <div className="space-y-2">
            <label htmlFor="work-end" className="text-sm font-medium">Work Day End</label>
            <input
              id="work-end"
              type="time"
              value={workDayEnd}
              onChange={(e) => setWorkDayEnd(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            />
          </div>
        </div>

        <button
          onClick={handleSave}
          disabled={isSaving}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
        >
          {isSaving ? 'Saving...' : 'Save Preferences'}
        </button>
      </div>
    </div>
  );
}
