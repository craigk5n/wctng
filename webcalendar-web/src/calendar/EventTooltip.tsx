import * as Tooltip from '@radix-ui/react-tooltip';
import type { ReactNode } from 'react';
import { stripHtmlToSnippet } from './eventTooltip';
import type { ApiCategory } from './useCategories';

export interface EventTooltipData {
  title: string;
  start: Date | null;
  end: Date | null;
  allDay: boolean;
  location?: string;
  description?: string;
  categoryIds?: number[];
}

interface EventTooltipProps {
  event: EventTooltipData;
  categories: ApiCategory[];
  children: ReactNode;
}

const MAX_DESCRIPTION_CHARS = 160;

function formatTime(date: Date): string {
  return date.toLocaleTimeString(undefined, {
    hour: 'numeric',
    minute: '2-digit',
  });
}

function formatTimeRange(event: EventTooltipData): string {
  if (event.allDay) return 'All day';
  if (!event.start) return '';
  const startStr = formatTime(event.start);
  if (!event.end) return startStr;
  return `${startStr} – ${formatTime(event.end)}`;
}

/**
 * Finds the first category that the event belongs to, for the header row
 * (color dot + emoji + name). Returns null for uncategorized events.
 */
function pickDisplayCategory(
  categoryIds: number[] | undefined,
  categories: ApiCategory[],
): ApiCategory | null {
  if (!categoryIds || categoryIds.length === 0) return null;
  for (const id of categoryIds) {
    const cat = categories.find((c) => c.id === id);
    if (cat) return cat;
  }
  return null;
}

/**
 * Richer styled tooltip for calendar event chips. Replaces the native
 * `title` attribute MVP with a Radix-portaled popover that carries the
 * full title, time, location, description snippet, and (when available)
 * the primary category's emoji + color dot + name.
 *
 * Accessibility:
 * - `role="tooltip"` and `aria-describedby` provided by Radix
 * - dismissable on Escape
 * - shown on keyboard focus as well as hover
 * - `prefers-reduced-motion` respected by Radix animations
 *
 * The calling code should wrap the FullCalendar tree in a single
 * `<Tooltip.Provider>` so the hover-delay state is shared.
 */
export function EventTooltip({ event, categories, children }: EventTooltipProps) {
  const displayCategory = pickDisplayCategory(event.categoryIds, categories);
  const timeLine = formatTimeRange(event);
  const descriptionSnippet = event.description
    ? stripHtmlToSnippet(event.description, MAX_DESCRIPTION_CHARS)
    : '';
  const location = event.location?.trim() ?? '';

  return (
    <Tooltip.Root>
      <Tooltip.Trigger asChild>{children}</Tooltip.Trigger>
      <Tooltip.Portal>
        <Tooltip.Content
          side="top"
          align="start"
          sideOffset={6}
          collisionPadding={8}
          className="z-50 max-w-sm rounded-md border border-border bg-popover px-3 py-2 text-popover-foreground shadow-md outline-none data-[state=delayed-open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=delayed-open]:fade-in-0"
          role="tooltip"
        >
          {displayCategory && (
            <div className="mb-1 flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
              <span
                className="inline-block h-2 w-2 rounded-full"
                style={{ backgroundColor: displayCategory.color ?? '#888' }}
                aria-hidden="true"
              />
              {displayCategory.icon && <span aria-hidden="true">{displayCategory.icon}</span>}
              <span className="font-medium">{displayCategory.name}</span>
            </div>
          )}
          <div className="text-sm font-semibold leading-snug">{event.title}</div>
          {timeLine && (
            <div className="mt-0.5 text-xs text-muted-foreground">{timeLine}</div>
          )}
          {location !== '' && (
            <div className="mt-0.5 text-xs text-muted-foreground">📍 {location}</div>
          )}
          {descriptionSnippet !== '' && (
            <div className="mt-1.5 border-t border-border pt-1.5 text-xs leading-relaxed">
              {descriptionSnippet}
            </div>
          )}
          <Tooltip.Arrow className="fill-popover" />
        </Tooltip.Content>
      </Tooltip.Portal>
    </Tooltip.Root>
  );
}
