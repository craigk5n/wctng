import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CustomFieldsPage } from '../CustomFieldsPage';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('CustomFieldsPage', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<CustomFieldsPage />);
    expect(screen.getByRole('heading', { name: /custom fields/i })).toBeInTheDocument();
  });

  it('lists existing fields', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [
          { id: 1, name: 'Department', field_type: 'text', required: false, sort_order: 0, options: [] },
          { id: 2, name: 'Priority', field_type: 'select', required: true, sort_order: 1, options: ['Low', 'Medium', 'High'] },
        ],
        error: null,
      }),
    });

    render(<CustomFieldsPage />);

    await waitFor(() => {
      expect(screen.getByText('Department')).toBeInTheDocument();
      expect(screen.getByText('Priority')).toBeInTheDocument();
      expect(screen.getByText('text')).toBeInTheDocument();
      expect(screen.getByText('select')).toBeInTheDocument();
    });
  });

  it('shows empty state', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<CustomFieldsPage />);

    await waitFor(() => {
      expect(screen.getByText(/no custom fields/i)).toBeInTheDocument();
    });
  });

  it('shows create form with field type selector', async () => {
    const user = userEvent.setup();

    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<CustomFieldsPage />);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add field/i })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: /add field/i }));

    expect(screen.getByLabelText(/field name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/field type/i)).toBeInTheDocument();
  });
});
