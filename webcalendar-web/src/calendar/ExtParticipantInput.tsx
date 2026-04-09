import { useState } from 'react';

export interface ExtParticipant {
  name: string;
  email: string | null;
}

interface ExtParticipantInputProps {
  value: ExtParticipant[];
  onChange: (next: ExtParticipant[]) => void;
}

/**
 * Chip-list input for external (email-only) event participants.
 *
 * Legacy-style external invitees: a display name is required, email
 * is optional. Duplicates are dropped by trimmed name.
 */
export function ExtParticipantInput({ value, onChange }: ExtParticipantInputProps) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);

  const add = () => {
    const trimmedName = name.trim();
    const trimmedEmail = email.trim();
    if (trimmedName === '') {
      setError('Name is required');
      return;
    }
    if (value.some((p) => p.name === trimmedName)) {
      setError('Already added');
      return;
    }
    if (trimmedEmail !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
      setError('Invalid email address');
      return;
    }
    onChange([...value, { name: trimmedName, email: trimmedEmail === '' ? null : trimmedEmail }]);
    setName('');
    setEmail('');
    setError(null);
  };

  const remove = (target: ExtParticipant) => {
    onChange(value.filter((p) => p.name !== target.name));
  };

  return (
    <div className="space-y-2">
      <span className="text-sm font-medium">External participants</span>
      <div className="flex flex-wrap gap-2">
        <input
          type="text"
          value={name}
          onChange={(e) => {
            setName(e.target.value);
            setError(null);
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              add();
            }
          }}
          placeholder="Full name"
          aria-label="External participant name"
          className="flex h-9 min-w-[140px] flex-1 rounded-md border border-input bg-background px-3 py-1 text-sm"
        />
        <input
          type="email"
          value={email}
          onChange={(e) => {
            setEmail(e.target.value);
            setError(null);
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              add();
            }
          }}
          placeholder="Email (optional)"
          aria-label="External participant email"
          className="flex h-9 min-w-[180px] flex-1 rounded-md border border-input bg-background px-3 py-1 text-sm"
        />
        <button
          type="button"
          onClick={add}
          className="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm hover:bg-accent"
        >
          Add
        </button>
      </div>
      {error && <p className="text-xs text-destructive">{error}</p>}
      {value.length > 0 && (
        <ul className="flex flex-wrap gap-1.5">
          {value.map((p) => (
            <li
              key={p.name}
              className="inline-flex items-center gap-1 rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium"
            >
              <span>✉️ {p.name}</span>
              {p.email && <span className="text-muted-foreground">&lt;{p.email}&gt;</span>}
              <button
                type="button"
                onClick={() => remove(p)}
                className="ml-0.5 text-muted-foreground hover:text-foreground"
                aria-label={`Remove ${p.name}`}
              >
                ✕
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
