import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EventDialog } from '../EventDialog';

describe('EventDialog - Create', () => {
  const defaultProps = {
    open: true,
    onClose: vi.fn(),
    onSave: vi.fn(),
    initialDate: '2026-03-15',
    initialTime: '10:00',
    initialAllDay: false,
  };

  it('renders all form fields', () => {
    render(<EventDialog {...defaultProps} />);

    expect(screen.getByLabelText(/title/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/date/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/start time/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/duration/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/location/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/description/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/all.day/i)).toBeInTheDocument();
  });

  it('pre-fills date and time from props', () => {
    render(<EventDialog {...defaultProps} />);

    expect(screen.getByLabelText(/date/i)).toHaveValue('2026-03-15');
    expect(screen.getByLabelText(/start time/i)).toHaveValue('10:00');
  });

  it('pre-fills as all-day when initialAllDay is true', () => {
    render(<EventDialog {...defaultProps} initialAllDay={true} initialTime="" />);

    const allDayCheckbox = screen.getByLabelText(/all.day/i);
    expect(allDayCheckbox).toBeChecked();
    expect(screen.queryByLabelText(/start time/i)).not.toBeInTheDocument();
  });

  it('validates title is required', async () => {
    const user = userEvent.setup();
    const onSave = vi.fn();
    render(<EventDialog {...defaultProps} onSave={onSave} />);

    // Clear title and submit
    await user.click(screen.getByRole('button', { name: /save|create/i }));

    // onSave should NOT have been called (title is empty)
    expect(onSave).not.toHaveBeenCalled();
  });

  it('calls onSave with event data on submit', async () => {
    const user = userEvent.setup();
    const onSave = vi.fn().mockResolvedValue(true);
    render(<EventDialog {...defaultProps} onSave={onSave} />);

    await user.type(screen.getByLabelText(/title/i), 'New Meeting');
    await user.click(screen.getByRole('button', { name: /save|create/i }));

    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'New Meeting',
        start_date: '20260315',
      }),
    );
  });

  it('cancel closes without calling onSave', async () => {
    const user = userEvent.setup();
    const onSave = vi.fn();
    const onClose = vi.fn();
    render(<EventDialog {...defaultProps} onSave={onSave} onClose={onClose} />);

    await user.click(screen.getByRole('button', { name: /cancel/i }));

    expect(onSave).not.toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it('toggling all-day hides time fields', async () => {
    const user = userEvent.setup();
    render(<EventDialog {...defaultProps} />);

    expect(screen.getByLabelText(/start time/i)).toBeInTheDocument();

    await user.click(screen.getByLabelText(/all.day/i));

    expect(screen.queryByLabelText(/start time/i)).not.toBeInTheDocument();
  });

  it('does not render when open is false', () => {
    render(<EventDialog {...defaultProps} open={false} />);
    expect(screen.queryByLabelText(/title/i)).not.toBeInTheDocument();
  });

  it('shows dialog title for create mode', () => {
    render(<EventDialog {...defaultProps} />);
    expect(screen.getByRole('heading', { name: /new event/i })).toBeInTheDocument();
  });

  it('shows dialog title for edit mode', () => {
    render(<EventDialog {...defaultProps} mode="edit" />);
    expect(screen.getByText(/edit event/i)).toBeInTheDocument();
  });
});
