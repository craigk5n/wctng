import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CategoryFilter } from '../CategoryFilter';

const mockCategories = [
  { id: 1, name: 'Work', color: '#3788d8', is_global: true, owner: null },
  { id: 2, name: 'Personal', color: '#43a047', is_global: false, owner: 'admin' },
  { id: 3, name: 'Holidays', color: '#e53935', is_global: true, owner: null },
];

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('CategoryFilter', () => {
  beforeEach(() => {
    localStorage.clear();
    mockFetch.mockReset();
    mockFetch.mockResolvedValue({
      ok: true, status: 200,
      json: async () => ({ data: mockCategories }),
    });
  });

  it('renders categories with checkboxes', async () => {
    const onChange = vi.fn();
    render(<CategoryFilter onChange={onChange} />);

    await waitFor(() => {
      expect(screen.getByLabelText('Work')).toBeInTheDocument();
      expect(screen.getByLabelText('Personal')).toBeInTheDocument();
      expect(screen.getByLabelText('Holidays')).toBeInTheDocument();
    });

    // All should be checked by default
    expect(screen.getByLabelText('Work')).toBeChecked();
    expect(screen.getByLabelText('Personal')).toBeChecked();
  });

  it('toggling a category calls onChange with updated IDs', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<CategoryFilter onChange={onChange} />);

    await waitFor(() => expect(screen.getByLabelText('Work')).toBeChecked());

    // Uncheck Work
    await user.click(screen.getByLabelText('Work'));

    // onChange should be called with remaining active IDs (Personal + Holidays)
    expect(onChange).toHaveBeenCalled();
    const lastCall = onChange.mock.calls[onChange.mock.calls.length - 1][0];
    expect(lastCall).not.toContain(1); // Work excluded
    expect(lastCall).toContain(2); // Personal included
    expect(lastCall).toContain(3); // Holidays included
  });

  it('shows All/None toggle buttons', async () => {
    const onChange = vi.fn();
    render(<CategoryFilter onChange={onChange} />);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /all/i })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /none/i })).toBeInTheDocument();
    });
  });

  it('None button unchecks all categories', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<CategoryFilter onChange={onChange} />);

    await waitFor(() => expect(screen.getByLabelText('Work')).toBeChecked());

    await user.click(screen.getByRole('button', { name: /none/i }));

    expect(screen.getByLabelText('Work')).not.toBeChecked();
    expect(screen.getByLabelText('Personal')).not.toBeChecked();
  });

  it('persists filter to localStorage', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<CategoryFilter onChange={onChange} />);

    await waitFor(() => expect(screen.getByLabelText('Work')).toBeChecked());

    await user.click(screen.getByLabelText('Holidays'));

    const stored = localStorage.getItem('wctng_category_filter');
    expect(stored).toBeTruthy();
    const parsed = JSON.parse(stored!);
    expect(parsed).not.toContain(3); // Holidays unchecked
  });

  it('shows color dots for each category', async () => {
    const onChange = vi.fn();
    render(<CategoryFilter onChange={onChange} />);

    await waitFor(() => expect(screen.getByLabelText('Work')).toBeInTheDocument());

    // Color dots should be present (using data-testid)
    const dots = document.querySelectorAll('[data-testid="category-color-dot"]');
    expect(dots.length).toBe(3);
  });
});
