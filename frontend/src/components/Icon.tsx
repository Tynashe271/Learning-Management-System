/** A small set of plain, monochrome line icons (inherits the surrounding text color), used in place of emoji throughout the app. */
export type IconName = 'menu' | 'bell' | 'message' | 'trend-down' | 'video' | 'help' | 'document' | 'warning' | 'lock' | 'link' | 'paperclip' | 'hand' | 'clock' | 'megaphone'

const PATHS: Record<IconName, React.ReactNode> = {
  menu: (
    <>
      <line x1="3" y1="6" x2="21" y2="6" />
      <line x1="3" y1="12" x2="21" y2="12" />
      <line x1="3" y1="18" x2="21" y2="18" />
    </>
  ),
  bell: <path d="M6 9a6 6 0 0 1 12 0c0 4 1.5 5.5 2 6.5H4c.5-1 2-2.5 2-6.5Zm2.3 9.5a2.5 2.5 0 0 0 4.9 0" />,
  message: <path d="M4 5h16v11H8l-4 4V5Z" />,
  'trend-down': (
    <>
      <polyline points="4,7 10,13 14,9 20,16" />
      <polyline points="15,16 20,16 20,11" />
    </>
  ),
  video: (
    <>
      <rect x="3" y="6" width="13" height="12" rx="1.5" />
      <path d="M21 8.2 16 11v2l5 2.8V8.2Z" />
    </>
  ),
  help: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M9.3 9.3a2.7 2.7 0 1 1 3.9 2.4c-.9.5-1.2 1-1.2 2" />
      <line x1="12" y1="17" x2="12" y2="17.01" />
    </>
  ),
  document: (
    <>
      <path d="M7 3h7l4 4v14H7V3Z" />
      <line x1="10" y1="12" x2="17" y2="12" />
      <line x1="10" y1="16" x2="17" y2="16" />
    </>
  ),
  warning: (
    <>
      <path d="M12 3.5 22 20H2L12 3.5Z" />
      <line x1="12" y1="10" x2="12" y2="14.5" />
      <line x1="12" y1="17" x2="12" y2="17.01" />
    </>
  ),
  lock: (
    <>
      <rect x="5" y="11" width="14" height="10" rx="1.5" />
      <path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </>
  ),
  link: <path d="M9 15 15 9M10 6l1.2-1.2a3.8 3.8 0 0 1 5.4 5.4L15.4 11.4M14 18l-1.2 1.2a3.8 3.8 0 0 1-5.4-5.4L8.6 12.6" />,
  paperclip: <path d="M17.5 7 9 15.5a3 3 0 0 0 4.2 4.2l8-8a5 5 0 0 0-7-7l-8 8a7 7 0 0 0 9.9 9.9" />,
  hand: <path d="M8 13V5.5a1.5 1.5 0 0 1 3 0V12m3 0V4.5a1.5 1.5 0 0 1 3 0V12m3 0V7a1.5 1.5 0 0 1 3 0v8a6 6 0 0 1-6 6h-2a6 6 0 0 1-5-2.7L5.4 15a1.6 1.6 0 0 1 2.4-2.1L9 14" />,
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <polyline points="12,7 12,12 15.5,14" />
    </>
  ),
  megaphone: <path d="M3 10v4h3l9 5V5L6 10H3Zm13 0a3 3 0 0 1 0 4" />,
}

export function Icon({ name, size = 16, className }: { name: IconName; size?: number; className?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true" focusable="false">
      {PATHS[name]}
    </svg>
  )
}
