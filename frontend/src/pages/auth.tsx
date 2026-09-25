import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { api, errorMessage } from '../api/client'
import type { AuthConfig, LoginResult } from '../api/types'
import { useAuth } from '../auth/AuthContext'
import { Alert, Button, CheckField, FormError, Loading, TextField } from '../components/ui'
import { fieldError, useApiMutation, useTitle } from '../lib/hooks'
import { describePolicy, institutionName, policyProblems, useAuthConfig } from '../lib/institution'

function AuthShell({ title, children, config }: { title: string; children: ReactNode; config?: AuthConfig }) {
  return (
    <div className="auth-page">
      <div className="auth-card">
        <div className="auth-brand">
          <img src="/favicon.svg" alt="" width="40" height="40" />
          <span>{institutionName(config)}</span>
        </div>
        <h1>{title}</h1>
        {config?.maintenance?.enabled && <Alert tone="warn">{config.maintenance.message || 'The system is being worked on. Only administrators can sign in right now.'}</Alert>}
        {children}
      </div>
      <p className="auth-footer muted small">
        {config?.privacy_url && (
          <a href={config.privacy_url} target="_blank" rel="noreferrer noopener">
            Privacy policy
          </a>
        )}
        {config?.privacy_url && config?.terms_url && ' · '}
        {config?.terms_url && (
          <a href={config.terms_url} target="_blank" rel="noreferrer noopener">
            Terms of use
          </a>
        )}
      </p>
    </div>
  )
}

export function LoginPage() {
  useTitle('Sign in')
  const { user, login, expired } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const config = useAuthConfig()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [ssoError, setSsoError] = useState<string | null>(null)
  const [ssoBusy, setSsoBusy] = useState(false)
  const from = (location.state as { from?: string } | null)?.from ?? '/'

  const signIn = useApiMutation(async () => login(email.trim(), password, remember), { onSuccess: () => navigate(from, { replace: true }) })

  if (user) return <Navigate to={from} replace />

  const submit = (event: FormEvent) => {
    event.preventDefault()
    signIn.mutate()
  }

  const startSso = async () => {
    setSsoError(null)
    setSsoBusy(true)
    try {
      const { url } = await api.get<{ url: string }>('/auth/sso')
      window.location.assign(url)
    } catch (error) {
      setSsoError(errorMessage(error))
      setSsoBusy(false)
    }
  }

  const passwordLogin = config.data?.password_login ?? true
  return (
    <AuthShell title="Sign in" config={config.data}>
      {expired && <Alert tone="info">Your session has ended. Please sign in again.</Alert>}
      {config.data?.sso_enabled && (
        <div className="sso-block">
          <Button variant="primary" onClick={startSso} loading={ssoBusy} className="btn-block">
            {config.data.sso_label}
          </Button>
          {ssoError && <Alert>{ssoError}</Alert>}
          {passwordLogin && <div className="divider">or use your password</div>}
        </div>
      )}
      {config.isPending && <Loading label="Loading…" />}
      {(passwordLogin || config.isError) && (
        <form onSubmit={submit} noValidate>
          <TextField label="Email" type="email" name="email" autoComplete="username" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} error={fieldError(signIn.error, 'email')} required />
          <TextField label="Password" type="password" name="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={fieldError(signIn.error, 'password')} required />
          <CheckField label="Keep me signed in on this device" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
          {signIn.error && !fieldError(signIn.error, 'email') && !fieldError(signIn.error, 'password') && <FormError error={signIn.error} />}
          <Button type="submit" variant="primary" loading={signIn.isPending} disabled={!email || !password} className="btn-block">
            Sign in
          </Button>
          <p className="auth-links">
            <Link to="/forgot-password">Forgot your password?</Link>
          </p>
        </form>
      )}
      {!passwordLogin && !config.data?.sso_enabled && <Alert tone="info">Sign-in is not available right now.</Alert>}
    </AuthShell>
  )
}

