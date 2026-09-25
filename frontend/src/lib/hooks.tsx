import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { useEffect, useState, type ReactNode } from 'react'
import { ApiError, downloadFile, type Query } from '../api/client'
import { Button, useToast } from '../components/ui'
import { getSiteName } from './institution'

/** The message for one field of a failed request, if the server named that field. */
export function fieldError(error: unknown, name: string): string | undefined {
  return error instanceof ApiError ? error.fieldError(name) : undefined
}

export function useTitle(title: string): void {
  useEffect(() => {
    document.title = `${title} · ${getSiteName()}`
  }, [title])
}

interface MutationOptions<TData> {
  /** Query keys (prefixes) to refresh once the change has gone through. */
  invalidate?: QueryKey[]
  success?: string | ((data: TData) => string)
  /** Show failures as a toast (for buttons that have no form to show the error in). */
  toastError?: boolean
  onSuccess?: (data: TData) => void
}

export function useApiMutation<TVars = void, TData = unknown>(fn: (vars: TVars) => Promise<TData>, options: MutationOptions<TData> = {}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  return useMutation<TData, ApiError, TVars>({
    mutationFn: fn,
    onSuccess: async (data) => {
      await Promise.all((options.invalidate ?? []).map((queryKey) => queryClient.invalidateQueries({ queryKey })))
      if (options.success) toast.success(typeof options.success === 'function' ? options.success(data) : options.success)
      options.onSuccess?.(data)
    },
    onError: (error) => {
      if (options.toastError) toast.error(error)
    },
  })
}

/** A button that downloads a protected file through the API and reports failures. */
export function DownloadButton({ path, filename, query, children, small = true }: { path: string; filename: string; query?: Query; children: ReactNode; small?: boolean }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  return (
    <Button
      small={small}
      loading={busy}
      onClick={async () => {
        setBusy(true)
        try {
          await downloadFile(path, filename, query)
        } catch (error) {
          toast.error(error)
        } finally {
          setBusy(false)
        }
      }}
    >
      {children}
    </Button>
  )
}

/** Remembers a value between visits to a screen (a selected tab or filter). Storage may be unavailable, so it never throws. */
export function useStoredState<T extends string>(key: string, initial: T): [T, (value: T) => void] {
  const [value, setValue] = useState<T>(() => {
    try {
      return (localStorage.getItem(key) as T | null) ?? initial
    } catch {
      return initial
    }
  })
  return [
    value,
    (next: T) => {
      setValue(next)
      try {
        localStorage.setItem(key, next)
      } catch {
        /* not remembered */
      }
    },
  ]
}

/** Turns a list of File objects from an input into a FormData entry list. */
export function appendFiles(form: FormData, name: string, files: FileList | File[] | null | undefined): void {
  for (const file of Array.from(files ?? [])) form.append(name, file)
}

export function bool(value: boolean): '1' | '0' {
  return value ? '1' : '0'
}
