import { useQuery } from '@tanstack/react-query'
import { AnimatePresence, motion } from 'motion/react'
import { useEffect, useId, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { api } from '../api/client'
import { PositionBadge } from './PositionBadge'
import styles from './SearchBox.module.css'

/** Player search with a results list: arrow keys to move, Enter to open, Escape to close. */
export function SearchBox() {
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const navigate = useNavigate()
  const listId = useId()
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), 180)
    return () => window.clearTimeout(timer)
  }, [query])

  useEffect(() => {
    const close = (event: MouseEvent) => {
      if (!box.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const { data } = useQuery({
    queryKey: ['search', debounced],
    queryFn: () => api.search(debounced),
    enabled: debounced.length >= 2,
    placeholderData: (previous) => previous,
  })
  const results = debounced.length >= 2 ? (data?.players ?? []) : []
  // Results belong to the text in the box only after the debounce has caught up.
  const current = debounced === query.trim()
  const expanded = open && results.length > 0

  const go = (id: number) => {
    setOpen(false)
    setQuery('')
    navigate(`/players/${id}`)
  }

  return (
    <div className={styles.box} ref={box}>
      <svg className={styles.icon} viewBox="0 0 20 20" width="16" height="16" aria-hidden="true">
        <path d="M8.5 3a5.5 5.5 0 014.38 8.83l3.65 3.64-1.06 1.06-3.64-3.65A5.5 5.5 0 118.5 3zm0 1.5a4 4 0 100 8 4 4 0 000-8z" fill="currentColor" />
      </svg>
      <input
        type="search"
        placeholder="Search a player"
        aria-label="Search a player"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={expanded}
        aria-controls={expanded ? listId : undefined}
        aria-activedescendant={expanded && results[active] ? `${listId}-${active}` : undefined}
        value={query}
        onChange={(e) => {
          setQuery(e.target.value)
          setActive(0)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') {
            e.preventDefault()
            setActive((i) => Math.max(0, Math.min(i + 1, results.length - 1)))
          } else if (e.key === 'ArrowUp') {
            e.preventDefault()
            setActive((i) => Math.max(i - 1, 0))
          } else if (e.key === 'Enter' && current && results[active]) {
            go(results[active].id)
          } else if (e.key === 'Escape') {
            setOpen(false)
          }
        }}
      />
      <AnimatePresence>
        {expanded && (
          <motion.ul
            id={listId}
            role="listbox"
            className={styles.results}
            initial={{ opacity: 0, y: -6, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -6, scale: 0.98 }}
            transition={{ duration: 0.16 }}
          >
            {results.map((player, i) => (
              <li
                key={player.id}
                id={`${listId}-${i}`}
                role="option"
                aria-selected={i === active}
                className={i === active ? styles.active : undefined}
                onMouseEnter={() => setActive(i)}
                onMouseDown={(e) => {
                  e.preventDefault()
                  go(player.id)
                }}
              >
                <span className={styles.name}>{player.name}</span>
                <span className={styles.team}>{player.team}</span>
                <PositionBadge position={player.position} />
              </li>
            ))}
          </motion.ul>
        )}
      </AnimatePresence>
    </div>
  )
}
