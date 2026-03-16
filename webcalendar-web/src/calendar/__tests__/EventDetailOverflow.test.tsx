import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EventDetailDialog } from '../EventDetailDialog';

const mockEvent = {
  id: 1,
  title: 'Test Event',
  description: 'A description',
  start_date: '20260315',
  start_time: '100000',
  end_date: '20260315',
  end_time: '110000',
  duration: 60,
  location: 'Office',
  access: 'P',
  type: 'E',
  created_by: 'admin',
  all_day: false,
};

describe('EventDetailDialog overflow menu', () => {
  it('renders the overflow menu button', () => {
    render(
      <EventDetailDialog
        event={mockEvent}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        onDuplicate={() => {}}
        onExportIcs={() => {}}
      />,
    );

    expect(screen.getByRole('button', { name: /more actions/i })).toBeInTheDocument();
  });

  it('shows Duplicate and Export options when menu is opened', async () => {
    const user = userEvent.setup();

    render(
      <EventDetailDialog
        event={mockEvent}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        onDuplicate={() => {}}
        onExportIcs={() => {}}
      />,
    );

    await user.click(screen.getByRole('button', { name: /more actions/i }));

    expect(screen.getByText(/duplicate/i)).toBeInTheDocument();
    expect(screen.getByText(/export as ics/i)).toBeInTheDocument();
  });

  it('calls onDuplicate when Duplicate is clicked', async () => {
    const user = userEvent.setup();
    const onDuplicate = vi.fn();

    render(
      <EventDetailDialog
        event={mockEvent}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        onDuplicate={onDuplicate}
        onExportIcs={() => {}}
      />,
    );

    await user.click(screen.getByRole('button', { name: /more actions/i }));
    await user.click(screen.getByText(/duplicate/i));

    expect(onDuplicate).toHaveBeenCalledOnce();
  });

  it('calls onExportIcs when Export is clicked', async () => {
    const user = userEvent.setup();
    const onExportIcs = vi.fn();

    render(
      <EventDetailDialog
        event={mockEvent}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        onDuplicate={() => {}}
        onExportIcs={onExportIcs}
      />,
    );

    await user.click(screen.getByRole('button', { name: /more actions/i }));
    await user.click(screen.getByText(/export as ics/i));

    expect(onExportIcs).toHaveBeenCalledOnce();
  });

  it('does not show menu items when callbacks are not provided', () => {
    render(
      <EventDetailDialog
        event={mockEvent}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />,
    );

    // Menu button should still be there but menu items should not render when opened
    expect(screen.getByRole('button', { name: /more actions/i })).toBeInTheDocument();
  });
});
