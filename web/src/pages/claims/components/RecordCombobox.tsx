/**
 * The type-ahead behind every "find the record" field on this screen.
 *
 * ONE COMPONENT FOR FIVE FIELDS — supplier, purchase order, bill, delivery and
 * return. Each caller passes its own search function; none of them passes rows.
 * Nothing is prefetched, nothing is cached into a list, and what is kept when
 * the user chooses is an id and enough text to show them what they chose.
 *
 * WHY IT IS DEBOUNCED AND NEEDS TWO CHARACTERS. Every keystroke here is a
 * network call to the product that owns the list — Books for suppliers, this
 * product for its own documents. One call per keystroke is a supplier master
 * being paged through a character at a time.
 *
 * KEYBOARD. It is a combobox and behaves like one: Down opens and moves,
 * Up moves back, Enter takes the highlighted row, Escape closes without
 * choosing, Tab leaves. The highlighted row is announced through
 * `aria-activedescendant` rather than by moving focus, so what the user is
 * typing stays where they are typing it.
 */

import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { Loader2, Search, X } from 'lucide-react'
import type { ReferenceOption } from '../types'
import { Button, Field, describedBy } from './ui'

type SearchFn = (term: string, signal: AbortSignal) => Promise<ReferenceOption[]>

interface Props {
  label: string
  placeholder: string
  required?: boolean
  hint?: string
  error?: string
  /** The record already chosen, shown instead of the search box. */
  selected: ReferenceOption | null
  onPick: (option: ReferenceOption) => void
  onClear: () => void
  search: SearchFn
  /**
   * Changes when the meaning of a search changes — the supplier, usually. Any
   * results on screen from before the change are dropped rather than left to be
   * chosen from a list that no longer applies.
   */
  scopeKey?: string | number | null
  disabled?: boolean
  disabledReason?: string
  emptyMessage?: string
  minChars?: number
}

function useDebounced<T>(value: T, ms: number): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), ms)

    return () => clearTimeout(timer)
  }, [value, ms])

  return debounced
}

