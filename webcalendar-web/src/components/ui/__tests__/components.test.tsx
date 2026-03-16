import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Button } from '../button';
import { Input } from '../input';
import { Label } from '../label';
import { Badge } from '../badge';
import { Textarea } from '../textarea';
import { Card, CardHeader, CardTitle, CardContent } from '../card';
import { Separator } from '../separator';

describe('Shadcn UI Components', () => {
  it('Button renders with default variant', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByRole('button')).toHaveTextContent('Click me');
  });

  it('Button renders with destructive variant', () => {
    render(<Button variant="destructive">Delete</Button>);
    const btn = screen.getByRole('button');
    expect(btn).toHaveTextContent('Delete');
    expect(btn.className).toContain('destructive');
  });

  it('Button renders with different sizes', () => {
    render(<Button size="sm">Small</Button>);
    expect(screen.getByRole('button')).toHaveTextContent('Small');
  });

  it('Button can be disabled', () => {
    render(<Button disabled>Disabled</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('Input renders and accepts value', () => {
    render(<Input placeholder="Enter text" />);
    expect(screen.getByPlaceholderText('Enter text')).toBeInTheDocument();
  });

  it('Input supports different types', () => {
    render(<Input type="password" placeholder="Password" />);
    expect(screen.getByPlaceholderText('Password')).toHaveAttribute('type', 'password');
  });

  it('Label renders text', () => {
    render(<Label>Username</Label>);
    expect(screen.getByText('Username')).toBeInTheDocument();
  });

  it('Badge renders with default variant', () => {
    render(<Badge>New</Badge>);
    expect(screen.getByText('New')).toBeInTheDocument();
  });

  it('Badge renders with outline variant', () => {
    render(<Badge variant="outline">Status</Badge>);
    expect(screen.getByText('Status')).toBeInTheDocument();
  });

  it('Textarea renders', () => {
    render(<Textarea placeholder="Write here" />);
    expect(screen.getByPlaceholderText('Write here')).toBeInTheDocument();
  });

  it('Card renders with header and content', () => {
    render(
      <Card>
        <CardHeader>
          <CardTitle>Title</CardTitle>
        </CardHeader>
        <CardContent>Body content</CardContent>
      </Card>,
    );
    expect(screen.getByText('Title')).toBeInTheDocument();
    expect(screen.getByText('Body content')).toBeInTheDocument();
  });

  it('Separator renders', () => {
    const { container } = render(<Separator />);
    expect(container.firstChild).toBeTruthy();
  });
});
