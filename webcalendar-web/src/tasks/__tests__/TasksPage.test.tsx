import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { AuthProvider } from '../../auth/AuthProvider';
import { TasksPage } from '../TasksPage';

function renderTasksPage() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <ToastProvider>
          <TasksPage />
        </ToastProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('TasksPage', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders page title', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    renderTasksPage();
    expect(screen.getByRole('heading', { name: /tasks/i })).toBeInTheDocument();
  });

  it('shows create task button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    renderTasksPage();
    expect(screen.getByRole('button', { name: /new task|add task|create/i })).toBeInTheDocument();
  });

  it('shows filter buttons', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    renderTasksPage();
    expect(screen.getByText(/all/i)).toBeInTheDocument();
    expect(screen.getByText(/pending/i)).toBeInTheDocument();
    expect(screen.getByText(/completed/i)).toBeInTheDocument();
  });

  it('displays tasks in a list', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            { id: 1, title: 'Write docs', due_date: '20260401', percent_complete: 0, status: 'pending', priority: 5 },
            { id: 2, title: 'Review PR', due_date: '20260402', percent_complete: 100, status: 'completed', priority: 3 },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    renderTasksPage();

    await waitFor(() => {
      expect(screen.getByText('Write docs')).toBeInTheDocument();
      expect(screen.getByText('Review PR')).toBeInTheDocument();
    });
  });

  it('shows empty state when no tasks', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    renderTasksPage();

    await waitFor(() => {
      expect(screen.getByText(/no tasks/i)).toBeInTheDocument();
    });
  });
});
