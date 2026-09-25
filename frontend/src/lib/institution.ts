import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { AuthConfig, PasswordPolicy } from '../api/types'
import { configureFormats } from './format'

const DEFAULT_NAME = 'University LMS'
let siteName = DEFAULT_NAME

/** The name shown in the browser tab and page titles. */
export function getSiteName(): string {
  return siteName
}

/** What the sign-in page and the app need to know about the institution and its rules. Public: it works before anyone signs in. */
export function useAuthConfig() {
  return useQuery({ queryKey: ['auth-config'], queryFn: () => api.get<AuthConfig>('/auth/config'), staleTime: 10 * 60_000, retry: 1 })
}

/** Applies the institution's name, language format and time zone to the whole app. Call once, near the top, so every screen below re-renders when they load. */
export function useInstitutionSettings(): AuthConfig | undefined {
  const { data } = useAuthConfig()
  const institution = data?.institution
  siteName = institution?.short_name || institution?.name || DEFAULT_NAME
  configureFormats({ locale: institution?.locale, timeZone: institution?.timezone })
  return data
}

export function institutionName(config: AuthConfig | undefined): string {
  return config?.institution?.name || DEFAULT_NAME
}

/** "At least 12 characters, with upper and lower case letters, a number and a symbol." */
export function describePolicy(policy: PasswordPolicy | undefined): string {
  const min = policy?.min_length ?? 12
  const parts: string[] = []
  if (policy?.mixed_case) parts.push('upper and lower case letters')
  if (policy?.number) parts.push('a number')
  if (policy?.symbol) parts.push('a symbol')
  const list = parts.length > 1 ? `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}` : parts[0]
  return `At least ${min} characters${list ? `, with ${list}` : ''}.`
}

/** Checks a password against the policy on screen so people are told what is missing before they submit. */
export function policyProblems(password: string, policy: PasswordPolicy | undefined): string[] {
  const problems: string[] = []
  if (password.length < (policy?.min_length ?? 12)) problems.push(`at least ${policy?.min_length ?? 12} characters`)
  if (policy?.mixed_case && !(/[a-z]/.test(password) && /[A-Z]/.test(password))) problems.push('both upper and lower case letters')
  if (policy?.number && !/\d/.test(password)) problems.push('a number')
  if (policy?.symbol && !/[^A-Za-z0-9]/.test(password)) problems.push('a symbol')
  return problems
}
