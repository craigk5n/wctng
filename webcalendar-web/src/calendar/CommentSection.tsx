import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useFeatureFlags } from '../hooks/useFeatureFlags';

interface Comment {
  id: number;
  event_id: number;
  user_login: string;
  text: string;
  created_at: string;
}

interface CommentSectionProps {
  eventId: number;
  currentUserLogin?: string;
}

export function CommentSection({ eventId, currentUserLogin }: CommentSectionProps) {
  const flags = useFeatureFlags();
  const [comments, setComments] = useState<Comment[]>([]);
  const [newComment, setNewComment] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const fetchComments = useCallback(async () => {
    const { data } = await apiFetch<Comment[]>(`/events/${eventId}/comments`);
    if (data) setComments(data);
  }, [eventId]);

  useEffect(() => {
    if (flags.DISABLE_COMMENTS !== 'Y') {
      void fetchComments();
    }
  }, [fetchComments, flags.DISABLE_COMMENTS]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newComment.trim()) return;

    setSubmitting(true);
    const { error } = await apiFetch(`/events/${eventId}/comments`, {
      method: 'POST',
      body: JSON.stringify({ text: newComment.trim() }),
    });
    setSubmitting(false);

    if (!error) {
      setNewComment('');
      void fetchComments();
    }
  };

  const handleDelete = async (commentId: number) => {
    const { error } = await apiFetch(`/events/${eventId}/comments/${commentId}`, {
      method: 'DELETE',
    });
    if (!error) void fetchComments();
  };

  if (flags.DISABLE_COMMENTS === 'Y') return null;

  return (
    <div className="mt-4 space-y-2">
      <h4 className="text-xs font-semibold uppercase text-muted-foreground">Comments</h4>

      {comments.map((c) => (
        <div key={c.id} className="rounded border border-border/50 p-2 text-sm">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium">{c.user_login}</span>
            <div className="flex items-center gap-2">
              <span className="text-[10px] text-muted-foreground">
                {new Date(c.created_at).toLocaleString()}
              </span>
              {currentUserLogin === c.user_login && (
                <button
                  onClick={() => void handleDelete(c.id)}
                  className="text-[10px] text-destructive hover:underline"
                  aria-label={`Delete comment by ${c.user_login}`}
                >
                  delete
                </button>
              )}
            </div>
          </div>
          <p className="mt-1 whitespace-pre-wrap text-sm">{c.text}</p>
        </div>
      ))}

      <form onSubmit={handleSubmit} className="flex gap-2">
        <input
          type="text"
          value={newComment}
          onChange={(e) => setNewComment(e.target.value)}
          placeholder="Add a comment..."
          aria-label="Add comment"
          className="h-8 flex-1 rounded-md border border-input bg-background px-2 text-sm"
        />
        <button
          type="submit"
          disabled={submitting || !newComment.trim()}
          className="h-8 rounded bg-primary px-3 text-xs text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
        >
          Post
        </button>
      </form>
    </div>
  );
}
