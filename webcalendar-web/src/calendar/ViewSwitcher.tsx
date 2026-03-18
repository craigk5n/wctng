import { useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface SavedView {
  id: number;
  name: string;
  user_logins: string[];
}

interface ViewSwitcherProps {
  onViewChange: (userLogins: string[]) => void;
}

export function ViewSwitcher({ onViewChange }: ViewSwitcherProps) {
  const [views, setViews] = useState<SavedView[]>([]);
  const [selected, setSelected] = useState('');

  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<SavedView[]>('/views');
      setViews(data ?? []);
    })();
  }, []);

  const handleChange = (value: string) => {
    setSelected(value);
    if (value === '') {
      onViewChange([]);
    } else {
      const view = views.find((v) => String(v.id) === value);
      if (view) onViewChange(view.user_logins);
    }
  };

  if (views.length === 0) return null;

  return (
    <select
      value={selected}
      onChange={(e) => handleChange(e.target.value)}
      className="flex h-9 rounded-md border border-input bg-background px-2 text-xs"
      title="Switch view"
    >
      <option value="">My Calendar</option>
      {views.map((v) => (
        <option key={v.id} value={String(v.id)}>
          {v.name}
        </option>
      ))}
    </select>
  );
}
