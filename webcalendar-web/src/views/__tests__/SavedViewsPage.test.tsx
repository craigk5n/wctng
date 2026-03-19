import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '../../auth/AuthProvider';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { SavedViewsPage } from '../SavedViewsPage';

const viewsData = [
  { id: 1, name: 'Engineering', user_logins: ['alice', 'bob'], is_global: false, owner: 'admin' },
  { id: 2, name: 'Marketing', user_logins: ['carol'], is_global: true, owner: 'admin' },
];

const usersData = [
  { login: 'alice', first_name: 'Alice', last_name: 'Smith' },
  { login: 'bob', first_name: 'Bob', last_name: 'Jones' },
  { login: 'carol', first_name: 'Carol', last_name: 'Lee' },
];

function renderPage() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <ToastProvider>
          <SavedViewsPage />
        </ToastProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('SavedViewsPage', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  function mockFetch() {
    globalThis.fetch = vi.fn().mockImplementation((url: string) => {
      if (typeof url === 'string' && url.includes('/views')) {
        return Promise.resolve(new Response(JSON.stringify({ data: viewsData }), {
          status: 200, headers: { 'Content-Type': 'application/json' },
        }));
      }
      if (typeof url === 'string' && url.includes('/users')) {
        return Promise.resolve(new Response(JSON.stringify({ data: usersData }), {
          status: 200, headers: { 'Content-Type': 'application/json' },
        }));
      }
      return Promise.resolve(new Response(JSON.stringify({ data: null }), {
        status: 200, headers: { 'Content-Type': 'application/json' },
      }));
    });
  }

  it('renders page title', async () => {
    mockFetch();
    renderPage();
    await waitFor(() => {
      expect(screen.getByText('Saved Views')).toBeInTheDocument();
    });
  });

  it('lists existing views', async () => {
    mockFetch();
    renderPage();
    await waitFor(() => {
      expect(screen.getByText('Engineering')).toBeInTheDocument();
      expect(screen.getByText('Marketing')).toBeInTheDocument();
    });
  });

  it('shows global badge on global views', async () => {
    mockFetch();
    renderPage();
    await waitFor(() => {
      expect(screen.getByText('Global')).toBeInTheDocument();
    });
  });

  it('shows create form', async () => {
    mockFetch();
    renderPage();
    await waitFor(() => {
      expect(screen.getByLabelText(/View Name/)).toBeInTheDocument();
      expect(screen.getByText('Create View')).toBeInTheDocument();
    });
  });

  it('has activate and delete buttons', async () => {
    mockFetch();
    renderPage();
    await waitFor(() => {
      expect(screen.getAllByText('Activate')).toHaveLength(2);
      expect(screen.getAllByText('Delete')).toHaveLength(2);
    });
  });
});