export function ForgotPasswordPage() {
  useTitle('Reset your password')
  const config = useAuthConfig()
  const [email, setEmail] = useState('')
  const send = useApiMutation((address: string) => api.post<{ message: string }>('/forgot-password', { email: address }, { anonymous: true }))

  return (
    <AuthShell title="Reset your password" config={config.data}>
      {send.isSuccess ? (
        <>
          <Alert tone="success">{send.data.message} Check your email, including the spam folder. The link works for one hour.</Alert>
          <p className="auth-links">
            <Link to="/login">Back to sign in</Link>
          </p>
        </>
      ) : (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            send.mutate(email.trim())
          }}
          noValidate
        >
          <p className="muted">Enter the email address of your account and we will send you a link to choose a new password.</p>
          <TextField label="Email" type="email" autoComplete="username" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} error={fieldError(send.error, 'email')} required />
          {send.error && !fieldError(send.error, 'email') && <FormError error={send.error} />}
          <Button type="submit" variant="primary" loading={send.isPending} disabled={!email} className="btn-block">
            Send reset link
          </Button>
          <p className="auth-links">
            <Link to="/login">Back to sign in</Link>
          </p>
        </form>
      )}
    </AuthShell>
  )
}

export function ResetPasswordPage() {
  useTitle('Choose a new password')
  const [params] = useSearchParams()
  const config = useAuthConfig()
  const token = params.get('token') ?? ''
  const [email, setEmail] = useState(params.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const reset = useApiMutation(() => api.post<{ message: string }>('/reset-password', { token, email: email.trim(), password, password_confirmation: confirmation }, { anonymous: true }))

  if (!token) {
    return (
      <AuthShell title="Choose a new password" config={config.data}>
        <Alert>This link is incomplete. Open the link from your email again, or request a new one.</Alert>
        <p className="auth-links">
          <Link to="/forgot-password">Request a new link</Link>
        </p>
      </AuthShell>
    )
  }

  return (
    <AuthShell title="Choose a new password" config={config.data}>
      {reset.isSuccess ? (
        <>
          <Alert tone="success">{reset.data.message}</Alert>
          <p className="auth-links">
            <Link to="/login">Go to sign in</Link>
          </p>
        </>
      ) : (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            reset.mutate()
          }}
          noValidate
        >
          <TextField label="Email" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} error={fieldError(reset.error, 'email')} required />
          <TextField
            label="New password"
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            hint={describePolicy(config.data?.password_policy)}
            error={fieldError(reset.error, 'password')}
            required
          />
          <TextField label="Repeat the new password" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} required />
          {password && confirmation && password !== confirmation && <div className="field-error">The two passwords do not match.</div>}
          {reset.error && !fieldError(reset.error, 'email') && !fieldError(reset.error, 'password') && <FormError error={reset.error} />}
          <Button type="submit" variant="primary" loading={reset.isPending} disabled={!email || policyProblems(password, config.data?.password_policy).length > 0 || password !== confirmation} className="btn-block">
            Save new password
          </Button>
        </form>
      )}
    </AuthShell>
  )
}

/** Where the identity provider sends the browser back to: it trades the one-time code for a normal session. */
export function SsoCallbackPage() {
  useTitle('Signing in')
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { acceptToken } = useAuth()
  const started = useRef(false)
  const [error, setError] = useState<string | null>(null)
  const code = params.get('code')
  const state = params.get('state')
  const providerError = params.get('error_description') ?? params.get('error')

  useEffect(() => {
    if (started.current) return
    started.current = true
    if (providerError) return setError(providerError)
    if (!code || !state) return setError('The sign-in response was incomplete. Please try again.')
    api
      .post<LoginResult>('/auth/sso/callback', { code, state }, { anonymous: true })
      .then((result) => acceptToken(result.token, false))
      .then(() => navigate('/', { replace: true }))
      .catch((e) => setError(errorMessage(e)))
  }, [code, state, providerError, acceptToken, navigate])

  return (
    <AuthShell title="Signing you in">
      {error ? (
        <>
          <Alert>{error}</Alert>
          <p className="auth-links">
            <Link to="/login">Back to sign in</Link>
          </p>
        </>
      ) : (
        <Loading label="Completing sign-in…" />
      )}
    </AuthShell>
  )
}
