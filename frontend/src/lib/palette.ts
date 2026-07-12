// Mirrors the CSS custom properties in assets/main.css — ECharts needs concrete
// strings. The series order is CVD-validated; never reorder or cycle it.
//
// Slots 1-6 are the validated palette; further series (e.g. a portfolio with more
// than 6 accounts) render neutral+dashed (INK_FAINT, dashed lineStyle) — never
// cycle hues. See projectionChart.ts's seriesColor/OVERFLOW_DASH_PATTERNS.
export const SERIES = ['#1b7a4e', '#b0521a', '#3f5bd6', '#8c3f9e', '#0b87b4', '#c03434'] as const
export const INK = '#20281f'
export const INK_SOFT = '#5a6355'
export const INK_FAINT = '#98a08f'
export const LINE = '#ddd4bf'
export const PAPER_RAISED = '#fffcf4'
export const DANGER = '#c03434'
export const COPPER = '#b0521a'
