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
  const [publicCalendar, setPublicCalendar] = useState('N');
  const [seoIndexing, setSeoIndexing] = useState('Y');
  const [reminderMinutes, setReminderMinutes] = useState('30');
  const [dailyAgendaEnabled, setDailyAgendaEnabled] = useState('N');
  const [dailyAgendaTime, setDailyAgendaTime] = useState('06:00');
  const [emailInvitation, setEmailInvitation] = useState('Y');
  const [emailUpdate, setEmailUpdate] = useState('Y');
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
          if (p.key === 'locale') {
            setLanguage(p.value);
            changeLocale(p.value);
          }
          if (p.key === 'public_calendar_enabled') setPublicCalendar(p.value);
          if (p.key === 'seo_indexing_enabled') setSeoIndexing(p.value);
          if (p.key === 'REMINDER_MINUTES') setReminderMinutes(p.value);
          if (p.key === 'daily_agenda_enabled') setDailyAgendaEnabled(p.value);
          if (p.key === 'daily_agenda_time') setDailyAgendaTime(p.value);
          if (p.key === 'EMAIL_INVITATION') setEmailInvitation(p.value);
          if (p.key === 'EMAIL_UPDATE') setEmailUpdate(p.value);
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
    prefs['public_calendar_enabled'] = publicCalendar;
    prefs['seo_indexing_enabled'] = seoIndexing;
    prefs['REMINDER_MINUTES'] = reminderMinutes;
    prefs['daily_agenda_enabled'] = dailyAgendaEnabled;
    prefs['daily_agenda_time'] = dailyAgendaTime;
    prefs['EMAIL_INVITATION'] = emailInvitation;
    prefs['EMAIL_UPDATE'] = emailUpdate;

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
  }, [
    login,
    defaultView,
    timezone,
    workDayStart,
    workDayEnd,
    conflictMode,
    language,
    publicCalendar,
    seoIndexing,
    reminderMinutes,
    dailyAgendaEnabled,
    dailyAgendaTime,
    emailInvitation,
    emailUpdate,
    toast,
  ]);

  return (
    <div>
      <h2 className="text-2xl font-bold">Preferences</h2>

      <div className="mt-6 max-w-lg space-y-6">
        <div className="space-y-2">
          <label htmlFor="default-view" className="text-sm font-medium">
            Default View
          </label>
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
          <label htmlFor="timezone" className="text-sm font-medium">
            Timezone
          </label>
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
            <label htmlFor="work-start" className="text-sm font-medium">
              Work Day Start
            </label>
            <input
              id="work-start"
              type="time"
              value={workDayStart}
              onChange={(e) => setWorkDayStart(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            />
          </div>
          <div className="space-y-2">
            <label htmlFor="work-end" className="text-sm font-medium">
              Work Day End
            </label>
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
          <label htmlFor="conflict-mode" className="text-sm font-medium">
            Conflict Detection
          </label>
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
            Controls whether you are warned about scheduling conflicts when creating or editing
            events.
          </p>
        </div>

        <div className="space-y-2">
          <label htmlFor="language" className="text-sm font-medium">
            Language
          </label>
          <select
            id="language"
            value={language}
            onChange={(e) => {
              setLanguage(e.target.value);
              changeLocale(e.target.value);
            }}
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

        <div className="space-y-2">
          <label htmlFor="email-reminder" className="text-sm font-medium">
            Email Reminder
          </label>
          <select
            id="email-reminder"
            value={reminderMinutes}
            onChange={(e) => setReminderMinutes(e.target.value)}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          >
            <option value="0">Off</option>
            <option value="5">5 minutes before</option>
            <option value="10">10 minutes before</option>
            <option value="15">15 minutes before</option>
            <option value="30">30 minutes before</option>
            <option value="60">1 hour before</option>
            <option value="1440">1 day before</option>
          </select>
          <p className="text-xs text-muted-foreground">
            Receive an email reminder before your events. Requires the cron job to be configured.
          </p>
        </div>

        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <input
              id="daily-agenda"
              type="checkbox"
              checked={dailyAgendaEnabled === 'Y'}
              onChange={(e) => setDailyAgendaEnabled(e.target.checked ? 'Y' : 'N')}
              className="h-4 w-4"
            />
            <label htmlFor="daily-agenda" className="text-sm font-medium">
              Daily Agenda Email
            </label>
          </div>
          {dailyAgendaEnabled === 'Y' && (
            <div className="ml-6">
              <label htmlFor="agenda-time" className="text-xs text-muted-foreground">
                Send at:
              </label>
              <input
                id="agenda-time"
                type="time"
                value={dailyAgendaTime}
                onChange={(e) => setDailyAgendaTime(e.target.value)}
                className="ml-2 h-8 rounded-md border border-input bg-background px-2 text-sm"
              />
            </div>
          )}
          <p className="text-xs text-muted-foreground">
            Receive a daily email summarizing your events for the day.
          </p>
        </div>

        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <input
              id="email-invitation"
              type="checkbox"
              checked={emailInvitation !== 'N'}
              onChange={(e) => setEmailInvitation(e.target.checked ? 'Y' : 'N')}
              className="h-4 w-4"
            />
            <label htmlFor="email-invitation" className="text-sm font-medium">
              Event Invitation Emails
            </label>
          </div>
          <p className="text-xs text-muted-foreground">
            Receive email notifications when you are invited to events.
          </p>
        </div>

        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <input
              id="email-update"
              type="checkbox"
              checked={emailUpdate !== 'N'}
              onChange={(e) => setEmailUpdate(e.target.checked ? 'Y' : 'N')}
              className="h-4 w-4"
            />
            <label htmlFor="email-update" className="text-sm font-medium">
              Event Update Emails
            </label>
          </div>
          <p className="text-xs text-muted-foreground">
            Receive email notifications when events you are participating in are updated or
            cancelled.
          </p>
        </div>

        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <input
              id="public-calendar"
              type="checkbox"
              checked={publicCalendar === 'Y'}
              onChange={(e) => setPublicCalendar(e.target.checked ? 'Y' : 'N')}
              className="h-4 w-4"
            />
            <label htmlFor="public-calendar" className="text-sm font-medium">
              Public Calendar
            </label>
          </div>
          <p className="text-xs text-muted-foreground">
            Make your public events visible at a shareable URL. Only events marked as
            &quot;Public&quot; access will be shown. Private and confidential events are never
            exposed.
          </p>
        </div>

        {publicCalendar === 'Y' && (
          <div className="ml-6 space-y-2">
            <div className="flex items-center gap-2">
              <input
                id="seo-indexing"
                type="checkbox"
                checked={seoIndexing === 'Y'}
                onChange={(e) => setSeoIndexing(e.target.checked ? 'Y' : 'N')}
                className="h-4 w-4"
              />
              <label htmlFor="seo-indexing" className="text-sm font-medium">
                Allow search engines to index my public events
              </label>
            </div>
            <p className="text-xs text-muted-foreground">
              When enabled, your public events may appear in Google and other search engine results.
              Disable this to keep your calendar shareable via link but hidden from search engines.
            </p>
          </div>
        )}

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
        {subscribed && <span className="text-xs text-green-600">Active</span>}
      </div>
      <p className="text-xs text-muted-foreground">
        Receive browser notifications for event reminders and calendar updates.
      </p>
    </div>
  );
}
