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
    </div>
  );
}
