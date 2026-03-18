import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CustomFieldsSection } from '../CustomFieldsSection';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('CustomFieldsSection', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders nothing when no custom fields defined', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    const { container } = render(
      <CustomFieldsSection values={{}} onChange={vi.fn()} />,
    );

    await waitFor(() => expect(mockFetch).toHaveBeenCalled());
    expect(container.firstChild).toBeNull();
  });

  it('renders text field', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [{ id: 1, name: 'Department', field_type: 'text', required: false, options: [] }],
        error: null,
      }),
    });

    render(<CustomFieldsSection values={{}} onChange={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getByText('Department')).toBeInTheDocument();
    });
  });

  it('renders select field with options', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [{ id: 2, name: 'Priority', field_type: 'select', required: true, options: ['Low', 'Medium', 'High'] }],
        error: null,
      }),
    });

    render(<CustomFieldsSection values={{}} onChange={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getByText('Priority')).toBeInTheDocument();
      expect(screen.getByText('Low')).toBeInTheDocument();
      expect(screen.getByText('High')).toBeInTheDocument();
    });
  });

  it('calls onChange when value changes', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [{ id: 1, name: 'Notes', field_type: 'text', required: false, options: [] }],
        error: null,
      }),
    });

    render(<CustomFieldsSection values={{}} onChange={onChange} />);

    await waitFor(() => expect(screen.getByText('Notes')).toBeInTheDocument());

    const input = screen.getByRole('textbox');
    await user.type(input, 'Test note');

    expect(onChange).toHaveBeenCalled();
  });
});
