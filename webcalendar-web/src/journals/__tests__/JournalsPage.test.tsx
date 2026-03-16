import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { AuthProvider } from '../../auth/AuthProvider';
import { JournalsPage } from '../JournalsPage';

function renderPage() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <ToastProvider>
          <JournalsPage />
        </ToastProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('JournalsPage', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders page title', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    renderPage();
    expect(screen.getByRole('heading', { name: /journals/i })).toBeInTheDocument();
  });

  it('shows create button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    renderPage();
    expect(screen.getByRole('button', { name: /new journal|new entry|create/i })).toBeInTheDocument();
  });

  it('displays journal entries', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            { id: 1, title: 'Morning notes', text: 'Good day', date: '20260315' },
            { id: 2, title: 'Evening thoughts', text: 'Reflections', date: '20260314' },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Morning notes')).toBeInTheDocument();
      expect(screen.getByText('Evening thoughts')).toBeInTheDocument();
    });
  });

  it('shows empty state', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    renderPage();

    await waitFor(() => {
      expect(screen.getByText(/no journal/i)).toBeInTheDocument();
    });
  });
});
