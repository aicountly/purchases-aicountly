/**
 * Small "ID: 123" tag shown beside a company name so companies with similar
 * names can be told apart. Renders nothing when the id is unknown.
 */
export function CompanyIdTag({ id, className = '' }: { id: number | string | null | undefined; className?: string }) {
  if (id === null || id === undefined || String(id).trim() === '') return null
  return (
    <span className={`company-id-tag ${className}`.trim()} title={`Company ID ${id}`}>
      ID: {String(id)}
    </span>
  )
}

/** The same id for a native <option>, which can only hold text. */
export function companyIdSuffix(id: number | string | null | undefined): string {
  return id === null || id === undefined || String(id).trim() === '' ? '' : ` (ID: ${String(id)})`
}
