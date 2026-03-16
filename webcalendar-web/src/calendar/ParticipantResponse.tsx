import { statusLabel, statusColor } from './ParticipantBadge';

interface ParticipantResponseProps {
  status: string;
  onAccept: () => void;
  onReject: () => void;
  isLoading?: boolean;
}

export function ParticipantResponse({ status, onAccept, onReject, isLoading = false }: ParticipantResponseProps) {
  const isPending = status === 'W';
  const isAccepted = status === 'A';
  const isRejected = status === 'R';

  return (
    <div className="flex items-center gap-2">
      {!isPending && (
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColor(status)}`}>
          {statusLabel(status)}
        </span>
      )}

      {(isPending || isRejected) && (
        <button
          onClick={onAccept}
          disabled={isLoading}
          className="inline-flex h-8 items-center rounded-md bg-green-600 px-3 text-xs font-medium text-white hover:bg-green-700 disabled:opacity-50"
        >
          Accept
        </button>
      )}

      {(isPending || isAccepted) && (
        <button
          onClick={onReject}
          disabled={isLoading}
          className="inline-flex h-8 items-center rounded-md bg-red-600 px-3 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50"
        >
          Decline
        </button>
      )}
    </div>
  );
}
