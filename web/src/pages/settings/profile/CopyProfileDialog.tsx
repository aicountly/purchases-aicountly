/**
 * Copy the settings from another company's purchase profile.
 *
 * A company has exactly one purchase profile — that is the contract five
 * services read — so the only other profiles that exist are the ones belonging
 * to the other companies this person can open. Those are listed from Manage,
 * live, the same way the company switcher lists them, and read through the SAME
 * scoped endpoint as this company's own profile. That matters: every scoped
 * request is checked against Manage before it touches a row, so copying is
 * authorised exactly as strictly as opening that company would be, and there is
 * no second permission path to get wrong.
 *
 * THE IDENTITY IS NEVER COPIED. The code, the name and the status stay this
 * company's — they are what tells the two profiles apart, and a copy that
 * overwrote them would leave an administrator looking at a screen that claims
 * to be somebody else's profile.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertTriangle, Building2, Check, Loader2, Search } from 'lucide-react'
import { ApiError } from '../../../services/api'
import { fetchAllCompanies, fetchCompanyInfo, type CompanyOption } from '../../../services/manage'
import { fetchProfileForScope } from '../../../services/purchaseProfile'
import { money } from '../../../ui'
import { PpButton, PpDialog, PpDl, PpEmpty, PpStrip, PpSummary } from './ProfileUi'
import { PREFIX_FIELDS, fromSettings, type ProfileForm } from './profileModel'

interface Loaded {
  company: CompanyOption
  fyId: number
  form: ProfileForm
}

export function CopyProfileDialog({
  open,
  currentCmpId,
  onClose,
  onApply,
}: {
  open: boolean
  currentCmpId: number | null
  onClose: () => void
  /** The parts that may be copied. Identity and status are deliberately absent. */
  onApply: (source: Pick<ProfileForm, 'type' | 'description' | 'numbering' | 'approvals'>, label: string) => void
}) {
  const [companies, setCompanies] = useState<CompanyOption[] | null>(null)
  const [listError, setListError] = useState<string | null>(null)
  const [query, setQuery] = useState('')
  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [reading, setReading] = useState<number | null>(null)
  const [readError, setReadError] = useState<string | null>(null)

  // Reset every time it opens: a dialog that reopens showing the last company
  // somebody looked at is a dialog that copies the wrong profile eventually.
  useEffect(() => {
    if (!open) return
    setQuery('')
    setLoaded(null)
    setReadError(null)
    setListError(null)
    setCompanies(null)

    const controller = new AbortController()
    fetchAllCompanies(controller.signal)
      .then((rows) => {
        if (!controller.signal.aborted) setCompanies(rows)
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        setCompanies([])
        setListError(error instanceof Error ? error.message : 'Your companies could not be listed.')
      })

    return () => controller.abort()
  }, [open])

  const others = useMemo(
    () => (companies ?? []).filter((company) => company.cmpId !== currentCmpId),
    [companies, currentCmpId],
  )

  const matches = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (needle === '') return others
    return others.filter((company) => company.name.toLowerCase().includes(needle) || String(company.cmpId).includes(needle))
  }, [others, query])

  const read = useCallback(async (company: CompanyOption) => {
    setReading(company.cmpId)
    setReadError(null)
    setLoaded(null)
    try {
      // Manage holds the financial years; a scoped read needs one, and the most
      // recent is the one the switcher would open too.
      const info = await fetchCompanyInfo(company.cmpId)
      const fyId = info.fyList[0]?.fyId
      if (!fyId) {
        setReadError(`${company.name} has no financial year in Aicountly Manage, so it has no profile to copy.`)
        return
      }
      const response = await fetchProfileForScope({ cmp_id: company.cmpId, fy_id: fyId, bo_id: 0 })
      setLoaded({ company, fyId, form: fromSettings(response.data) })
    } catch (error) {
      setReadError(
        error instanceof ApiError
          ? error.message
          : error instanceof Error
            ? error.message
            : 'That profile could not be read.',
      )
    } finally {
      setReading(null)
    }
  }, [])

  const body = () => {
    if (companies === null) {
      return (
        <div style={{ display: 'grid', gap: 8 }} aria-busy="true">
          <div className="pp-skeleton" style={{ height: 56 }} />
          <div className="pp-skeleton" style={{ height: 56 }} />
          <div className="pp-skeleton" style={{ height: 56 }} />
        </div>
      )
    }

    if (listError) {
      return (
        <PpStrip tone="danger" icon={<AlertTriangle size={14} aria-hidden />}>
          {listError}
        </PpStrip>
      )
    }

    if (others.length === 0) {
      return (
        <PpEmpty icon={<Building2 size={18} aria-hidden />} title="No other purchase profiles are available">
          A purchase profile belongs to a company, and this sign-in can open only this one. Ask whoever administers
          the other company in Aicountly Manage for access, and it will appear here.
        </PpEmpty>
      )
    }

    return (
      <>
        <div className="pp-search">
          <Search size={15} aria-hidden />
          <input
            type="search"
            value={query}
            placeholder="Search your companies…"
            aria-label="Search your companies"
            onChange={(event) => setQuery(event.target.value)}
          />
        </div>

        {matches.length === 0 ? (
          <PpEmpty icon={<Search size={18} aria-hidden />} title="Nothing matches that">
            Try a different name, or clear the search.
          </PpEmpty>
        ) : (
          <div role="listbox" aria-label="Companies you can copy from">
            {matches.map((company) => {
              const selected = loaded?.company.cmpId === company.cmpId
              return (
                <button
                  key={company.cmpId}
                  type="button"
                  role="option"
                  aria-selected={selected}
                  className={selected ? 'pp-option is-selected' : 'pp-option'}
                  disabled={reading !== null}
                  onClick={() => void read(company)}
                >
                  <span className="pp-quick__icon" aria-hidden>
                    <Building2 size={15} />
                  </span>
                  <span className="pp-option__text">
                    <strong>{company.name}</strong>
                    <small>
                      Company {company.cmpId}
                      {company.ownership === 'shared' ? ' · shared with you' : ''}
                    </small>
                  </span>
                  {reading === company.cmpId ? (
                    <Loader2 size={15} aria-hidden />
                  ) : selected ? (
                    <Check size={15} aria-hidden />
                  ) : null}
                </button>
              )
            })}
          </div>
        )}

        {readError && (
          <div style={{ marginTop: 12 }}>
            <PpStrip tone="danger" icon={<AlertTriangle size={14} aria-hidden />}>
              {readError}
            </PpStrip>
          </div>
        )}

        {loaded && (
          <>
            <PpSummary title={`What will be copied from ${loaded.company.name}`}>
              <PpDl
                rows={[
                  { label: 'Profile type', value: loaded.form.type },
                  {
                    label: 'Description',
                    value: loaded.form.description.trim() || 'Not set',
                    muted: loaded.form.description.trim() === '',
                  },
                  // The prefix alone: the rest of a document number is
                  // the financial year of whoever issues it, which is this
                  // company's, not the one being read from.
                  ...PREFIX_FIELDS.map((field) => ({
                    label: field.label,
                    value: loaded.form.numbering[field.key],
                  })),
                  {
                    label: 'Requisition limit',
                    value: money(Number(loaded.form.approvals.requisitionLimit || 0)),
                  },
                  {
                    label: 'Purchase order limit',
                    value: money(Number(loaded.form.approvals.purchaseOrderLimit || 0)),
                  },
                  {
                    label: 'Approved suppliers only',
                    value: loaded.form.approvals.approvedSuppliersOnly ? 'Yes' : 'No',
                  },
                  {
                    label: 'Block bills on exception',
                    value: loaded.form.approvals.blockBillOnMatchException ? 'Yes' : 'No',
                  },
                ]}
              />
            </PpSummary>

            <PpStrip tone="info">
              The profile code, name and status are <strong>not</strong> copied — they are what tells your profile
              apart from theirs. Tolerance policies are not copied either; export and import the profile file for
              those.
            </PpStrip>
          </>
        )}
      </>
    )
  }

  return (
    <PpDialog
      open={open}
      size="wide"
      title="Copy from an existing profile"
      subtitle="Take the numbering, approvals and controls from another company you can open."
      onClose={onClose}
      footer={
        <>
          <PpButton onClick={onClose}>Cancel</PpButton>
          <PpButton
            tone="primary"
            disabled={!loaded}
            onClick={() => {
              if (!loaded) return
              onApply(
                {
                  type: loaded.form.type,
                  description: loaded.form.description,
                  numbering: loaded.form.numbering,
                  approvals: loaded.form.approvals,
                },
                loaded.company.name,
              )
            }}
          >
            Copy these settings
          </PpButton>
        </>
      }
    >
      {body()}
    </PpDialog>
  )
}