export function RecordCombobox({
  label,
  placeholder,
  required,
  hint,
  error,
  selected,
  onPick,
  onClear,
  search,
  scopeKey = null,
  disabled = false,
  disabledReason,
  emptyMessage = 'No matches.',
  minChars = 2,
}: Props) {
  const id = useId()
  const listId = `${id}-list`
  const [term, setTerm] = useState('')
  const [options, setOptions] = useState<ReferenceOption[]>([])
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const [active, setActive] = useState(0)
  const [attempt, setAttempt] = useState(0)
  const boxRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  /**
   * The search function, held rather than depended on.
   *
   * Callers build theirs from the supplier currently chosen, so its identity
   * changes on every render of the form around it. Depending on it directly
   * would mean a fetch, a state update, a re-render and another fetch — a loop
   * that is invisible until it is a thousand requests. What genuinely changes
   * the ANSWER is the term and the scope, and those are what the effect below
   * watches; `scopeKey` is how a caller says the meaning has changed.
   */
  const searchRef = useRef(search)
  searchRef.current = search
  const debounced = useDebounced(term, 250)
  const ready = debounced.trim().length >= minChars

  // A different supplier means different orders. Anything still on screen from
  // the previous one is not an answer to this question.
  useEffect(() => {
    setOptions([])
    setFailed(null)
    setActive(0)
  }, [scopeKey])

  useEffect(() => {
    if (!ready || disabled) {
      setOptions([])
      setBusy(false)

      return
    }

    const controller = new AbortController()
    setBusy(true)
    setFailed(null)

    searchRef.current(debounced.trim(), controller.signal)
      .then((rows) => {
        if (controller.signal.aborted) return
        setOptions(rows)
        setActive(0)
      })
      .catch((err: Error) => {
        if (controller.signal.aborted) return
        setFailed(err.message || 'That list could not be loaded.')
        setOptions([])
      })
      .finally(() => {
        if (!controller.signal.aborted) setBusy(false)
      })

    return () => controller.abort()
  }, [debounced, ready, disabled, attempt, scopeKey])

  useEffect(() => {
    function onClickAway(event: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false)
    }

    document.addEventListener('mousedown', onClickAway)

    return () => document.removeEventListener('mousedown', onClickAway)
  }, [])

  const choose = useCallback(
    (option: ReferenceOption) => {
      onPick(option)
      setTerm('')
      setOpen(false)
      setOptions([])
    },
    [onPick],
  )

  const menuOpen = open && !disabled && (ready || busy)

  function onKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Escape') {
      setOpen(false)

      return
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setOpen(true)
      setActive((current) => (options.length === 0 ? 0 : (current + 1) % options.length))

      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive((current) => (options.length === 0 ? 0 : (current - 1 + options.length) % options.length))

      return
    }
    if (event.key === 'Enter' && menuOpen && options[active]) {
      event.preventDefault()
      choose(options[active])
    }
  }

  const described = describedBy(id, hint ?? disabledReason, error)

  const body = useMemo(() => {
    if (busy) {
      return (
        <p className="sc-combo__state">
          <Loader2 size={14} className="sc-spin" aria-hidden />
          Searching…
        </p>
      )
    }
    if (failed) {
      return (
        <p className="sc-combo__state is-error">
          {failed}
          <Button small onClick={() => setAttempt((n) => n + 1)}>
            Retry
          </Button>
        </p>
      )
    }
    if (options.length === 0) {
      return <p className="sc-combo__state">{emptyMessage}</p>
    }

    return (
      <>
        {options.map((option, index) => (
          <button
            key={option.id}
            type="button"
            role="option"
            id={`${listId}-${option.id}`}
            aria-selected={index === active}
            className={index === active ? 'sc-combo__option is-active' : 'sc-combo__option'}
            onMouseEnter={() => setActive(index)}
            onClick={() => choose(option)}
          >
            <strong>{option.primary}</strong>
            {option.secondary && <span>{option.secondary}</span>}
          </button>
        ))}
      </>
    )
  }, [busy, failed, options, active, emptyMessage, choose, listId])

  return (
    <Field label={label} htmlFor={id} required={required} hint={hint ?? disabledReason} error={error}>
      <div className="sc-combo" ref={boxRef}>
        <div className="sc-combo__control">
          <Search size={15} className="sc-combo__icon" aria-hidden />
          <input
            id={id}
            ref={inputRef}
            className="sc-input sc-combo__input"
            type="text"
            role="combobox"
            autoComplete="off"
            aria-expanded={menuOpen}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={menuOpen && options[active] ? `${listId}-${options[active].id}` : undefined}
            aria-describedby={described}
            aria-invalid={error ? true : undefined}
            disabled={disabled}
            placeholder={selected ? selected.primary : placeholder}
            value={term}
            onChange={(event) => {
              setTerm(event.target.value)
              setOpen(true)
            }}
            onFocus={() => setOpen(true)}
            onKeyDown={onKeyDown}
          />
          {selected && (
            <button
              type="button"
              className="sc-combo__clear"
              onClick={() => {
                onClear()
                setTerm('')
                inputRef.current?.focus()
              }}
              aria-label={`Clear ${label.toLowerCase()}`}
            >
              <X size={14} aria-hidden />
            </button>
          )}
        </div>

        {menuOpen && (
          <div className="sc-combo__menu" id={listId} role="listbox" aria-label={label}>
            {!ready && !busy ? (
              <p className="sc-combo__state">Type at least {minChars} characters.</p>
            ) : (
              body
            )}
          </div>
        )}
      </div>

      {selected && (
        <div className="sc-picked">
          <div className="sc-picked__row">
            <strong>{selected.primary}</strong>
            <Button small tone="ghost" onClick={onClear}>
              Change
            </Button>
          </div>
          {selected.secondary && <div className="sc-picked__meta">{selected.secondary}</div>}
          {selected.meta.length > 0 && (
            <div className="sc-picked__meta">
              {selected.meta.map((fact) => (
                <span key={fact.label}>
                  {fact.label}: <b>{fact.value}</b>
                </span>
              ))}
            </div>
          )}
        </div>
      )}
    </Field>
  )
}
