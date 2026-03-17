import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { useAuth } from '../auth/auth-context';

interface Pref {
  key: string;
  value: string;
}

export function NotificationSettings() {
  const [invitations, setInvitations] = useState(true);
  const [updates, setUpdates] = useState(true);
  const [reminderMinutes, setReminderMinutes] = useState('30');
  const [isSaving, setIsSaving] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const { toast } = useToast();
  const { user } = useAuth();
  const login = user?.login ?? '';

  const fetchPrefs = useCallback(async () => {
    if (!login) {
      setIsLoading(false);
      return;
    }
    setIsLoading(true);
    const { data } = await apiFetch<Pref[]>(`/users/${login}/preferences`);
    if (data) {
      for (const p of data) {
        if (p.key === 'EMAIL_INVITATION') setInvitations(p.value !== 'N');
        if (p.key === 'EMAIL_UPDATE') setUpdates(p.value !== 'N');
        if (p.key === 'REMINDER_MINUTES') setReminderMinutes(p.value);
      }
    }
    setIsLoading(false);
  }, [login]);

  useEffect(() => {
    void fetchPrefs();
  }, [fetchPrefs]);

  const handleSave = async () => {
    if (!login) return;
    setIsSaving(true);

    const prefs = [
      { key: 'EMAIL_INVITATION', value: invitations ? 'Y' : 'N' },
      { key: 'EMAIL_UPDATE', value: updates ? 'Y' : 'N' },
      { key: 'REMINDER_MINUTES', value: reminderMinutes },
    ];

    let hasError = false;
    for (const p of prefs) {
      const { error } = await apiFetch(`/users/${login}/preferences/${p.key}`, {
        method: 'PUT',
        body: JSON.stringify({ value: p.value }),
      });
      if (error) hasError = true;
    }

    setIsSaving(false);
    toast({
      title: hasError ? 'Failed to save some preferences' : 'Notification preferences saved',
      variant: hasError ? 'error' : 'success',
    });
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Notifications</h2>
        <button
          onClick={() => void handleSave()}
          disabled={isSaving}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
        >
          {isSaving ? 'Saving...' : 'Save'}
        </button>
      </div>

      <p className="mt-2 text-sm text-muted-foreground">
        Configure how and when you receive email notifications.
      </p>

      {isLoading ? (
        <p className="mt-4 text-sm text-muted-foreground">Loading...</p>
      ) : (
        <div className="mt-6 space-y-6">
          <div className="rounded-lg border border-border p-4">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="font-medium">Event Invitations</h3>
                <p className="text-xs text-muted-foreground">Receive email when added as a participant</p>
              </div>
              <label className="relative inline-flex cursor-pointer items-center">
                <input
                  type="checkbox"
                  checked={invitations}
                  onChange={(e) => setInvitations(e.target.checked)}
                  className="peer sr-only"
                />
                <div className="h-6 w-11 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all peer-checked:bg-primary peer-checked:after:translate-x-full dark:bg-gray-700" />
              </label>
            </div>
          </div>

          <div className="rounded-lg border border-border p-4">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="font-medium">Event Updates</h3>
                <p className="text-xs text-muted-foreground">Receive email when events you're attending are changed or cancelled</p>
              </div>
              <label className="relative inline-flex cursor-pointer items-center">
                <input
                  type="checkbox"
                  checked={updates}
                  onChange={(e) => setUpdates(e.target.checked)}
                  className="peer sr-only"
                />
                <div className="h-6 w-11 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all peer-checked:bg-primary peer-checked:after:translate-x-full dark:bg-gray-700" />
              </label>
            </div>
          </div>

          <div className="rounded-lg border border-border p-4">
            <div>
              <h3 className="font-medium">Event Reminders</h3>
              <p className="text-xs text-muted-foreground">Receive a reminder email before upcoming events</p>
            </div>
            <div className="mt-3">
              <select
                value={reminderMinutes}
                onChange={(e) => setReminderMinutes(e.target.value)}
                className="flex h-10 w-full max-w-xs rounded-md border border-input bg-background px-3 py-2 text-sm"
                aria-label="Reminder time"
              >
                <option value="0">Disabled</option>
                <option value="15">15 minutes before</option>
                <option value="30">30 minutes before</option>
                <option value="60">1 hour before</option>
                <option value="1440">1 day before</option>
              </select>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
