/** "Mode set by X on D" for the company's receipt stock mode (E8-INV A11). */
export function stockModeChangedText(changed: { by: string | null; at: string | null } | undefined | null): string {
  if (!changed || (!changed.by && !changed.at)) return 'This is the company setting it started with (Settings → Purchase profile).'
  const when = changed.at ? new Date(changed.at).toLocaleDateString() : null
  return `Set in Settings → Purchase profile${changed.by ? ` by ${changed.by}` : ''}${when ? ` on ${when}` : ''}.`
}
