import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface FeatureToggle {
  key: string;
  label: string;
  description: string;
  inverted: boolean; // If true, Y means disabled
}

const FEATURES: FeatureToggle[] = [
  {
    key: 'ALLOW_HTML_DESCRIPTION',
    label: 'Rich Text Descriptions',
    description:
      'Allow HTML formatting in event, task, and journal descriptions using the rich text editor.',
    inverted: false,
  },
  {
    key: 'DISABLE_LOCATION_FIELD',
    label: 'Location Field',
    description: 'Show the location field on event create/edit forms.',
    inverted: true,
  },
  {
    key: 'DISABLE_URL_FIELD',
    label: 'URL Field',
    description: 'Show the URL field on event forms.',
    inverted: true,
  },
  {
    key: 'DISABLE_PRIORITY_FIELD',
    label: 'Priority Field',
    description: 'Show the priority field on task forms.',
    inverted: true,
  },
  {
    key: 'DISABLE_PARTICIPANTS_FIELD',
    label: 'Participants',
    description: 'Allow adding participants to events.',
    inverted: true,
  },
  {
    key: 'DISABLE_EXT_PARTICIPANTS_FIELD',
    label: 'External Participants',
    description:
      'Allow inviting email-only external guests (customers, vendors, etc.) who do not have user accounts.',
    inverted: true,
  },
  {
    key: 'DISABLE_TASKS',
    label: 'Tasks',
    description:
      'Enable the Tasks module for tracking to-dos with due dates and completion status.',
    inverted: true,
  },
  {
    key: 'DISABLE_JOURNALS',
    label: 'Journals',
    description: 'Enable the Journals module for daily notes and entries.',
    inverted: true,
  },
  {
    key: 'ENABLE_SEO_PAGES',
    label: 'Public Event Pages for Search Engines',
    description:
      'Enable server-rendered event detail pages that search engines can crawl. Individual users can opt out in their preferences.',
    inverted: false,
  },
  {
    key: 'DISABLE_ATTACHMENTS',
    label: 'Event Attachments',
    description: 'Allow users to attach files to events.',
    inverted: true,
  },
  {
    key: 'DISABLE_COMMENTS',
    label: 'Event Comments',
    description: 'Allow participants to add comments to events.',
    inverted: true,
  },
  {
    key: 'ENABLE_GEOCODING',
    label: 'Location Geocoding',
    description: 'Automatically geocode event locations to show maps on public event pages.',
    inverted: false,
  },
  {
    key: 'ENABLE_EMAIL_REMINDERS',
    label: 'Email Reminders',
    description:
      'Send email reminders before events. Individual users can configure their reminder timing in preferences.',
    inverted: false,
  },
  {
    key: 'ENABLE_DAILY_AGENDA',
    label: 'Daily Agenda Email',
    description: 'Allow users to opt in to a daily email summarizing their events for the day.',
    inverted: false,
  },
];

interface NumericSetting {
  key: string;
  label: string;
  description: string;
  min: number;
  max: number;
  defaultValue: number;
}

interface TextSetting {
  key: string;
  label: string;
  description: string;
  placeholder: string;
}

const TEXT_SETTINGS: TextSetting[] = [
  {
    key: 'SEO_OG_IMAGE_URL',
    label: 'Social Share Image URL',
    description:
      'URL of the image shown when public event pages are shared on social media (og:image). Leave empty for no image. Recommended size: 1200x630px.',
    placeholder: 'https://example.com/calendar-card.png',
  },
];

const NUMERIC_SETTINGS: NumericSetting[] = [
  {
    key: 'MAX_EVENTS_PER_PAGE',
    label: 'Max Events Per API Request',
    description:
      'Maximum number of events returned per API request. Must be high enough to show all events in month view. Default: 1000.',
    min: 50,
    max: 10000,
    defaultValue: 1000,
  },
];

type DurationUnit = 'minutes' | 'hours' | 'days';

const UNIT_SECONDS: Record<DurationUnit, number> = {
  minutes: 60,
  hours: 3600,
  days: 86400,
};

/** Decompose seconds into { value, unit } picking the most natural unit. */
function decomposeDuration(totalSeconds: number): { value: number; unit: DurationUnit } {
  if (totalSeconds >= 86400 && totalSeconds % 86400 === 0) {
    return { value: totalSeconds / 86400, unit: 'days' };
  }
  if (totalSeconds >= 3600 && totalSeconds % 3600 === 0) {
    return { value: totalSeconds / 3600, unit: 'hours' };
  }
  return { value: Math.round(totalSeconds / 60), unit: 'minutes' };
}

interface DurationSetting {
  key: string;
  label: string;
  description: string;
  defaultSeconds: number;
  minSeconds: number;
  maxSeconds: number;
}

