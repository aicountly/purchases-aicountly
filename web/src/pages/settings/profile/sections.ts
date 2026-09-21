/**
 * The sections of the profile workspace, in the order they are read.
 *
 * One list, used by the progress navigator, the scroll spy, the sidebar's
 * deep links and the "scroll to the first invalid field" behaviour after a
 * failed save. Four things agreeing because they read the same array.
 */

export interface SetupSectionDefinition {
  id: string
  title: string
  caption: string
}

export const SETUP_SECTIONS: SetupSectionDefinition[] = [
  { id: 'basic-details', title: 'Basic Details', caption: 'Profile information' },
  { id: 'numbering', title: 'Numbering', caption: 'Document series' },
  { id: 'approvals', title: 'Approvals', caption: 'Control & limits' },
  { id: 'matching', title: 'Match Tolerances', caption: '3-way matching' },
  { id: 'permissions', title: 'Permissions', caption: 'User access' },
]

export const SECTION_IDS = SETUP_SECTIONS.map((section) => section.id)
