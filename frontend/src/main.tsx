import '@fontsource-variable/inter'
import '@fontsource-variable/space-grotesk'
import './styles/global.css'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MotionConfig } from 'motion/react'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './App'

const queryClient = new QueryClient({
  defaultOptions: {
    // Rankings change only when a new score run is made; no need to refetch on focus.
    queries: { staleTime: 5 * 60_000, refetchOnWindowFocus: false, retry: 1 },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      {/* Honour the system "reduce motion" setting for every animation. */}
      <MotionConfig reducedMotion="user">
        <App />
      </MotionConfig>
    </QueryClientProvider>
  </StrictMode>,
)