const SESSION_SETTINGS: DurationSetting[] = [
  {
    key: 'SESSION_TTL',
    label: 'Session Duration',
    description:
      'How long a login session lasts without "Remember me". Tokens auto-refresh while the user is active.',
    defaultSeconds: 28800,
    minSeconds: 1800,
    maxSeconds: 86400,
  },
  {
    key: 'SESSION_TTL_REMEMBER_ME',
    label: 'Remember Me Duration',
    description: 'How long a session lasts when "Remember me" is checked at login.',
    defaultSeconds: 2592000,
    minSeconds: 86400,
    maxSeconds: 7776000,
  },
];

export function AdminSettingsPage() {
  const [config, setConfig] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const { toast } = useToast();

  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<Record<string, string>>('/admin/config');
      if (data) setConfig(data);
      setLoading(false);
    })();
  }, []);

  const handleToggle = useCallback(
    async (key: string, inverted: boolean, checked: boolean) => {
      // For inverted fields: checked = enabled = 'N' (not disabled)
      // For normal fields: checked = enabled = 'Y'
      const value = inverted ? (checked ? 'N' : 'Y') : checked ? 'Y' : 'N';

      // Optimistic update
      setConfig((prev) => ({ ...prev, [key]: value }));

      const { error } = await apiFetch('/admin/config', {
        method: 'PUT',
        body: JSON.stringify({ [key]: value }),
      });

      if (!error) {
        toast({ title: 'Setting saved', variant: 'success' });
      } else {
        toast({ title: 'Failed to save', variant: 'error' });
        // Revert
        setConfig((prev) => ({ ...prev, [key]: value === 'Y' ? 'N' : 'Y' }));
      }
    },
    [toast],
  );

  const handleNumericBlur = useCallback(
    async (key: string, min: number, max: number, defaultValue: number) => {
      const raw = parseInt(config[key] ?? String(defaultValue), 10);
      const clamped = Math.max(min, Math.min(max, isNaN(raw) ? defaultValue : raw));
      const value = String(clamped);

      setConfig((prev) => ({ ...prev, [key]: value }));

      const { error } = await apiFetch('/admin/config', {
        method: 'PUT',
        body: JSON.stringify({ [key]: value }),
      });

      if (!error) {
        toast({ title: 'Setting saved', variant: 'success' });
      } else {
        toast({ title: 'Failed to save', variant: 'error' });
      }
    },
    [config, toast],
  );

  const handleTextBlur = useCallback(
    async (key: string) => {
      const value = config[key] ?? '';

      const { error } = await apiFetch('/admin/config', {
        method: 'PUT',
        body: JSON.stringify({ [key]: value }),
      });

      if (!error) {
        toast({ title: 'Setting saved', variant: 'success' });
      } else {
        toast({ title: 'Failed to save', variant: 'error' });
      }
    },
    [config, toast],
  );

  const isEnabled = (key: string, inverted: boolean): boolean => {
    const value = config[key] ?? (inverted ? 'N' : 'Y');
    return inverted ? value === 'N' : value === 'Y';
  };

  if (loading) {
    return <p className="text-muted-foreground">Loading...</p>;
  }

  return (
    <div>
      <h2 className="text-2xl font-bold">System Settings</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Enable or disable features for all users.
      </p>

      <div className="mt-6 max-w-lg space-y-4">
        {FEATURES.map((feature) => (
          <label
            key={feature.key}
            className="flex cursor-pointer items-start gap-3 rounded-lg border p-4 hover:bg-accent/30"
          >
            <input
              type="checkbox"
              checked={isEnabled(feature.key, feature.inverted)}
              onChange={(e) => void handleToggle(feature.key, feature.inverted, e.target.checked)}
              className="mt-0.5 h-5 w-5 rounded border-input"
            />
            <div>
              <span className="text-sm font-medium">{feature.label}</span>
              <p className="mt-0.5 text-xs text-muted-foreground">{feature.description}</p>
            </div>
          </label>
        ))}
      </div>

      <h3 className="mt-10 text-lg font-semibold">Limits</h3>
      <p className="mt-1 text-sm text-muted-foreground">
        Configure system limits. Changes take effect on the next page load.
      </p>

      <div className="mt-4 max-w-lg space-y-4">
        {NUMERIC_SETTINGS.map((setting) => (
          <div key={setting.key} className="rounded-lg border p-4">
            <label className="text-sm font-medium" htmlFor={setting.key}>
              {setting.label}
            </label>
            <p className="mt-0.5 text-xs text-muted-foreground">{setting.description}</p>
            <input
              id={setting.key}
              type="number"
              min={setting.min}
              max={setting.max}
              value={config[setting.key] ?? String(setting.defaultValue)}
              onChange={(e) => setConfig((prev) => ({ ...prev, [setting.key]: e.target.value }))}
              onBlur={() =>
                void handleNumericBlur(setting.key, setting.min, setting.max, setting.defaultValue)
              }
              className="mt-2 w-32 rounded-md border border-input bg-background px-3 py-1.5 text-sm"
            />
          </div>
        ))}
      </div>

      <h3 className="mt-10 text-lg font-semibold">SEO</h3>
      <p className="mt-1 text-sm text-muted-foreground">
        Configure how public event pages appear to search engines and social media.
      </p>

      <div className="mt-4 max-w-lg space-y-4">
        {TEXT_SETTINGS.map((setting) => (
          <div key={setting.key} className="rounded-lg border p-4">
            <label className="text-sm font-medium" htmlFor={setting.key}>
              {setting.label}
            </label>
            <p className="mt-0.5 text-xs text-muted-foreground">{setting.description}</p>
            <input
              id={setting.key}
              type="text"
              value={config[setting.key] ?? ''}
              placeholder={setting.placeholder}
              onChange={(e) => setConfig((prev) => ({ ...prev, [setting.key]: e.target.value }))}
              onBlur={() => void handleTextBlur(setting.key)}
              className="mt-2 w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm"
            />
          </div>
        ))}
      </div>

      <h3 className="mt-10 text-lg font-semibold">Sessions</h3>
      <p className="mt-1 text-sm text-muted-foreground">
        Configure how long login sessions last. Tokens auto-refresh while the user is active.
      </p>

      <div className="mt-4 max-w-lg space-y-4">
        {SESSION_SETTINGS.map((setting) => (
          <DurationInput
            key={setting.key}
            setting={setting}
            seconds={parseInt(config[setting.key] ?? String(setting.defaultSeconds), 10)}
            onSave={(seconds) => {
              const value = String(seconds);
              setConfig((prev) => ({ ...prev, [setting.key]: value }));
              void (async () => {
                const { error } = await apiFetch('/admin/config', {
                  method: 'PUT',
                  body: JSON.stringify({ [setting.key]: value }),
                });
                if (!error) {
                  toast({ title: 'Setting saved', variant: 'success' });
                } else {
                  toast({ title: 'Failed to save', variant: 'error' });
                }
              })();
            }}
          />
        ))}
      </div>
    </div>
  );
}

