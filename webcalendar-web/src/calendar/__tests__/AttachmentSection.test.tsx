import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { AttachmentSection } from '../AttachmentSection';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('AttachmentSection', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('shows file list after loading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 10, filename: 'report.pdf', mime_type: 'application/pdf', size: 2048, created_at: '2026-04-01T10:00:00', uploaded_by: 'alice' },
          { id: 11, filename: 'photo.jpg', mime_type: 'image/jpeg', size: 50000, created_at: '2026-04-01T11:00:00', uploaded_by: 'alice' },
        ],
        error: null,
      }),
    });

    render(<AttachmentSection eventId={1} currentUserLogin="alice" eventOwner="alice" />);

    await waitFor(() => {
      expect(screen.getByText('report.pdf')).toBeInTheDocument();
      expect(screen.getByText('photo.jpg')).toBeInTheDocument();
    });
  });

  it('shows file size formatted', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 10, filename: 'big.pdf', mime_type: 'application/pdf', size: 1048576, created_at: '2026-04-01T10:00:00', uploaded_by: 'alice' },
        ],
        error: null,
      }),
    });

    render(<AttachmentSection eventId={1} currentUserLogin="alice" eventOwner="alice" />);

    await waitFor(() => {
      expect(screen.getByText(/1.*MB/i)).toBeInTheDocument();
    });
  });

  it('shows upload button for event owner', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<AttachmentSection eventId={1} currentUserLogin="alice" eventOwner="alice" />);

    await waitFor(() => {
      expect(screen.getByText(/upload/i)).toBeInTheDocument();
    });
  });

  it('shows delete button for event owner', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 10, filename: 'file.pdf', mime_type: 'application/pdf', size: 1024, created_at: '2026-04-01', uploaded_by: 'alice' },
        ],
        error: null,
      }),
    });

    render(<AttachmentSection eventId={1} currentUserLogin="alice" eventOwner="alice" />);

    await waitFor(() => {
      expect(screen.getByTitle(/delete/i)).toBeInTheDocument();
    });
  });

  it('shows empty state when no attachments', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<AttachmentSection eventId={1} currentUserLogin="alice" eventOwner="alice" />);

    await waitFor(() => {
      expect(screen.getByText(/no attachments/i)).toBeInTheDocument();
    });
  });
});
