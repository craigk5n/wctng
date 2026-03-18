import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiFetch } from '../api/client';
import { useAuth } from '../auth/auth-context';

interface PollOption {
  id: number;
  start: string;
  end: string;
  votes: Array<{ voter: string; vote: string }>;
  yes_count: number;
}

interface Poll {
  id: number;
  creator: string;
  title: string;
  description: string;
  status: string;
  created_at: string;
  options: PollOption[];
}

export function PollVotingPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const [poll, setPoll] = useState<Poll | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [myVotes, setMyVotes] = useState<Record<number, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [finalizing, setFinalizing] = useState(false);

  const fetchPoll = useCallback(async () => {
    if (!id) return;
    const { data, error } = await apiFetch<Poll>(`/polls/${id}`);
    if (error || !data) {
      setNotFound(true);
    } else {
      setPoll(data);
      // Pre-fill user's existing votes
      const existing: Record<number, string> = {};
      for (const opt of data.options) {
        const myVote = opt.votes.find((v) => v.voter === user?.login);
        if (myVote) existing[opt.id] = myVote.vote;
      }
      setMyVotes(existing);
    }
    setLoading(false);
  }, [id, user?.login]);

  useEffect(() => {
    void fetchPoll();
  }, [fetchPoll]);

  const handleVoteChange = (optionId: number, vote: string) => {
    setMyVotes((prev) => ({ ...prev, [optionId]: vote }));
  };

  const handleSubmitVotes = async () => {
    if (!id) return;
    setSubmitting(true);
    await apiFetch(`/polls/${id}/vote`, {
      method: 'POST',
      body: JSON.stringify({ votes: myVotes }),
    });
    setSubmitting(false);
    void fetchPoll();
  };

  const handleFinalize = async () => {
    if (!id) return;
    setFinalizing(true);
    await apiFetch(`/polls/${id}/finalize`, { method: 'POST' });
    setFinalizing(false);
    void fetchPoll();
  };

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-muted-foreground">Loading...</div>;
  }

  if (notFound || !poll) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center">
        <h1 className="text-2xl font-bold">Poll not found</h1>
      </div>
    );
  }

  const isClosed = poll.status === 'closed';
  const isCreator = user?.login === poll.creator;

  // Find winning option
  let bestCount = 0;
  let bestOptionId = 0;
  for (const opt of poll.options) {
    if (opt.yes_count > bestCount) {
      bestCount = opt.yes_count;
      bestOptionId = opt.id;
    }
  }

  return (
    <div className="mx-auto max-w-3xl p-6">
      <h1 className="text-2xl font-bold">{poll.title}</h1>
      {poll.description && <p className="mt-1 text-muted-foreground">{poll.description}</p>}

      <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
        <span>Created by {poll.creator}</span>
        {isClosed && (
          <span className="rounded bg-muted px-2 py-0.5 font-medium">Closed / Finalized</span>
        )}
      </div>

      {/* Options grid */}
      <div className="mt-6 space-y-3">
        {poll.options.map((opt) => {
          const isWinner = opt.id === bestOptionId && bestCount > 0;
          return (
            <div
              key={opt.id}
              className={`rounded-lg border p-4 ${isWinner && isClosed ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : ''}`}
            >
              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium">
                    {formatDateTime(opt.start)} — {formatTime(opt.end)}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {formatDate(opt.start)}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <span className="rounded-full bg-green-100 px-2.5 py-0.5 text-sm font-semibold text-green-800 dark:bg-green-900/30 dark:text-green-300">
                    {opt.yes_count}
                  </span>
                  {isWinner && isClosed && (
                    <span className="text-xs font-medium text-green-600">Winner</span>
                  )}
                </div>
              </div>

              {/* Voter list */}
              {opt.votes.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                  {opt.votes.map((v) => (
                    <span
                      key={v.voter}
                      className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                        v.vote === 'yes' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                        : v.vote === 'maybe' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'
                        : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
                      }`}
                    >
                      {v.voter}: {v.vote}
                    </span>
                  ))}
                </div>
              )}

              {/* Vote buttons */}
              {!isClosed && (
                <div className="mt-3 flex gap-2">
                  {(['yes', 'maybe', 'no'] as const).map((vote) => (
                    <button
                      key={vote}
                      onClick={() => handleVoteChange(opt.id, vote)}
                      className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                        myVotes[opt.id] === vote
                          ? vote === 'yes' ? 'bg-green-500 text-white'
                            : vote === 'maybe' ? 'bg-yellow-500 text-white'
                            : 'bg-red-500 text-white'
                          : 'border border-border hover:bg-accent'
                      }`}
                    >
                      {vote === 'yes' ? '✓ Yes' : vote === 'maybe' ? '? Maybe' : '✕ No'}
                    </button>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* Actions */}
      {!isClosed && (
        <div className="mt-6 flex gap-3">
          <button
            onClick={handleSubmitVotes}
            disabled={submitting || Object.keys(myVotes).length === 0}
            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {submitting ? 'Submitting...' : 'Submit Votes'}
          </button>
          {isCreator && (
            <button
              onClick={handleFinalize}
              disabled={finalizing}
              className="rounded-md border border-green-600 px-4 py-2 text-sm font-medium text-green-600 hover:bg-green-50 disabled:opacity-50"
            >
              {finalizing ? 'Finalizing...' : 'Finalize & Create Event'}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function formatDateTime(dt: string): string {
  const d = new Date(dt);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatTime(dt: string): string {
  const d = new Date(dt);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDate(dt: string): string {
  const d = new Date(dt);
  return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
}
