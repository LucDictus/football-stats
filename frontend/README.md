# Frontend

React 19 + TypeScript + Vite. Reads the Symfony JSON API (`/api`).

- **React Router** for pages: ranking (`/`), player profile (`/players/:id`), methodology.
- **TanStack Query** for fetching and caching API data.
- **Motion** for animations: sliding filter pill, staggered rows, growing bars, counting
  scores, page transitions. All motion respects the system "reduce motion" setting.

```bash
npm install
npm run dev      # http://127.0.0.1:5173, proxies /api to Symfony on :8010
npm run check    # TypeScript, lint (oxlint) and unit tests (Vitest)
npm run build    # production build in dist/
npm run e2e      # browser checks (needs both servers and Chrome; CHROME_PATH to override)
```

The Symfony server must run for data: `php -S 127.0.0.1:8010 -t public` from the repo root.

Structure: `src/api/` (types mirroring `src/Api/ApiPresenter.php`, fetch client),
`src/components/`, `src/pages/`, `src/styles/global.css` (design tokens, dark and light).
