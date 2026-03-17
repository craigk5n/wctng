import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConflictWarning, type ConflictInfo } from '../ConflictWarning';

describe('ConflictWarning', () => {
  const conflicts: ConflictInfo[] = [
    { id: 1, title: 'Team Standup', start: '2026-04-01T10:00:00', end: '2026-04-01T10:30:00' },
    { id: 2, title: 'Lunch Meeting', start: '2026-04-01T12:00:00', end: '2026-04-01T13:00:00' },
  ];

  it('renders nothing when no conflicts', () => {
    const { container } = render(
      <ConflictWarning conflicts={[]} mode="warn" onDismiss={vi.fn()} />,
    );
    expect(container.firstElementChild).toBeNull();
  });

  it('shows warning with conflict list in warn mode', () => {
    render(
      <ConflictWarning conflicts={conflicts} mode="warn" onDismiss={vi.fn()} />,
    );

    expect(screen.getByText(/conflicts/i)).toBeInTheDocument();
    expect(screen.getByText(/team standup/i)).toBeInTheDocument();
    expect(screen.getByText(/lunch meeting/i)).toBeInTheDocument();
  });

  it('shows dismiss button in warn mode', () => {
    render(
      <ConflictWarning conflicts={conflicts} mode="warn" onDismiss={vi.fn()} />,
    );

    expect(screen.getByRole('button', { name: /dismiss|save anyway/i })).toBeInTheDocument();
  });

  it('calls onDismiss when dismiss button clicked', async () => {
    const user = userEvent.setup();
    const onDismiss = vi.fn();

    render(
      <ConflictWarning conflicts={conflicts} mode="warn" onDismiss={onDismiss} />,
    );

    await user.click(screen.getByRole('button', { name: /dismiss|save anyway/i }));
    expect(onDismiss).toHaveBeenCalledOnce();
  });

  it('shows block message in block mode without dismiss button', () => {
    render(
      <ConflictWarning conflicts={conflicts} mode="block" onDismiss={vi.fn()} />,
    );

    expect(screen.getByText(/cannot be saved/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /dismiss|save anyway/i })).not.toBeInTheDocument();
  });

  it('shows conflict times', () => {
    render(
      <ConflictWarning conflicts={conflicts} mode="warn" onDismiss={vi.fn()} />,
    );

    // Should show formatted times
    expect(screen.getByText(/10:00/)).toBeInTheDocument();
  });
});
