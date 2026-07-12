const moneyFmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })
const compactFmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1 })
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

export function money(n: number): string {
  return moneyFmt.format(n)
}

export function moneyCompact(n: number): string {
  return compactFmt.format(n)
}

export function monthLabel(date: string): string {
  const [year, month] = date.split('-')
  return `${MONTHS[Number(month) - 1]} ${year}`
}

export function ageAt(date: string, birthDate: string | null): number | null {
  if (!birthDate) return null
  return Number(date.slice(0, 4)) - Number(birthDate.slice(0, 4))
}
