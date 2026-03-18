import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useAuth } from '../auth/auth-context';

const LOCATIONS = [
  { value: 'office', label: 'Office', icon: '🏢' },
  { value: 'remote', label: 'Remote', icon: '🏠' },
  { value: 'traveling', label: 'Traveling', icon: '✈️' },
] as const;

export function WorkingLocationWidget() {
  const { user } = useAuth();
  const login = user?.login ?? '';
  const [location, setLocation] = useState('office');
  const today = new Date().toISOString().slice(0, 10);

  useEffect(() => {
    if (!login) return;
    void (async () => {
      const { data } = await apiFetch<{ location: string }>(`/users/${login}/location?date=${today}`);
      if (data?.location) setLocation(data.location);
    })();
  }, [login, today]);

  const handleChange = useCallback(async (newLocation: string) => {
    setLocation(newLocation);
    await apiFetch(`/users/${login}/location`, {
      method: 'PUT',
      body: JSON.stringify({ date: today, location: newLocation }),
    });
  }, [login, today]);

  return (
    <div className="flex items-center gap-1">
      {LOCATIONS.map((loc) => (
        <button
          key={loc.value}
          onClick={() => void handleChange(loc.value)}
          title={loc.label}
          className={`rounded px-1.5 py-0.5 text-xs transition-colors ${
            location === loc.value
              ? 'bg-primary/10 text-primary font-medium'
              : 'text-muted-foreground hover:bg-accent'
          }`}
        >
          {loc.icon}
        </button>
      ))}
    </div>
  );
}
