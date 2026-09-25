import { useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, ApiError, setUnauthorizedHandler, tokenStore } from '../api/client'
import type { LoginResult, Me, Permission } from '../api/types'

interface AuthState {
  user: Me | null
  /** true until the first check of a stored token has finished */
  loading: boolean
  /** true when the session ended because the server said the token is no longer valid */
  expired: boolean
  /** set when a stored token could not be checked because of a hiccup (timeout, server error, maintenance), not because it was refused */
  checkError: unknown
  retryCheck: () => void
  login: (email: string, password: string, remember: boolean) => Promise<void>
  acceptToken: (token: string, remember: boolean) => Promise<void>
  logout: () => Promise<void>
  refresh: () => Promise<void>
  setUser: (user: Me) => void
  can: (permission: Permission) => boolean
  hasRole: (role: string) => boolean
  /** True when the person's only role is "student" — the signal for the learning-focused student experience. */
  isStudentOnly: boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [user, setUser] = useState<Me | null>(null)
  const [loading, setLoading] = useState<boolean>(() => tokenStore.get() !== null)
  const [expired, setExpired] = useState(false)
  const [checkError, setCheckError] = useState<unknown>(null)
  const [attempt, setAttempt] = useState(0)
  const retryCheck = useCallback(() => setAttempt((n) => n + 1), [])

  const clearSession = useCallback(() => {
    tokenStore.clear()
    setUser(null)
    setCheckError(null)
    queryClient.clear()
  }, [queryClient])

  useEffect(() => {
    setUnauthorizedHandler(() => {
      if (tokenStore.get()) setExpired(true)
      clearSession()
    })
    return () => setUnauthorizedHandler(null)
  }, [clearSession])

  useEffect(() => {
    if (!tokenStore.get()) return
    let cancelled = false
    setLoading(true)
    setCheckError(null)
    api
      .get<Me>('/me')
      .then((me) => !cancelled && setUser(me))
      .catch((error) => {
        // A 401 is handled by the handler above (the session really ended). Anything else is a hiccup: keep the token and let the
        // person try again, instead of sending them to the sign-in page as if they had been signed out.
        if (!cancelled && !(error instanceof ApiError && error.status === 401)) setCheckError(error)
      })
      .finally(() => !cancelled && setLoading(false))
    return () => {
      cancelled = true
    }
  }, [attempt])

  const acceptToken = useCallback(async (token: string, remember: boolean) => {
    tokenStore.set(token, remember)
    setExpired(false)
    try {
      setUser(await api.get<Me>('/me'))
    } catch (error) {
      tokenStore.clear()
      throw error
    }
  }, [])

  const login = useCallback(
    async (email: string, password: string, remember: boolean) => {
      const result = await api.post<LoginResult>('/login', { email, password }, { anonymous: true })
      await acceptToken(result.token, remember)
    },
    [acceptToken],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/logout')
    } catch {
      /* the token may already be invalid; signing out locally is what matters */
    }
    clearSession()
  }, [clearSession])

  const refresh = useCallback(async () => {
    setUser(await api.get<Me>('/me'))
  }, [])

  const value = useMemo<AuthState>(() => {
    const permissions = new Set<string>(user?.permissions ?? [])
    const roles = new Set<string>((user?.roles ?? []).map((role) => role.name))
    return {
      user,
      loading,
      expired,
      checkError,
      retryCheck,
      login,
      acceptToken,
      logout,
      refresh,
      setUser,
      can: (permission) => permissions.has(permission),
      hasRole: (role) => roles.has(role),
      isStudentOnly: roles.size === 1 && roles.has('student'),
    }
  }, [user, loading, expired, checkError, retryCheck, login, acceptToken, logout, refresh])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside AuthProvider')
  return context
}

/** The signed-in user; only for screens that sit behind the sign-in guard. */
export function useMe(): Me {
  const { user } = useAuth()
  if (!user) throw new Error('No signed-in user')
  return user
}
