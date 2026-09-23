/**
 * The five stages of setting a profile up.
 *
 * A navigator, not a wizard. Everything on this screen is visible at once and
 * always editable — hiding four cards behind a Next button would mean an
 * administrator changing one prefix has to walk past four sections they did not
 * come for. This marks where you are and takes you where you asked.
 */

import { SETUP_SECTIONS } from './sections'

export function ProfileSetupProgress({
  active,
  onSelect,
}: {
  active: string
  onSelect: (id: string) => void
}) {
  return (
    <nav className="pp-progress" aria-label="Profile setup sections">
      {SETUP_SECTIONS.map((section, index) => {
        const isActive = section.id === active
        return (
          <button
            key={section.id}
            type="button"
            className={isActive ? 'pp-progress__step is-active' : 'pp-progress__step'}
            aria-current={isActive ? 'true' : undefined}
            onClick={() => onSelect(section.id)}
          >
            <span className="pp-progress__number" aria-hidden>
              {index + 1}
            </span>
            <span className="pp-progress__label">
              <strong>{section.title}</strong>
              <small>{section.caption}</small>
            </span>
          </button>
        )
      })}
    </nav>
  )
}
