import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RecurrenceEditor } from '../RecurrenceEditor';

describe('RecurrenceEditor', () => {
  it('renders with None selected by default', () => {
    render(<RecurrenceEditor value="" onChange={vi.fn()} />);
    const select = screen.getByRole('combobox') as HTMLSelectElement;
    expect(select.value).toBe('none');
  });

  it('shows preset options', () => {
    render(<RecurrenceEditor value="" onChange={vi.fn()} />);
    const select = screen.getByRole('combobox');
    expect(select).toBeInTheDocument();
  });

  it('calls onChange with RRULE for daily preset', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<RecurrenceEditor value="" onChange={onChange} />);

    await user.selectOptions(screen.getByRole('combobox'), 'daily');
    expect(onChange).toHaveBeenCalledWith('FREQ=DAILY');
  });

  it('calls onChange with RRULE for weekly preset', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<RecurrenceEditor value="" onChange={onChange} />);

    await user.selectOptions(screen.getByRole('combobox'), 'weekly');
    expect(onChange).toHaveBeenCalledWith('FREQ=WEEKLY');
  });

  it('calls onChange with RRULE for monthly preset', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<RecurrenceEditor value="" onChange={onChange} />);

    await user.selectOptions(screen.getByRole('combobox'), 'monthly');
    expect(onChange).toHaveBeenCalledWith('FREQ=MONTHLY');
  });

  it('shows custom options when custom selected', async () => {
    const user = userEvent.setup();
    render(<RecurrenceEditor value="" onChange={vi.fn()} />);

    await user.selectOptions(screen.getByRole('combobox'), 'custom');

    expect(screen.getByLabelText(/interval/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/end/i)).toBeInTheDocument();
  });

  it('parses existing RRULE and pre-selects', () => {
    render(<RecurrenceEditor value="FREQ=WEEKLY" onChange={vi.fn()} />);
    const select = screen.getByRole('combobox') as HTMLSelectElement;
    expect(select.value).toBe('weekly');
  });

  it('clears RRULE when None selected', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<RecurrenceEditor value="FREQ=DAILY" onChange={onChange} />);

    await user.selectOptions(screen.getByRole('combobox'), 'none');
    expect(onChange).toHaveBeenCalledWith('');
  });
});
