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
    description: 'Allow HTML formatting in event, task, and journal descriptions using the rich text editor.',
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

  const handleToggle = useCallback(async (key: string, inverted: boolean, checked: boolean) => {
    // For inverted fields: checked = enabled = 'N' (not disabled)
    // For normal fields: checked = enabled = 'Y'
    const value = inverted
      ? (checked ? 'N' : 'Y')
      : (checked ? 'Y' : 'N');

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
  }, [toast]);

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
            className="flex items-start gap-3 rounded-lg border p-4 cursor-pointer hover:bg-accent/30"
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
    </div>
  );
}
