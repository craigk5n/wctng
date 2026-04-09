import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import * as Tooltip from '@radix-ui/react-tooltip';
import { EventTooltip, type EventTooltipData } from '../EventTooltip';
import type { ApiCategory } from '../useCategories';

function renderTip(event: EventTooltipData, categories: ApiCategory[] = []) {
  return render(
    <Tooltip.Provider delayDuration={0} skipDelayDuration={0}>
      <EventTooltip event={event} categories={categories}>
        <button type="button">hover me</button>
      </EventTooltip>
    </Tooltip.Provider>,
  );
}

const baseEvent: EventTooltipData = {
  title: 'Quarterly planning',
  start: new Date('2026-04-15T09:00:00'),
  end: new Date('2026-04-15T10:00:00'),
  allDay: false,
};

describe('EventTooltip', () => {
  it('renders the trigger child', () => {
    renderTip(baseEvent);
    expect(screen.getByRole('button', { name: /hover me/ })).toBeInTheDocument();
  });

  it('shows the title when hovered', async () => {
    const user = userEvent.setup();
    renderTip(baseEvent);

    await user.hover(screen.getByRole('button'));

    // Radix renders content into a portal; the title appears once open.
    const matches = await screen.findAllByText('Quarterly planning');
    expect(matches.length).toBeGreaterThanOrEqual(1);
  });

  it('shows "All day" for all-day events', async () => {
    const user = userEvent.setup();
    renderTip({ ...baseEvent, allDay: true, end: null });

    await user.hover(screen.getByRole('button'));
    const m = await screen.findAllByText(/All day/);
    expect(m.length).toBeGreaterThanOrEqual(1);
  });

  it('shows a time range for timed events', async () => {
    const user = userEvent.setup();
    renderTip(baseEvent);

    await user.hover(screen.getByRole('button'));
    // Locale-independent: just check the en-dash separator renders.
    const content = await screen.findAllByText(/–/);
    expect(content.length).toBeGreaterThanOrEqual(1);
  });

  it('shows the location with pin prefix when provided', async () => {
    const user = userEvent.setup();
    renderTip({ ...baseEvent, location: 'Conference Room B' });

    await user.hover(screen.getByRole('button'));
    const m = await screen.findAllByText(/📍.*Conference Room B/);
    expect(m.length).toBeGreaterThanOrEqual(1);
  });

  it('omits location line when blank', async () => {
    const user = userEvent.setup();
    renderTip({ ...baseEvent, location: '   ' });

    await user.hover(screen.getByRole('button'));
    await screen.findAllByText('Quarterly planning'); // wait for open
    expect(screen.queryByText(/📍/)).not.toBeInTheDocument();
  });

  it('shows a description snippet stripped of HTML', async () => {
    const user = userEvent.setup();
    renderTip({
      ...baseEvent,
      description: '<p>Please <b>prepare</b> the Q2 deck beforehand.</p>',
    });

    await user.hover(screen.getByRole('button'));
    const m = await screen.findAllByText(/Please prepare the Q2 deck/);
    expect(m.length).toBeGreaterThanOrEqual(1);
  });

  it('shows the primary category color dot, emoji, and name', async () => {
    const user = userEvent.setup();
    const categories: ApiCategory[] = [
      { id: 1, name: 'Work', color: '#FF5500', icon: '💼', is_global: true, owner: null },
    ];
    renderTip({ ...baseEvent, categoryIds: [1] }, categories);

    await user.hover(screen.getByRole('button'));
    const work = await screen.findAllByText('Work');
    expect(work.length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('💼').length).toBeGreaterThanOrEqual(1);
  });

  it('does not render a category header for uncategorized events', async () => {
    const user = userEvent.setup();
    const categories: ApiCategory[] = [
      { id: 1, name: 'Work', color: '#FF5500', icon: '💼', is_global: true, owner: null },
    ];
    renderTip({ ...baseEvent, categoryIds: [] }, categories);

    await user.hover(screen.getByRole('button'));
    await screen.findAllByText('Quarterly planning');
    expect(screen.queryByText('Work')).not.toBeInTheDocument();
  });

  it('handles an unknown category id gracefully', async () => {
    const user = userEvent.setup();
    renderTip({ ...baseEvent, categoryIds: [999] }, []);

    await user.hover(screen.getByRole('button'));
    // Still renders the title; just no category header.
    const matches = await screen.findAllByText('Quarterly planning');
    expect(matches.length).toBeGreaterThanOrEqual(1);
  });
});
