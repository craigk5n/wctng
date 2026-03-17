import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RichTextDisplay } from '../../components/editor/RichTextDisplay';

describe('RichTextDisplay', () => {
  it('renders plain text as-is', () => {
    render(<RichTextDisplay html="Hello world" />);
    expect(screen.getByText('Hello world')).toBeInTheDocument();
  });

  it('renders HTML content with formatting', () => {
    const { container } = render(
      <RichTextDisplay html="<p>Hello <strong>bold</strong> world</p>" />,
    );
    const strong = container.querySelector('strong');
    expect(strong).toBeInTheDocument();
    expect(strong?.textContent).toBe('bold');
  });

  it('applies prose class for typography', () => {
    const { container } = render(
      <RichTextDisplay html="<p>Styled</p>" />,
    );
    const wrapper = container.firstElementChild;
    expect(wrapper?.className).toContain('prose');
  });

  it('renders nothing when html is empty', () => {
    const { container } = render(<RichTextDisplay html="" />);
    expect(container.firstElementChild).toBeNull();
  });

  it('renders links', () => {
    const { container } = render(
      <RichTextDisplay html='<a href="https://example.com">Link</a>' />,
    );
    const link = container.querySelector('a');
    expect(link).toBeInTheDocument();
    expect(link?.getAttribute('href')).toBe('https://example.com');
  });
});
