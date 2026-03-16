import { ParticipantBadge } from './ParticipantBadge';

export interface Participant {
  login: string;
  status: string;
}

interface ParticipantListProps {
  participants: Participant[];
  editable?: boolean;
  onRemove?: (login: string) => void;
}

export function ParticipantList({ participants, editable = false, onRemove }: ParticipantListProps) {
  if (participants.length === 0) {
    return <p className="text-sm text-muted-foreground">No participants</p>;
  }

  return (
    <div className="space-y-1.5">
      {participants.map((p) => (
        <div key={p.login} className="flex items-center justify-between">
          <ParticipantBadge login={p.login} status={p.status} />
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
      ))}
    </div>
  );
}
