import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { SearchBar } from '../SearchBar';

function renderSearchBar() {
  return render(
    <MemoryRouter>
      <SearchBar />
    </MemoryRouter>,
  );
}

describe('SearchBar', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders search input', () => {
    renderSearchBar();
    expect(screen.getByPlaceholderText(/search/i)).toBeInTheDocument();
  });

  it('shows results dropdown after typing', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            { id: 1, title: 'Team Meeting', start_date: '20260315', type: 'E', all_day: false },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    renderSearchBar();
    await user.type(screen.getByPlaceholderText(/search/i), 'meeting');

    await waitFor(() => {
      expect(screen.getByText('Team Meeting')).toBeInTheDocument();
    }, { timeout: 2000 });
  });

  it('shows empty state when no results', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({ data: [] }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    renderSearchBar();
    await user.type(screen.getByPlaceholderText(/search/i), 'zzzznothing');

    await waitFor(() => {
      expect(screen.getByText(/no results/i)).toBeInTheDocument();
    }, { timeout: 2000 });
  });

  it('closes dropdown on Escape', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [{ id: 1, title: 'Result', start_date: '20260315', type: 'E', all_day: false }],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    renderSearchBar();
    const input = screen.getByPlaceholderText(/search/i);
    await user.type(input, 'test');

    await waitFor(() => {
      expect(screen.getByText('Result')).toBeInTheDocument();
    }, { timeout: 2000 });

    await user.keyboard('{Escape}');
    expect(screen.queryByText('Result')).not.toBeInTheDocument();
  });

  it('does not search with empty input', async () => {
    const mockFetch = vi.fn();
    globalThis.fetch = mockFetch;

    renderSearchBar();
    const input = screen.getByPlaceholderText(/search/i);
    await userEvent.clear(input);

    // fetch should not be called for empty search
    expect(mockFetch).not.toHaveBeenCalled();
  });
});
