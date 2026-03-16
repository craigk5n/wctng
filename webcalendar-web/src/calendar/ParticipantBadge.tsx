const STATUS_MAP: Record<string, { label: string; color: string }> = {
  A: { label: 'Accepted', color: 'bg-green-100 text-green-800' },
  R: { label: 'Rejected', color: 'bg-red-100 text-red-800' },
  W: { label: 'Pending', color: 'bg-yellow-100 text-yellow-800' },
  C: { label: 'Completed', color: 'bg-blue-100 text-blue-800' },
  P: { label: 'In Progress', color: 'bg-blue-100 text-blue-800' },
  D: { label: 'Deleted', color: 'bg-gray-100 text-gray-500' },
};

export function statusLabel(status: string): string {
  return STATUS_MAP[status]?.label ?? status;
}

export function statusColor(status: string): string {
  return STATUS_MAP[status]?.color ?? 'bg-gray-100 text-gray-600';
}

interface ParticipantBadgeProps {
  login: string;
  status: string;
}

export function ParticipantBadge({ login, status }: ParticipantBadgeProps) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-sm font-medium">{login}</span>
      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColor(status)}`}>
        {statusLabel(status)}
      </span>
    </div>
  );
}
