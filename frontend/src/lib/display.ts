import { useEffect, useState } from 'react'

export type FontSize = 'normal' | 'large' | 'larger'

const FONT_SIZE_KEY = 'lms.fontSize'
const CONTRAST_KEY = 'lms.highContrast'

function readFontSize(): FontSize {
  try {
    const v = localStorage.getItem(FONT_SIZE_KEY)
    return v === 'large' || v === 'larger' ? v : 'normal'
  } catch {
    return 'normal'
  }
}

function readContrast(): boolean {
  try {
    return localStorage.getItem(CONTRAST_KEY) === '1'
  } catch {
    return false
  }
}

/** Font size and contrast, a per-device accessibility preference remembered in local storage and applied to <html>. */
export function useDisplayPrefs() {
  const [fontSize, setFontSizeState] = useState<FontSize>(readFontSize)
  const [highContrast, setHighContrastState] = useState<boolean>(readContrast)

  useEffect(() => {
    document.documentElement.setAttribute('data-font-size', fontSize)
  }, [fontSize])
  useEffect(() => {
    if (highContrast) document.documentElement.setAttribute('data-contrast', 'high')
    else document.documentElement.removeAttribute('data-contrast')
  }, [highContrast])

  const setFontSize = (value: FontSize) => {
    setFontSizeState(value)
    try {
      localStorage.setItem(FONT_SIZE_KEY, value)
    } catch {
      /* a private window or blocked storage just won't remember the choice */
    }
  }
  const setHighContrast = (value: boolean) => {
    setHighContrastState(value)
    try {
      localStorage.setItem(CONTRAST_KEY, value ? '1' : '0')
    } catch {
      /* a private window or blocked storage just won't remember the choice */
    }
  }

  return { fontSize, setFontSize, highContrast, setHighContrast }
}
