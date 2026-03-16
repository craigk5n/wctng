import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { EventDetailDialog } from '../EventDetailDialog';

const baseEvent = {
  id: 1,
  title: 'Test Event',
  description: '',
  start_date: '20260315',
  start_time: '100000',
  end_date: '20260315',
  end_time: '110000',
  duration: 60,
  location: '',
  access: 'P',
  type: 'E',
  all_day: false,
};

describe('Permission Enforcement in UI', () => {
  it('shows Edit and Delete for own events', () => {
    render(
      <EventDetailDialog
        event={{ ...baseEvent, created_by: 'admin' }}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        currentUserLogin="admin"
      />,
    );

    expect(screen.getByRole('button', { name: /edit/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /delete/i })).toBeInTheDocument();
  });

  it('hides Edit and Delete for other users events', () => {
    render(
      <EventDetailDialog
        event={{ ...baseEvent, created_by: 'alice' }}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        currentUserLogin="admin"
      />,
    );

    expect(screen.queryByRole('button', { name: /^edit$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^delete$/i })).not.toBeInTheDocument();
  });

  it('still shows Duplicate and Export for other users events', () => {
    render(
      <EventDetailDialog
        event={{ ...baseEvent, created_by: 'alice' }}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        onDuplicate={() => {}}
        onExportIcs={() => {}}
        currentUserLogin="admin"
      />,
    );

    // Overflow menu should still be available
    expect(screen.getByRole('button', { name: /more actions/i })).toBeInTheDocument();
  });

  it('shows "Busy" title for confidential events from other users', () => {
    render(
      <EventDetailDialog
        event={{ ...baseEvent, created_by: 'alice', title: 'Busy', access: 'C', description: '' }}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        currentUserLogin="admin"
      />,
    );

    // Should show "Busy" as the title (server already masks this)
    expect(screen.getByText('Busy')).toBeInTheDocument();
  });
});
