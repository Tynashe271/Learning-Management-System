import { describe, expect, it } from 'vitest'
import { configureFormats, formatBytes, formatDate, formatDateTime, formatPercent, formatScore, fromLocalInput, initials, isPast, plural, relativeTime, toDateInput, toLocalInput } from '../src/lib/format'
import { notificationLink, notificationText } from '../src/lib/notifications'
import type { Notification } from '../src/api/types'

describe('formatting', () => {
  it('shows marks without needless decimals', () => {
    expect(formatScore(12)).toBe('12')
    expect(formatScore('12.50')).toBe('12.5')
    expect(formatScore(7.333)).toBe('7.33')
    expect(formatScore(null)).toBe('—')
    expect(formatPercent(87.5)).toBe('87.5%')
    expect(formatPercent(null)).toBe('—')
  })

  it('shows plain dates as the same calendar day everywhere', () => {
    expect(formatDate('2026-01-05')).toMatch(/5/)
    expect(formatDate('2026-01-05')).toMatch(/2026/)
    expect(formatDate(null)).toBe('—')
    expect(toDateInput('2026-01-05T00:00:00.000000Z')).toBe('2026-01-05')
  })

  it('converts between UTC timestamps and the local datetime inputs', () => {
    const iso = '2026-09-24T15:30:00.000Z'
    const local = toLocalInput(iso)
    expect(local).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/)
    expect(fromLocalInput(local)).toBe(iso)
    expect(toLocalInput(null)).toBe('')
  })

  it('formats sizes, plurals, initials and relative times', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2048)).toBe('2.0 KB')
    expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MB')
    expect(plural(1, 'reply', 'replies')).toBe('1 reply')
    expect(plural(3, 'reply', 'replies')).toBe('3 replies')
    expect(plural(2, 'course')).toBe('2 courses')
    expect(initials('Ada Lovelace')).toBe('AL')
    expect(initials('Plato')).toBe('P')
    const now = new Date('2026-01-01T12:00:00Z')
    expect(relativeTime('2026-01-01T11:00:00Z', now)).toMatch(/hour/)
    expect(isPast('2025-01-01T00:00:00Z', now)).toBe(true)
    expect(isPast('2027-01-01T00:00:00Z', now)).toBe(false)
  })
})

describe('notifications', () => {
  const n = (type: string, data: Record<string, unknown>): Notification => ({ id: '1', type: `App\\Notifications\\${type}`, data, read_at: null, created_at: '2026-01-01T00:00:00Z' })

  it('describes each kind and links to where it belongs', () => {
    expect(notificationText(n('GradePublished', { title: 'Essay' }))).toContain('Essay')
    expect(notificationLink(n('GradePublished', { assignment_id: 9 }))).toBe('/assignments/9')
    expect(notificationLink(n('AnnouncementPosted', { offering_id: 3 }))).toBe('/courses/3/announcements')
    expect(notificationLink(n('AppealFiled', { appeal_id: 4 }))).toBe('/appeals/4')
    expect(notificationLink(n('AppealResolved', { appeal_id: 4 }))).toBe('/appeals/4')
    expect(notificationText(n('AppealResolved', { title: 'Essay', outcome: 'upheld' }))).toContain('upheld')
    expect(notificationLink(n('AssignmentDueSoon', { assignment_id: 2 }))).toBe('/assignments/2')
  })

  it('copes with kinds it does not know', () => {
    expect(notificationText(n('SomethingNew', { title: 'Hi' }))).toBe('Hi')
    expect(notificationLink(n('SomethingNew', {}))).toBeNull()
  })
})

describe('the institution’s date settings', () => {
  it('writes times in the institution’s time zone and language format, and falls back safely on a bad value', () => {
    const moment = '2026-09-19T23:30:00Z'
    configureFormats({ locale: 'en-GB', timeZone: 'Africa/Harare' }) // UTC+2: already the next day
    expect(formatDateTime(moment)).toMatch(/20 Sept?\s2026/)
    configureFormats({ locale: 'en-GB', timeZone: 'America/New_York' }) // UTC-4: still the 19th
    expect(formatDateTime(moment)).toMatch(/19 Sept?\s2026/)
    expect(() => configureFormats({ locale: 'xx-not-real-locale-at-all', timeZone: 'Not/AZone' })).not.toThrow()
    expect(formatDateTime(moment)).not.toBe('—')
    configureFormats()
  })

  it('treats a plain calendar date as that day, whatever the time zone', () => {
    configureFormats({ locale: 'en-GB', timeZone: 'Pacific/Kiritimati' })
    expect(formatDate('2026-03-01')).toMatch(/1 Mar 2026/)
    configureFormats()
  })

  it('describes the new notice from the institution', () => {
    const notice: Notification = { id: '2', type: `App\\Notifications\\SystemAnnouncementPosted`, data: { title: 'Registration closes Friday' }, read_at: null, created_at: '2026-01-01T00:00:00Z' }
    expect(notificationText(notice)).toBe('Notice from the institution: Registration closes Friday')
  })
})
