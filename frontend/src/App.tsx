import { createBrowserRouter, RouterProvider } from 'react-router'
import { Layout } from './components/Layout'
import { NotFoundPage } from './pages/NotFoundPage'
import { RankingPage } from './pages/RankingPage'

// The ranking is the landing page; other pages load when first visited.
const router = createBrowserRouter([
  {
    element: <Layout />,
    children: [
      { index: true, element: <RankingPage /> },
      { path: 'players/:id', lazy: async () => ({ Component: (await import('./pages/PlayerPage')).PlayerPage }) },
      { path: 'compare', lazy: async () => ({ Component: (await import('./pages/ComparePage')).ComparePage }) },
      { path: 'methodology', lazy: async () => ({ Component: (await import('./pages/MethodologyPage')).MethodologyPage }) },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])

export function App() {
  return <RouterProvider router={router} />
}
