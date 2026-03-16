import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EventDetailDialog } from '../EventDetailDialog';
import type { ApiEvent } from '../eventMapper';

const timedEvent: ApiEvent = {
  id: 1,
  title: 'Team Meeting',
  description: 'Weekly sync with the team',
  start_date: '20260315',
  start_time: '100000',
  end_date: '20260315',
  end_time: '110000',
  duration: 60,
  location: 'Room A',
  access: 'P',
  type: 'E',
  created_by: 'admin',
  all_day: false,
};

const allDayEvent: ApiEvent = {
  id: 2,
  title: 'Company Holiday',
  description: '',
  start_date: '20260401',
  start_time: null,
  end_date: '20260401',
  end_time: null,
  duration: 0,
  location: '',
  access: 'P',
  type: 'E',
  created_by: 'admin',
  all_day: true,
};

describe('EventDetailDialog', () => {
  it('renders event title and details', () => {
    render(
      <EventDetailDialog event={timedEvent} open={true} onClose={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    );

    expect(screen.getByText('Team Meeting')).toBeInTheDocument();
    expect(screen.getByText(/weekly sync/i)).toBeInTheDocument();
    expect(screen.getByText(/room a/i)).toBeInTheDocument();
  });

  it('shows formatted date and time', () => {
    render(
      <EventDetailDialog event={timedEvent} open={true} onClose={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    );

    // Should show date in readable format
    expect(screen.getByText(/2026-03-15/)).toBeInTheDocument();
    expect(screen.getByText(/10:00/)).toBeInTheDocument();
  });

  it('shows "All day" for all-day events', () => {
    render(
      <EventDetailDialog event={allDayEvent} open={true} onClose={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    );

    expect(screen.getByText(/all day/i)).toBeInTheDocument();
  });

  it('shows Edit and Delete buttons', () => {
    render(
      <EventDetailDialog event={timedEvent} open={true} onClose={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    );

    expect(screen.getByRole('button', { name: /edit/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /delete/i })).toBeInTheDocument();
  });

  it('calls onEdit when Edit button clicked', async () => {
    const onEdit = vi.fn();
    render(
      <EventDetailDialog event={timedEvent} open={true} onClose={() => {}} onEdit={onEdit} onDelete={() => {}} />,
    );

    await userEvent.click(screen.getByRole('button', { name: /edit/i }));
    expect(onEdit).toHaveBeenCalled();
  });

  it('calls onDelete when Delete button clicked', async () => {
    const onDelete = vi.fn();
    render(
      <EventDetailDialog event={timedEvent} open={true} onClose={() => {}} onEdit={() => {}} onDelete={onDelete} />,
    );

    await userEvent.click(screen.getByRole('button', { name: /delete/i }));
    expect(onDelete).toHaveBeenCalled();
  });

  it('calls onClose when Close button clicked', async () => {
    const onClose = vi.fn();
    render(
      <EventDetailDialog event={timedEvent} open={true} onClose={onClose} onEdit={() => {}} onDelete={() => {}} />,
    );

    await userEvent.click(screen.getByRole('button', { name: /close/i }));
    expect(onClose).toHaveBeenCalled();
  });

  it('does not render when open is false', () => {
    render(
      <EventDetailDialog event={timedEvent} open={false} onClose={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    );

    expect(screen.queryByText('Team Meeting')).not.toBeInTheDocument();
  });

  it('shows access level', () => {
    render(
      <EventDetailDialog event={timedEvent} open={true} onClose={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    );

    expect(screen.getByText(/public/i)).toBeInTheDocument();
  });
});
