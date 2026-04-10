import { useCallback, useEffect, useRef, useState } from 'react';
import { apiFetch, getApiBaseUrl, getAuthHeaders } from '../api/client';
import { useFeatureFlags } from '../hooks/useFeatureFlags';

interface Attachment {
  id: number;
  filename: string;
  mime_type: string;
  size: number;
  created_at: string;
  uploaded_by: string;
}

interface AttachmentSectionProps {
  eventId: number;
  currentUserLogin?: string;
  eventOwner: string;
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function isImage(mimeType: string): boolean {
  return mimeType.startsWith('image/');
}

export function AttachmentSection({
  eventId,
  currentUserLogin,
  eventOwner,
}: AttachmentSectionProps) {
  const flags = useFeatureFlags();
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const canManage = currentUserLogin === eventOwner;

  const fetchAttachments = useCallback(async () => {
    const { data } = await apiFetch<Attachment[]>(`/events/${eventId}/attachments`);
    setAttachments(data ?? []);
    setLoading(false);
  }, [eventId]);

  useEffect(() => {
    void fetchAttachments();
  }, [fetchAttachments]);

  const handleUpload = useCallback(
    async (file: File) => {
      setUploading(true);
      setUploadError(null);

      const formData = new FormData();
      formData.append('file', file);

      const baseUrl = getApiBaseUrl();
      const headers = getAuthHeaders();
      // Remove Content-Type — browser sets it with boundary for multipart
      delete headers['Content-Type'];

      try {
        const res = await fetch(`${baseUrl}/events/${eventId}/attachments`, {
          method: 'POST',
          headers,
          body: formData,
        });

        const body = await res.json();
        if (!res.ok) {
          setUploadError(body?.error?.message ?? 'Upload failed');
        } else {
          void fetchAttachments();
        }
      } catch {
        setUploadError('Upload failed');
      } finally {
        setUploading(false);
      }
    },
    [eventId, fetchAttachments],
  );

  const handleFileSelect = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      if (file) void handleUpload(file);
      // Reset input so the same file can be selected again
      e.target.value = '';
    },
    [handleUpload],
  );

  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault();
      setDragOver(false);
      const file = e.dataTransfer.files[0];
      if (file) void handleUpload(file);
    },
    [handleUpload],
  );

  const handleDelete = useCallback(
    async (attachmentId: number) => {
      const { error } = await apiFetch(`/events/${eventId}/attachments/${attachmentId}`, {
        method: 'DELETE',
      });
      if (error) {
        setUploadError(error.message ?? 'Failed to delete attachment');
        return;
      }
      void fetchAttachments();
    },
    [eventId, fetchAttachments],
  );

  if (flags.DISABLE_ATTACHMENTS === 'Y') return null;
  if (loading) return null;

  // Hide the whole section when there's nothing to show and nothing to do.
  if (attachments.length === 0 && !canManage) return null;

  return (
    <div className="space-y-2">
      {attachments.length > 0 && (
        <span className="font-medium text-muted-foreground">Attachments:</span>
      )}

      {/* File list */}
      {attachments.length > 0 && (
        <div className="space-y-1.5">
          {attachments.map((att) => (
            <div
              key={att.id}
              className="flex items-center gap-2 rounded border bg-muted/30 px-2 py-1.5 text-xs"
            >
              {isImage(att.mime_type) ? (
                <img
                  src={`${getApiBaseUrl()}/events/${eventId}/attachments/${att.id}`}
                  alt={att.filename}
                  className="h-8 w-8 rounded object-cover"
                />
              ) : (
                <span className="flex h-8 w-8 items-center justify-center rounded bg-muted text-[10px] font-medium uppercase text-muted-foreground">
                  {att.filename.split('.').pop()?.slice(0, 3) ?? '?'}
                </span>
              )}
              <div className="min-w-0 flex-1">
                <a
                  href={`${getApiBaseUrl()}/events/${eventId}/attachments/${att.id}`}
                  className="block truncate font-medium text-foreground hover:underline"
                  download={att.filename}
                >
                  {att.filename}
                </a>
                <span className="text-muted-foreground">{formatSize(att.size)}</span>
              </div>
              {canManage && (
                <button
                  type="button"
                  title="Delete attachment"
                  onClick={() => void handleDelete(att.id)}
                  className="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                >
                  ✕
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Upload zone */}
      {canManage && (
        <>
          <div
            className={`mt-2 rounded-md border-2 border-dashed p-3 text-center text-xs transition-colors ${
              dragOver ? 'border-primary bg-primary/5' : 'border-border'
            }`}
            onDragOver={(e) => {
              e.preventDefault();
              setDragOver(true);
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={handleDrop}
          >
            {uploading ? (
              <span className="text-muted-foreground">Uploading...</span>
            ) : (
              <>
                <span className="text-muted-foreground">Drag & drop a file here, or </span>
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  className="font-medium text-primary hover:underline"
                >
                  Upload
                </button>
              </>
            )}
          </div>
          <input ref={fileInputRef} type="file" className="hidden" onChange={handleFileSelect} />
          {uploadError && <p className="text-xs text-destructive">{uploadError}</p>}
        </>
      )}
    </div>
  );
}
