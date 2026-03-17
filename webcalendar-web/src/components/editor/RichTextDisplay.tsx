interface RichTextDisplayProps {
  html: string;
  className?: string;
}

/**
 * Renders sanitized HTML description content with prose typography.
 * HTML is sanitized server-side by DescriptionSanitizer before storage.
 */
export function RichTextDisplay({ html, className = '' }: RichTextDisplayProps) {
  if (!html) return null;

  // If content has no HTML tags, render as plain text
  if (html === stripTags(html)) {
    return <p className={`text-sm text-muted-foreground ${className}`}>{html}</p>;
  }

  return (
    <div
      className={`prose prose-sm max-w-none text-muted-foreground ${className}`}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}

function stripTags(str: string): string {
  return str.replace(/<[^>]*>/g, '');
}
