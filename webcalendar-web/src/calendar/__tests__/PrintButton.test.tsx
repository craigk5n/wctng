import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PrintButton } from '../PrintButton';

describe('PrintButton', () => {
  it('renders a print button', () => {
    render(<PrintButton />);
    expect(screen.getByTitle(/print/i)).toBeInTheDocument();
  });

  it('calls window.print when clicked', async () => {
    const user = userEvent.setup();
    const printSpy = vi.fn();
    window.print = printSpy;

    render(<PrintButton />);
    await user.click(screen.getByTitle(/print/i));

    expect(printSpy).toHaveBeenCalledOnce();
  });
});
