export const HOURS_PER_DAY = 8 as const

export const hoursToDay = (hours: number): number =>
  Math.round((hours / HOURS_PER_DAY) * 100) / 100

export const daysToHours = (days: number): number => days * HOURS_PER_DAY

export const formatDays = (hours: number): string => `${hoursToDay(hours)}d`
