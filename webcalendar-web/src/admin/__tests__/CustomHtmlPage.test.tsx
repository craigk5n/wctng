import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CustomHtmlPage } from '../CustomHtmlPage';

// Mock apiFetch
vi.mock('../../api/client', () => ({
  apiFetch: vi.fn().mockResolvedValue({
    data: { header_html: '<div>Test Header</div>', trailer_html: '', custom_css: '' },
    error: null,
  }),
}));

// Mock toast
vi.mock('../../components/toast/ToastProvider', () => ({
  useToast: () => ({ toast: vi.fn() }),
}));

describe('CustomHtmlPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the page title', async () => {
    render(<CustomHtmlPage />);
    await waitFor(() => {
      expect(screen.getByText(/Custom Header, Trailer/)).toBeInTheDocument();
    });
  });

  it('renders three textareas', async () => {
    render(<CustomHtmlPage />);
    await waitFor(() => {
      expect(screen.getByLabelText(/Header HTML/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/Trailer.*Footer HTML/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/Custom CSS/i)).toBeInTheDocument();
    });
  });

  it('loads saved values from API', async () => {
    render(<CustomHtmlPage />);
    await waitFor(() => {
      const textarea = screen.getByLabelText(/Header HTML/i) as HTMLTextAreaElement;
      expect(textarea.value).toContain('Test Header');
    });
  });

  it('has save button', async () => {
    render(<CustomHtmlPage />);
    await waitFor(() => {
      expect(screen.getByText(/Save Changes/i)).toBeInTheDocument();
    });
  });

  it('toggles preview panel', async () => {
    render(<CustomHtmlPage />);
    await waitFor(() => {
      expect(screen.getByText(/Show Preview/i)).toBeInTheDocument();
    });

    await userEvent.click(screen.getByText(/Show Preview/i));
    expect(screen.getByTestId('custom-html-preview')).toBeInTheDocument();
    expect(screen.getByText(/Hide Preview/i)).toBeInTheDocument();
  });
});
