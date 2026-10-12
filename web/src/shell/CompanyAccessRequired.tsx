/**
 * "Company access restricted" — the company is registered in this person's workspace, so they
 * can see it, but its owner has not granted them Purchases (Manage answered the API's company
 * check with COMPANY_ACCESS_REQUIRED). Nothing of the company is loaded behind this.
 *
 * Offers what will actually help: ask the owner (the shared "Request access" dialog, which goes
 * to Manage's registry), or choose another company. Never "try again".
 */

import { useState } from 'react'
import { RestrictedCompanyDialog } from '../company-access/RestrictedCompanyDialog.tsx'
import type { DirectoryCompany } from '../company-access/companyRegistryClient.ts'
import { COMPANY_REGISTRY_APP_NAME, companyRegistry } from '../services/companyRegistry'
import { Button, Notice } from '../ui'

export function CompanyAccessRequired({
  cmpId,
  message,
  onChooseAnother,
}: {
  cmpId: number
  message: string
  onChooseAnother: () => void
}) {
  const [company, setCompany] = useState<DirectoryCompany | null>(null)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const openRequest = () => {
    setBusy(true)
    setProblem(null)
    companyRegistry
      .summary(cmpId)
      .then(setCompany)
      .catch((e: unknown) => setProblem(e instanceof Error ? e.message : 'Aicountly Manage could not be reached.'))
      .finally(() => setBusy(false))
  }

  return (
    <>
      <Notice
        tone="warning"
        title="Company access restricted"
        action={
          <span style={{ display: 'inline-flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            <Button tone="primary" onClick={openRequest} disabled={busy}>
              {busy ? 'Opening…' : 'Request access'}
            </Button>
            <Button onClick={onChooseAnother}>Choose another company</Button>
          </span>
        }
      >
        {message}
        {problem !== null && <div style={{ marginTop: '0.4rem' }}>{problem}</div>}
      </Notice>
      {company !== null && (
        <RestrictedCompanyDialog
          client={companyRegistry}
          company={company}
          appName={COMPANY_REGISTRY_APP_NAME}
          onClose={() => setCompany(null)}
        />
      )}
    </>
  )
}
