import { EmojiPicker } from 'frimousse';

interface EmojiPickerInnerProps {
  onSelect: (emoji: string) => void;
}

/**
 * Lazy-loaded frimousse emoji picker. Isolated from the popover shell
 * so React.lazy can tree-split the ~100kb emoji dataset out of the
 * main bundle.
 */
export function EmojiPickerInner({ onSelect }: EmojiPickerInnerProps) {
  return (
    <EmojiPicker.Root
      onEmojiSelect={({ emoji }) => onSelect(emoji)}
      className="isolate flex h-[320px] w-[280px] flex-col bg-background"
    >
      <EmojiPicker.Search
        placeholder="Search emoji…"
        className="z-10 mx-2 mt-2 h-8 appearance-none rounded-md bg-muted px-2 text-sm outline-none"
      />
      <EmojiPicker.Viewport className="relative flex-1 outline-hidden">
        <EmojiPicker.Loading className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
          Loading…
        </EmojiPicker.Loading>
        <EmojiPicker.Empty className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
          No emoji found.
        </EmojiPicker.Empty>
        <EmojiPicker.List
          className="select-none pb-2"
          components={{
            CategoryHeader: ({ category, ...props }) => (
              <div
                {...props}
                className="bg-background px-2 pb-1 pt-2 text-[11px] font-medium uppercase text-muted-foreground"
              >
                {category.label}
              </div>
            ),
            Row: ({ children, ...props }) => (
              <div {...props} className="scroll-my-1 px-1">
                {children}
              </div>
            ),
            Emoji: ({ emoji, ...props }) => (
              <button
                {...props}
                className="flex h-8 w-8 items-center justify-center rounded text-lg hover:bg-accent data-[active]:bg-accent"
              >
                {emoji.emoji}
              </button>
            ),
          }}
        />
      </EmojiPicker.Viewport>
    </EmojiPicker.Root>
  );
}
