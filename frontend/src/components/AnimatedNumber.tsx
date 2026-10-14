import { animate, useReducedMotion } from 'motion/react'
import { useEffect, useRef } from 'react'

/** Counts up to a value once, e.g. a score appearing on a profile. */
export function AnimatedNumber({ value, decimals = 1, duration = 1.1 }: { value: number; decimals?: number; duration?: number }) {
  const ref = useRef<HTMLSpanElement>(null)
  const reduced = useReducedMotion()

  useEffect(() => {
    const node = ref.current
    if (!node) return
    if (reduced) {
      node.textContent = value.toFixed(decimals)
      return
    }
    const controls = animate(0, value, {
      duration,
      ease: [0.22, 1, 0.36, 1],
      onUpdate: (v) => {
        node.textContent = v.toFixed(decimals)
      },
    })
    return () => controls.stop()
  }, [value, decimals, duration, reduced])

  return <span ref={ref}>{value.toFixed(decimals)}</span>
}
