import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConfirmDeleteDialog } from '../ConfirmDeleteDialog';

describe('ConfirmDeleteDialog', () => {
  const defaultProps = {
    open: true,
    eventTitle: 'Team Meeting',
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    isDeleting: false,
  };

  it('shows confirmation with event title', () => {
    render(<ConfirmDeleteDialog {...defaultProps} />);

    expect(screen.getByRole('heading', { name: /cancel event/i })).toBeInTheDocument();
    expect(screen.getByText(/team meeting/i)).toBeInTheDocument();
  });

  it('has confirm and keep buttons', () => {
    render(<ConfirmDeleteDialog {...defaultProps} />);

    expect(screen.getByRole('button', { name: /cancel event/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /keep/i })).toBeInTheDocument();
  });

  it('calls onConfirm when confirm button clicked', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(<ConfirmDeleteDialog {...defaultProps} onConfirm={onConfirm} />);

    await user.click(screen.getByRole('button', { name: /cancel event/i }));
    expect(onConfirm).toHaveBeenCalled();
  });

  it('calls onCancel when keep button clicked', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();
    render(<ConfirmDeleteDialog {...defaultProps} onCancel={onCancel} />);

    await user.click(screen.getByRole('button', { name: /keep/i }));
    expect(onCancel).toHaveBeenCalled();
  });

  it('does not render when open is false', () => {
    render(<ConfirmDeleteDialog {...defaultProps} open={false} />);
    expect(screen.queryByText(/team meeting/i)).not.toBeInTheDocument();
  });

  it('disables confirm button while processing', () => {
    render(<ConfirmDeleteDialog {...defaultProps} isDeleting={true} />);

    const confirmBtn = screen.getByRole('button', { name: /canceling/i });
    expect(confirmBtn).toBeDisabled();
  });

  it('shows canceling state text for organizer', () => {
    render(<ConfirmDeleteDialog {...defaultProps} isOrganizer={true} isDeleting={true} />);
    expect(screen.getByText(/canceling/i)).toBeInTheDocument();
  });

  it('shows decline wording for non-organizer', () => {
    render(<ConfirmDeleteDialog {...defaultProps} isOrganizer={false} />);
    expect(screen.getByRole('heading', { name: /decline event/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /decline/i })).toBeInTheDocument();
  });

  it('shows declining state text for participant', () => {
    render(<ConfirmDeleteDialog {...defaultProps} isOrganizer={false} isDeleting={true} />);
    expect(screen.getByText(/declining/i)).toBeInTheDocument();
  });
});
