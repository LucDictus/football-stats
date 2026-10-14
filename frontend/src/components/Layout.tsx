import { AnimatePresence, motion } from 'motion/react'
import { useState } from 'react'
import { NavLink, useLocation, useOutlet } from 'react-router'
import { SearchBox } from './SearchBox'
import styles from './Layout.module.css'

/** Keeps the outgoing page on screen while it animates out. */
function FrozenOutlet() {
  const outlet = useOutlet()
  const [frozen] = useState(outlet)
  return frozen
}

export function Layout() {
  const location = useLocation()

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
            <NavLink to="/methodology">Methodology</NavLink>
          </nav>
          <SearchBox />
        </div>
      </header>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div
          key={location.pathname}
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -8 }}
          transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
          onAnimationStart={() => window.scrollTo({ top: 0 })}
        >
          <FrozenOutlet />
        </motion.div>
      </AnimatePresence>
    </>
  )
}