function DurationInput({
  setting,
  seconds,
  onSave,
}: {
  setting: DurationSetting;
  seconds: number;
  onSave: (seconds: number) => void;
}) {
  const initial = decomposeDuration(isNaN(seconds) ? setting.defaultSeconds : seconds);
  const [value, setValue] = useState(initial.value);
  const [unit, setUnit] = useState<DurationUnit>(initial.unit);

  // Recompute when the stored seconds change externally
  useEffect(() => {
    const s = isNaN(seconds) ? setting.defaultSeconds : seconds;
    const d = decomposeDuration(s);
    setValue(d.value);
    setUnit(d.unit);
  }, [seconds, setting.defaultSeconds]);

  const handleSave = () => {
    const raw = Math.max(1, isNaN(value) ? 1 : value);
    const totalSeconds = raw * UNIT_SECONDS[unit];
    const clamped = Math.max(setting.minSeconds, Math.min(setting.maxSeconds, totalSeconds));
    // Re-decompose in case clamping changed the value
    const d = decomposeDuration(clamped);
    setValue(d.value);
    setUnit(d.unit);
    onSave(clamped);
  };

  return (
    <div className="rounded-lg border p-4">
      <label className="text-sm font-medium">{setting.label}</label>
      <p className="mt-0.5 text-xs text-muted-foreground">{setting.description}</p>
      <div className="mt-2 flex items-center gap-2">
        <input
          type="number"
          min={1}
          value={value}
          onChange={(e) => setValue(parseInt(e.target.value, 10))}
          onBlur={handleSave}
          className="w-20 rounded-md border border-input bg-background px-3 py-1.5 text-sm"
        />
        <select
          value={unit}
          onChange={(e) => {
            const newUnit = e.target.value as DurationUnit;
            // Convert current value to the new unit
            const totalSeconds = value * UNIT_SECONDS[unit];
            const converted = Math.max(1, Math.round(totalSeconds / UNIT_SECONDS[newUnit]));
            setUnit(newUnit);
            setValue(converted);
            // Save after unit change
            const clamped = Math.max(
              setting.minSeconds,
              Math.min(setting.maxSeconds, converted * UNIT_SECONDS[newUnit]),
            );
            onSave(clamped);
          }}
          className="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
        >
          <option value="minutes">minutes</option>
          <option value="hours">hours</option>
          <option value="days">days</option>
        </select>
      </div>
    </div>
  );
}
