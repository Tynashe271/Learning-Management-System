import { type UseQueryResult } from '@tanstack/react-query'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'
import { NavLink } from 'react-router-dom'
import { ApiError, errorMessage } from '../api/client'
import type { Meta, Page } from '../api/types'

// ---- buttons and badges ---------------------------------------------------------------------------------------------
type Variant = 'primary' | 'secondary' | 'danger' | 'ghost'

export function Button({
  variant = 'secondary',
  loading = false,
  small = false,
  children,
  disabled,
  type = 'button',
  className = '',
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; loading?: boolean; small?: boolean }) {
  return (
    <button {...rest} type={type} disabled={disabled || loading} aria-busy={loading || undefined} className={`btn btn-${variant}${small ? ' btn-small' : ''} ${className}`}>
      {loading && <span className="spinner spinner-inline" aria-hidden="true" />}
      {children}
    </button>
  )
}

export function Badge({ tone = 'neutral', children }: { tone?: 'neutral' | 'good' | 'warn' | 'bad' | 'info'; children: ReactNode }) {
  return <span className={`badge badge-${tone}`}>{children}</span>
}

export function PublishedBadge({ published }: { published: boolean }) {
  return published ? <Badge tone="good">Published</Badge> : <Badge tone="warn">Draft</Badge>
}

// ---- layout pieces ------------------------------------------------------------------------------------------------
export function PageHeader({ title, subtitle, actions }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>
        {subtitle && <p className="muted">{subtitle}</p>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </div>
  )
}

export function Card({ title, actions, children, className = '' }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`card ${className}`}>
      {(title || actions) && (
        <div className="card-head">
          {title && <h2>{title}</h2>}
          {actions && <div className="card-actions">{actions}</div>}
        </div>
      )}
      {children}
    </section>
  )
}

export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <div className="stat">
      <div className="stat-value">{value}</div>
      <div className="stat-label">{label}</div>
      {hint && <div className="stat-hint muted">{hint}</div>}
    </div>
  )
}

export function Tabs({ tabs }: { tabs: { to: string; label: string; end?: boolean }[] }) {
  return (
    <nav className="tabs" aria-label="Course sections">
      {tabs.map((tab) => (
        <NavLink key={tab.to} to={tab.to} end={tab.end} className={({ isActive }) => `tab${isActive ? ' tab-active' : ''}`}>
          {tab.label}
        </NavLink>
      ))}
    </nav>
  )
}

// ---- states: loading, empty, error --------------------------------------------------------------------------------
export function Spinner({ label = 'Loading' }: { label?: string }) {
  return <span className="spinner" role="status" aria-label={label} />
}

export function Loading({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="state" role="status" aria-live="polite">
      <span className="spinner" aria-hidden="true" />
      <span>{label}</span>
    </div>
  )
}

export function EmptyState({ title, children, action, icon }: { title: string; children?: ReactNode; action?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="state empty">
      {icon && (
        <span className="state-icon" aria-hidden="true">
          {icon}
        </span>
      )}
      <strong>{title}</strong>
      {children && <p className="muted">{children}</p>}
      {action}
    </div>
  )
}

/** A circular "how much of this is done" indicator, used where a learning tool should feel like progress rather than a data table. */
export function ProgressRing({ percent, size = 84, stroke = 8, label }: { percent: number; size?: number; stroke?: number; label?: string }) {
  const radius = (size - stroke) / 2
  const circumference = 2 * Math.PI * radius
  const clamped = Math.max(0, Math.min(100, percent))
  const offset = circumference * (1 - clamped / 100)
  return (
    <div className="progress-ring" style={{ width: size, height: size }} role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(clamped)} aria-label={label}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
        <circle className="progress-ring-track" cx={size / 2} cy={size / 2} r={radius} strokeWidth={stroke} fill="none" />
        <circle
          className="progress-ring-value"
          cx={size / 2}
          cy={size / 2}
          r={radius}
          strokeWidth={stroke}
          fill="none"
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          strokeLinecap="round"
          transform={`rotate(-90 ${size / 2} ${size / 2})`}
        />
      </svg>
      <span className="progress-ring-text">{Math.round(clamped)}%</span>
    </div>
  )
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const requestId = error instanceof ApiError ? error.requestId : null
  return (
    <div className="state error" role="alert">
      <strong>Something went wrong</strong>
      <p>{errorMessage(error)}</p>
      {requestId && <p className="muted small">Reference: {requestId}</p>}
      {onRetry && <Button onClick={onRetry}>Try again</Button>}
    </div>
  )
}

