import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { useAuth } from '../auth/auth-context';
import { changeLocale, getLocale } from '../i18n';
import { usePushNotifications } from '../hooks/usePushNotifications';

interface Pref {
  key: string;
  value: string;
}

export function PreferencesPage() {
  const [defaultView, setDefaultView] = useState('dayGridMonth');
  const [timezone, setTimezone] = useState('');
  const [workDayStart, setWorkDayStart] = useState('09:00');
  const [workDayEnd, setWorkDayEnd] = useState('17:00');
  const [conflictMode, setConflictMode] = useState('warn');
  const [language, setLanguage] = useState(getLocale());
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
          if (p.key === 'conflict_mode') setConflictMode(p.value);
          if (p.key === 'locale') { setLanguage(p.value); changeLocale(p.value); }
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
    prefs['conflict_mode'] = conflictMode;
    prefs['locale'] = language;

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
  }, [login, defaultView, timezone, workDayStart, workDayEnd, conflictMode, language, toast]);

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

        <div className="space-y-2">
          <label htmlFor="conflict-mode" className="text-sm font-medium">Conflict Detection</label>
          <select
            id="conflict-mode"
            value={conflictMode}
            onChange={(e) => setConflictMode(e.target.value)}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          >
            <option value="warn">Warn (show conflicts, allow save)</option>
            <option value="block">Block (prevent saving conflicting events)</option>
            <option value="off">Off (no conflict checking)</option>
          </select>
          <p className="text-xs text-muted-foreground">
            Controls whether you are warned about scheduling conflicts when creating or editing events.
          </p>
        </div>

        <div className="space-y-2">
          <label htmlFor="language" className="text-sm font-medium">Language</label>
          <select
            id="language"
            value={language}
            onChange={(e) => { setLanguage(e.target.value); changeLocale(e.target.value); }}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          >
            <option value="en">English</option>
            <option value="fr">Français</option>
            <option value="de">Deutsch</option>
            <option value="es">Español</option>
            <option value="ar">العربية</option>
            <option value="he">עברית</option>
          </select>
        </div>

        <PushNotificationToggle />

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

function PushNotificationToggle() {
  const { supported, subscribed, subscribe, unsubscribe } = usePushNotifications();

  if (!supported) return null;

  return (
    <div className="space-y-2">
      <label className="text-sm font-medium">Push Notifications</label>
      <div className="flex items-center gap-3">
        <button
          onClick={() => void (subscribed ? unsubscribe() : subscribe())}
          className={`rounded px-4 py-1.5 text-sm font-medium ${
            subscribed
              ? 'border border-destructive text-destructive hover:bg-destructive/10'
              : 'bg-primary text-primary-foreground hover:bg-primary/90'
          }`}
        >
          {subscribed ? 'Disable Notifications' : 'Enable Notifications'}
        </button>
        {subscribed && (
          <span className="text-xs text-green-600">Active</span>
        )}
      </div>
      <p className="text-xs text-muted-foreground">
        Receive browser notifications for event reminders and calendar updates.
      </p>
    </div>
  );
}
