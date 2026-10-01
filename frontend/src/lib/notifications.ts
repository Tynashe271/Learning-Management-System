import type { Notification } from '../api/types'

const kind = (n: Notification) => n.type.split('\\').pop() ?? n.type

/** What a notification says, in a sentence. */
export function notificationText(n: Notification): string {
  const title = typeof n.data.title === 'string' ? n.data.title : ''
  switch (kind(n)) {
    case 'AnnouncementPosted':
      return `New announcement: ${title}`
    case 'GradePublished':
      return `Your grade for “${title}” has been published`
    case 'AppealFiled':
      return `A student appealed the grade for “${title}”`
    case 'AppealResolved': {
      const outcome = n.data.outcome === 'upheld' ? 'upheld' : 'decided'
      return `Your appeal for “${title}” was ${outcome}`
    }
    case 'AssignmentDueSoon':
      return `Due soon: ${title}`
    case 'SystemAnnouncementPosted':
      return `Notice from the institution: ${title}`
    case 'SupportCheckIn':
      return typeof n.data.message === 'string' ? n.data.message : `A note from your ${title} teacher`
    default:
      return title || 'Update'
  }
}

/** Where a notification should take the person, or null when there is nowhere useful to go. */
export function notificationLink(n: Notification): string | null {
  const d = n.data as Record<string, unknown>
  switch (kind(n)) {
    case 'AnnouncementPosted':
      return d.offering_id ? `/courses/${d.offering_id}/announcements` : null
    case 'SupportCheckIn':
      return d.offering_id ? `/courses/${d.offering_id}` : null
    case 'GradePublished':
    case 'AssignmentDueSoon':
      return d.assignment_id ? `/assignments/${d.assignment_id}` : null
    case 'AppealFiled':
    case 'AppealResolved':
      return d.appeal_id ? `/appeals/${d.appeal_id}` : null
    default:
      return null
  }
}