export function Alert({ tone = 'error', children }: { tone?: 'error' | 'success' | 'info' | 'warn'; children: ReactNode }) {
  return (
    <div className={`alert alert-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
      {children}
    </div>
  )
}

/** Shows a form-level message for a failed request, unless every problem belongs to a field. */
export function FormError({ error }: { error: unknown }) {
  if (!error) return null
  if (error instanceof ApiError && Object.keys(error.errors).length > 0) {
    return <Alert>{Object.values(error.errors).flat().join(' ')}</Alert>
  }
  return <Alert>{errorMessage(error)}</Alert>
}

/** Renders loading, error, and (optionally) empty states around a query; the children run only when there is data. */
export function QueryView<T>({
  query,
  children,
  isEmpty,
  empty,
  label,
}: {
  query: UseQueryResult<T>
  children: (data: T) => ReactNode
  isEmpty?: (data: T) => boolean
  empty?: ReactNode
  label?: string
}) {
  if (query.isPending) return <Loading label={label} />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  if (isEmpty?.(query.data)) return <>{empty}</>
  return <>{children(query.data)}</>
}

// ---- form fields ----------------------------------------------------------------------------------------------------
interface FieldProps {
  label: string
  error?: string
  hint?: ReactNode
  optional?: boolean
}

function FieldShell({ id, label, error, hint, optional, children }: FieldProps & { id: string; children: ReactNode }) {
  return (
    <div className={`field${error ? ' field-invalid' : ''}`}>
      <label htmlFor={id}>
        {label}
        {optional && <span className="muted"> (optional)</span>}
      </label>
      {children}
      {hint && (
        <div className="hint" id={`${id}-hint`}>
          {hint}
        </div>
      )}
      {error && (
        <div className="field-error" id={`${id}-error`} role="alert">
          {error}
        </div>
      )}
    </div>
  )
}

const describedBy = (id: string, error?: string, hint?: ReactNode) => [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined

export function TextField({ label, error, hint, optional, ...rest }: FieldProps & InputHTMLAttributes<HTMLInputElement>) {
  const id = useId()
  return (
    <FieldShell id={id} label={label} error={error} hint={hint} optional={optional}>
      <input id={id} {...rest} aria-invalid={!!error || undefined} aria-describedby={describedBy(id, error, hint)} />
    </FieldShell>
  )
}

export function TextArea({ label, error, hint, optional, ...rest }: FieldProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const id = useId()
  return (
    <FieldShell id={id} label={label} error={error} hint={hint} optional={optional}>
      <textarea id={id} rows={4} {...rest} aria-invalid={!!error || undefined} aria-describedby={describedBy(id, error, hint)} />
    </FieldShell>
  )
}

export function SelectField({ label, error, hint, optional, children, ...rest }: FieldProps & SelectHTMLAttributes<HTMLSelectElement>) {
  const id = useId()
  return (
    <FieldShell id={id} label={label} error={error} hint={hint} optional={optional}>
      <select id={id} {...rest} aria-invalid={!!error || undefined} aria-describedby={describedBy(id, error, hint)}>
        {children}
      </select>
    </FieldShell>
  )
}

export function CheckField({ label, hint, ...rest }: { label: ReactNode; hint?: ReactNode } & Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const id = useId()
  return (
    <div className="field field-check">
      <input id={id} type="checkbox" {...rest} />
      <label htmlFor={id}>
        {label}
        {hint && <span className="hint block">{hint}</span>}
      </label>
    </div>
  )
}

export function FileField({ label, error, hint, optional, ...rest }: FieldProps & Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const id = useId()
  return (
    <FieldShell id={id} label={label} error={error} hint={hint} optional={optional}>
      <input id={id} type="file" {...rest} aria-invalid={!!error || undefined} aria-describedby={describedBy(id, error, hint)} />
    </FieldShell>
  )
}

// ---- dialogs --------------------------------------------------------------------------------------------------------
export function Modal({ title, onClose, children, wide = false }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  const ref = useRef<HTMLDivElement>(null)
  const titleId = useId()

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const node = ref.current
    const focusable = () => Array.from(node?.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? [])
    ;(focusable()[0] ?? node)?.focus()
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
      } else if (event.key === 'Tab') {
        const items = focusable()
        if (items.length === 0) return
        const first = items[0]
        const last = items[items.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first.focus()
        }
      }
    }
    document.addEventListener('keydown', onKey)
    document.body.classList.add('modal-open')
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.classList.remove('modal-open')
      previous?.focus?.()
    }
  }, [onClose])

  return (
    <div className="modal-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div className={`modal${wide ? ' modal-wide' : ''}`} role="dialog" aria-modal="true" aria-labelledby={titleId} ref={ref} tabIndex={-1}>
        <div className="modal-head">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Close">
            ×
          </button>
        </div>
        <div className="modal-body">{children}</div>
      </div>
    </div>
  )
}

// ---- toasts ---------------------------------------------------------------------------------------------------------
interface ToastItem {
  id: number
  tone: 'success' | 'error' | 'info'
  text: string
}
interface ToastApi {
  success: (text: string) => void
  error: (error: unknown) => void
  info: (text: string) => void
}
const ToastContext = createContext<ToastApi | null>(null)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([])
  const counter = useRef(0)
  const push = useCallback((tone: ToastItem['tone'], text: string) => {
    const id = ++counter.current
    setItems((current) => [...current.slice(-3), { id, tone, text }])
    setTimeout(() => setItems((current) => current.filter((item) => item.id !== id)), tone === 'error' ? 8000 : 4500)
  }, [])
  const api = useMemo<ToastApi>(
    () => ({ success: (text) => push('success', text), info: (text) => push('info', text), error: (error) => push('error', errorMessage(error)) }),
    [push],
  )
  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="toasts" aria-live="polite" aria-atomic="false">
        {items.map((item) => (
          <div key={item.id} className={`toast toast-${item.tone}`} role={item.tone === 'error' ? 'alert' : 'status'}>
            {item.text}
            <button type="button" className="icon-btn" aria-label="Dismiss" onClick={() => setItems((current) => current.filter((t) => t.id !== item.id))}>
              ×
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastApi {
  const context = useContext(ToastContext)
  if (!context) throw new Error('useToast must be used inside ToastProvider')
  return context
}

// ---- confirmation ---------------------------------------------------------------------------------------------------
interface ConfirmOptions {
  title: string
  message?: ReactNode
  confirmLabel?: string
  danger?: boolean
}
type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>
const ConfirmContext = createContext<ConfirmFn | null>(null)

export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [pending, setPending] = useState<(ConfirmOptions & { resolve: (ok: boolean) => void }) | null>(null)
  const confirm = useCallback<ConfirmFn>((options) => new Promise((resolve) => setPending({ ...options, resolve })), [])
  const close = (ok: boolean) => {
    pending?.resolve(ok)
    setPending(null)
  }
  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      {pending && (
        <Modal title={pending.title} onClose={() => close(false)}>
          {pending.message && <div className="confirm-message">{pending.message}</div>}
          <div className="form-actions">
            <Button onClick={() => close(false)}>Cancel</Button>
            <Button variant={pending.danger ? 'danger' : 'primary'} onClick={() => close(true)}>
              {pending.confirmLabel ?? 'Confirm'}
            </Button>
          </div>
        </Modal>
      )}
    </ConfirmContext.Provider>
  )
}

export function useConfirm(): ConfirmFn {
  const context = useContext(ConfirmContext)
  if (!context) throw new Error('useConfirm must be used inside ConfirmProvider')
  return context
}

// ---- paging ---------------------------------------------------------------------------------------------------------
export function Pager({ page, lastPage, total, onPage }: { page: number; lastPage: number; total?: number; onPage: (page: number) => void }) {
  if (lastPage <= 1) return total !== undefined && total > 0 ? <p className="muted small pager-note">{total} in total</p> : null
  return (
    <nav className="pager" aria-label="Pages">
      <Button small disabled={page <= 1} onClick={() => onPage(page - 1)}>
        Previous
      </Button>
      <span>
        Page {page} of {lastPage}
        {total !== undefined && <span className="muted"> · {total} in total</span>}
      </span>
      <Button small disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
        Next
      </Button>
    </nav>
  )
}

export const pagerFromPage = (data: Page<unknown>) => ({ page: data.current_page, lastPage: data.last_page, total: data.total })
export const pagerFromMeta = (meta: Meta) => ({ page: meta.page, lastPage: meta.last_page, total: meta.total })

// ---- small table wrapper --------------------------------------------------------------------------------------------
export function Table({ children, caption }: { children: ReactNode; caption?: string }) {
  return (
    <div className="table-wrap">
      <table>
        {caption && <caption className="sr-only">{caption}</caption>}
        {children}
      </table>
    </div>
  )
}

export function Avatar({ name }: { name: string }) {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  const text = ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?'
  return (
    <span className="avatar" aria-hidden="true">
      {text}
    </span>
  )
}

/** Debounces a fast-changing value such as a search box. */
export function useDebounced<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])
  return debounced
}
