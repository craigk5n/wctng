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
    <div className="group relative flex items-center gap-1" role="radiogroup" aria-label="Working location for today">
      {LOCATIONS.map((loc) => (
        <button
          key={loc.value}
          onClick={() => void handleChange(loc.value)}
          role="radio"
          aria-checked={location === loc.value}
          aria-label={loc.label}
          className={`relative rounded px-1.5 py-0.5 text-xs transition-colors ${
            location === loc.value
              ? 'bg-primary/10 text-primary font-medium ring-1 ring-primary/30'
              : 'text-muted-foreground hover:bg-accent'
          }`}
        >
          {loc.icon}
          {/* Tooltip */}
          <span className="pointer-events-none absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded bg-popover px-2 py-1 text-[11px] font-normal text-popover-foreground shadow-md border border-border opacity-0 transition-opacity group-hover:opacity-0 hover:!opacity-100 peer-hover:opacity-0"
            style={{ opacity: 0 }}
            aria-hidden="true"
          />
        </button>
      ))}
      {/* Group-level tooltip showing current + explanation */}
      <span className="pointer-events-none absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded bg-popover px-2 py-1 text-[11px] text-popover-foreground shadow-md border border-border opacity-0 transition-opacity group-hover:opacity-100"
        aria-hidden="true"
      >
        Working location: {LOCATIONS.find((l) => l.value === location)?.label ?? 'Office'}
      </span>
    </div>
  );
}
