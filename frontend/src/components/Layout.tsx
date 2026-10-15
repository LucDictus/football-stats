import { AnimatePresence, motion } from 'motion/react'
import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { NavLink, useLocation, useNavigationType, useOutlet } from 'react-router'
import { SearchBox } from './SearchBox'
import styles from './Layout.module.css'

const EASE = [0.22, 1, 0.36, 1] as const
const PAGE = {
  hidden: { opacity: 0, y: 12 },
  shown: { opacity: 1, y: 0, transition: { duration: 0.28, ease: EASE } },
  gone: { opacity: 0, y: -8, transition: { duration: 0.28, ease: EASE } },
}

/** Keeps the outgoing page on screen while it animates out. */
function FrozenOutlet() {
  const outlet = useOutlet()
  const [frozen] = useState(outlet)
  return frozen
}

/**
 * Scroll position per history entry: a new page starts at the top, Back/Forward return to
 * where you were (e.g. deep in the ranking).
 *
 * Restoring waits until the new page is on screen: AnimatePresence calls onExitComplete
 * before the incoming page has rendered, and scrolling then would be cut short by the
 * outgoing page's height. Scroll events during a transition are ignored for the same reason.
 */
function useScrollMemory() {
  const location = useLocation()
  const navigationType = useNavigationType()
  const positions = useRef(new Map<string, number>())
  const current = useRef({ key: location.key, pathname: location.pathname, navigationType })
  const transitioning = useRef(false)

  useLayoutEffect(() => {
    // Only a new path animates (AnimatePresence is keyed by path). A same-path navigation,
    // like the position filter, keeps the page and simply saves under the new history key.
    if (current.current.pathname !== location.pathname) transitioning.current = true
    current.current = { key: location.key, pathname: location.pathname, navigationType }
  }, [location.key, location.pathname, navigationType])

  useEffect(() => {
    window.history.scrollRestoration = 'manual'
    const save = () => {
      if (!transitioning.current) positions.current.set(current.current.key, window.scrollY)
    }
    window.addEventListener('scroll', save, { passive: true })
    return () => window.removeEventListener('scroll', save)
  }, [])

  return {
    restore: () => {
      // Two frames: the first lets React render the incoming page, the second runs after layout.
      requestAnimationFrame(() =>
        requestAnimationFrame(() => {
          const { key, navigationType: type } = current.current
          window.scrollTo({ top: type === 'POP' ? (positions.current.get(key) ?? 0) : 0 })
          transitioning.current = false
        }),
      )
    },
    // Safety net: an interrupted exit (A → B → back to A within the animation) never calls
    // onExitComplete; once a page has fully entered, positions are saved again.
    entered: () => {
      transitioning.current = false
    },
  }
}

export function Layout() {
  const location = useLocation()
  const scroll = useScrollMemory()

  return (
    <>
      <header className={styles.header}>
        <div className={styles.inner}>
          <NavLink to="/" className={styles.brand} aria-label="Football MVP Index, home">
            <svg viewBox="0 0 32 32" width="26" height="26" aria-hidden="true">
              <rect width="32" height="32" rx="8" fill="var(--surface-3)" />
              <path d="M16 6l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L16 20.8l-5.9 3.3 1.3-6.6-4.9-4.6 6.6-.8z" fill="var(--accent)" />
            </svg>
            <span>
              Football MVP <em>Index</em>
            </span>
          </NavLink>
          <nav className={styles.nav} aria-label="Main">
            <NavLink to="/" end>
              Ranking
            </NavLink>
            <NavLink to="/compare">Compare</NavLink>
            <NavLink to="/methodology">Methodology</NavLink>
          </nav>
          <SearchBox />
        </div>
      </header>
      <AnimatePresence mode="wait" initial={false} onExitComplete={scroll.restore}>
        <motion.div
          key={location.pathname}
          variants={PAGE}
          initial="hidden"
          animate="shown"
          exit="gone"
          onAnimationComplete={(variant) => variant === 'shown' && scroll.entered()}
        >
          <FrozenOutlet />
        </motion.div>
      </AnimatePresence>
    </>
  )
}
