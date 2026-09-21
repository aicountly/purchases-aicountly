/**
 * The one search box in the application frame.
 *
 * It does not have a search backend of its own, and does not pretend to. It
 * writes the `q` filter the dashboards and lists already apply — to order
 * numbers, supplier names and supplier invoice numbers — so what it finds is
 * exactly what the screen it lands on would have found.
 *
 * The second action is real too: the Ask box on Purchase intelligence answers
 * questions from live data, and a term that looks like a question offers to go
 * there instead of pretending a keyword search understood it.
 */

import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { CornerDownLeft, Search, Sparkles } from 'lucide-react'

/** Enough of a question that a keyword match would be the wrong answer. */
function looksLikeAQuestion(term: string): boolean {
  const text = term.trim()
  if (text.endsWith('?')) return true

  return /^(which|what|why|how|when|who|show|list|is|are|do|does|can)\b/i.test(text) && text.split(/\s+/).length >= 3
}

export function HeaderSearch() {
  const navigate = useNavigate()
  const [term, setTerm] = useState('')
  const [open, setOpen] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  const boxRef = useRef<HTMLDivElement>(null)

  // Ctrl+K / ⌘K focuses it, Escape gives the page back. Both are what every
  // other application this user has open today already does.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        inputRef.current?.focus()
        inputRef.current?.select()
      }
      if (event.key === 'Escape' && document.activeElement === inputRef.current) {
        setOpen(false)
        inputRef.current?.blur()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  useEffect(() => {
    function onClickAway(event: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickAway)
    return () => document.removeEventListener('mousedown', onClickAway)
  }, [])

  const search = () => {
    const text = term.trim()
    if (text === '') return
    setOpen(false)
    navigate(`/dashboard/overview?q=${encodeURIComponent(text)}`)
  }

  const ask = () => {
    const text = term.trim()
    if (text === '') return
    setOpen(false)
    navigate(`/dashboard/ai-insights?ask=${encodeURIComponent(text)}`)
  }

  return (
    <div className="app-search" ref={boxRef}>
      <form
        role="search"
        onSubmit={(event) => {
          event.preventDefault()
          search()
        }}
      >
        <Search size={15} aria-hidden className="app-search__icon" />
        <input
          ref={inputRef}
          type="search"
          value={term}
          placeholder="Search suppliers, orders, invoices or ask a question"
          aria-label="Search purchases"
          onChange={(event) => {
            setTerm(event.target.value)
            setOpen(event.target.value.trim() !== '')
          }}
          onFocus={() => setOpen(term.trim() !== '')}
        />
        <kbd className="app-search__hint" aria-hidden>
          Ctrl K
        </kbd>
      </form>

      {open && (
        <div className="app-search__menu">
          <button type="button" onClick={search}>
            <Search size={14} aria-hidden />
            <span>
              Search purchases for <strong>{term.trim()}</strong>
            </span>
            <CornerDownLeft size={13} aria-hidden className="app-search__enter" />
          </button>

          <button type="button" onClick={ask}>
            <Sparkles size={14} aria-hidden />
            <span>
              {looksLikeAQuestion(term) ? 'Ask purchase intelligence' : 'Ask purchase intelligence about this'}
            </span>
          </button>

          <p className="app-search__note">
            Search matches order numbers, supplier names and supplier invoice numbers.
          </p>
        </div>
      )}
    </div>
  )
}
