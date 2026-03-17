export function PrintButton() {
  return (
    <button
      type="button"
      title="Print calendar"
      onClick={() => window.print()}
      className="inline-flex h-9 items-center gap-1.5 rounded-md border border-input px-3 text-sm hover:bg-accent print:hidden"
    >
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="6 9 6 2 18 2 18 9" />
        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
        <rect x="6" y="14" width="12" height="8" />
      </svg>
      Print
    </button>
  );
}
