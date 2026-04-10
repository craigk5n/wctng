import { useEffect, useRef, useState } from 'react';
import { ParticipantBadge, statusColor, statusLabel } from './ParticipantBadge';

export interface Participant {
  login: string;
  status: string;
}

interface ParticipantListProps {
  participants: Participant[];
  editable?: boolean;
  onRemove?: (login: string) => void;
  /** Current user's login — enables the inline response dropdown for their row. */
  currentUserLogin?: string;
  /** Called when the current user changes their response status (e.g. 'A' or 'R'). */
  onRespond?: (status: string) => void;
  /** When true, the response control is disabled while a request is in flight. */
  isResponding?: boolean;
}

const RESPONSE_OPTIONS: ReadonlyArray<{ status: string; label: string; icon: string }> = [
  { status: 'A', label: 'Accepted', icon: '✓' },
  { status: 'R', label: 'Declined', icon: '✕' },
];

interface ResponsePillProps {
  status: string;
  onRespond: (status: string) => void;
  isResponding: boolean;
}

function ResponsePill({ status, onRespond, isResponding }: ResponsePillProps) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const handleSelect = (next: string) => {
    setOpen(false);
    if (next !== status) onRespond(next);
  };

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        disabled={isResponding}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Change your response (currently ${statusLabel(status)})`}
        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium hover:brightness-95 disabled:opacity-50 ${statusColor(status)}`}
      >
        <span>{statusLabel(status)}</span>
        <span aria-hidden="true" className="text-[9px] leading-none">
          ▾
        </span>
      </button>
      {open && (
        <div
          role="menu"
          className="absolute right-0 z-10 mt-1 w-32 overflow-hidden rounded-md border border-border bg-card py-1 shadow-lg"
        >
          {RESPONSE_OPTIONS.map((opt) => {
            const selected = opt.status === status;
            return (
              <button
                key={opt.status}
                type="button"
                role="menuitemradio"
                aria-checked={selected}
                onClick={() => handleSelect(opt.status)}
                className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs hover:bg-accent ${selected ? 'font-semibold' : ''}`}
              >
                <span className="w-3 text-center">{selected ? '•' : ''}</span>
                <span>{opt.icon}</span>
                <span>{opt.label}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

export function ParticipantList({
  participants,
  editable = false,
  onRemove,
  currentUserLogin,
  onRespond,
  isResponding = false,
}: ParticipantListProps) {
  if (participants.length === 0) {
    return <p className="text-sm text-muted-foreground">No participants</p>;
  }

  return (
    <div className="space-y-1.5">
      {participants.map((p) => {
        const isMe = !!currentUserLogin && p.login === currentUserLogin && !!onRespond;
        return (
          <div key={p.login} className="flex items-center justify-between gap-2">
            {isMe ? (
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium">
                  {p.login} <span className="text-muted-foreground">(you)</span>
                </span>
                <ResponsePill
                  status={p.status}
                  onRespond={onRespond!}
                  isResponding={isResponding}
                />
              </div>
            ) : (
              <ParticipantBadge login={p.login} status={p.status} />
            )}
            {editable && onRemove && (
              <button
                onClick={() => onRemove(p.login)}
                aria-label={`Remove ${p.login}`}
                className="rounded px-1.5 py-0.5 text-xs text-destructive hover:bg-destructive/10"
              >
                Remove
              </button>
            )}
          </div>
        );
      })}
    </div>
  );
}
