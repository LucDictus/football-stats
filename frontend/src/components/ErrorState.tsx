import { ApiError } from '../api/client'

export function ErrorState({ error }: { error: Error }) {
  const offline = !(error instanceof ApiError)
  return (
    <div className="card" role="alert" style={{ padding: 24 }}>
      <h2 style={{ fontSize: 18, marginBottom: 6 }}>{offline ? 'The API is not reachable' : 'Something went wrong'}</h2>
      <p className="muted" style={{ margin: 0 }}>
        {offline ? 'Start the Symfony server (php -S 127.0.0.1:8010 -t public) and reload.' : error.message}
      </p>
    </div>
  )
}
