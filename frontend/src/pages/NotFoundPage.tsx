import { Link } from 'react-router'

export function NotFoundPage() {
  return (
    <main className="page" style={{ textAlign: 'center', paddingTop: 96 }}>
      <p className="eyebrow">404</p>
      <h1 style={{ fontSize: 40, margin: '10px 0 12px' }}>Not on the team sheet</h1>
      <p className="muted">This page or player doesn’t exist in the current season.</p>
      <p>
        <Link to="/" style={{ color: 'var(--accent-text)', fontWeight: 600 }}>
          Back to the ranking →
        </Link>
      </p>
    </main>
  )
}
