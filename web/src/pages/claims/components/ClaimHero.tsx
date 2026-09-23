/**
 * The short explanation of what this screen is for.
 *
 * Kept shallow on purpose. It is read once by somebody new and skipped by
 * everybody else, so it earns about a hundred pixels and no more — the form
 * beneath it is the product.
 */

import { CheckCircle2, HandCoins, Link2, ShieldCheck } from 'lucide-react'

const BENEFITS = [
  { icon: Link2, title: 'Link POs & bills', caption: 'Auto fetch details' },
  { icon: CheckCircle2, title: 'Track resolution', caption: 'Stay updated' },
  { icon: HandCoins, title: 'Get quicker refunds', caption: 'Better supplier relationships' },
]

export function ClaimHero() {
  return (
    <section className="claim-hero" aria-label="About supplier claims">
      <div className="claim-hero-intro">
        <span className="claim-hero-art" aria-hidden>
          <HandCoins size={28} />
        </span>
        <div>
          <h2>Recover what's yours</h2>
          <p>
            Create a supplier claim with all the details, documents and references. We'll track it until it's resolved.
          </p>
        </div>
      </div>

      {BENEFITS.map((benefit) => {
        const Icon = benefit.icon

        return (
          <div className="claim-benefit" key={benefit.title}>
            <span className="claim-benefit-mark" aria-hidden>
              <Icon size={15} />
            </span>
            <strong>{benefit.title}</strong>
            <span>{benefit.caption}</span>
          </div>
        )
      })}

      <div className="claim-hero-pledge">
        <span className="claim-hero-pledge-mark" aria-hidden>
          <ShieldCheck size={18} />
        </span>
        <div>
          <strong>Fair purchasing</strong>
          <span>Stronger tomorrow</span>
        </div>
      </div>
    </section>
  )
}
