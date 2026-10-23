/**
 * The legal, support and grievance surface every AICOUNTLY web app carries (launch item A.1): the same
 * entries, the same order and the same wording as Billing, Inventory, Books and Manage.
 */

export const LEGAL_ENTITY = 'Aicountly Interactive Services Private Limited'
export const COPYRIGHT = `© 2026 ${LEGAL_ENTITY}`

export const GRIEVANCE_OFFICER = { name: 'Vikram Rana', email: 'grievance@aicountly.com' } as const

export const LEGAL_LINKS: ReadonlyArray<{ label: string; href: string }> = [
  { label: 'Privacy', href: 'https://aicountly.com/privacy-policy' },
  { label: 'Terms', href: 'https://aicountly.com/terms-of-use' },
  { label: 'Refund policy', href: 'https://aicountly.com/refund-policy' },
  { label: 'Delete account', href: 'https://aicountly.com/delete-account' },
  { label: 'Support', href: 'https://aicountly.com/support' },
  { label: 'Grievance', href: 'https://aicountly.com/complaints' },
]
