export interface User {
  id: number
  email: string
  birthDate: string
  deathAge: number
}

export interface RegisterInput {
  email: string
  password: string
  birthDate: string
  deathAge?: number
}

export type AccountType =
  | 'traditional_401k'
  | 'roth_401k'
  | 'traditional_ira'
  | 'roth_ira'
  | 'brokerage'
  | 'plan_529'
  | 'cash'

export type DrawdownFrequency = 'weekly' | 'monthly'
export type DrawdownEntryMode = 'gross' | 'net'

export interface ContributionInput {
  monthlyAmount: number
  escalationRate: number
  startsOn: string | null
  endsOn: string | null
}

export interface DrawdownInput {
  amount: number | null
  frequency: DrawdownFrequency
  entryMode: DrawdownEntryMode
  startsOn: string | null
  endsOn: string | null
  inflationIndexed: boolean
}

export interface AccountInput {
  name: string
  type: AccountType
  startingBalance: number
  startingBasis: number | null
  annualReturnRate: number
  inflationRate: number
  horizonYears: number
  contribution: ContributionInput
  drawdown: DrawdownInput
}

export interface Account extends AccountInput {
  id: number
  portfolioId: number
}

export interface PortfolioInput {
  name: string
  ordinaryIncomeTaxRate: number
  capitalGainsTaxRate: number
}

export interface Portfolio extends PortfolioInput {
  id: number
  accounts: Account[]
}

export interface ProjectionMonth {
  index: number
  date: string
  balance: number
  realBalance: number
  basis: number
  contribution: number
  realContribution: number
  grossWithdrawal: number
  realGrossWithdrawal: number
  netWithdrawal: number
  realNetWithdrawal: number
  taxPaid: number
  realTaxPaid: number
}

export interface ProjectionSummary {
  endingBalance: number
  endingRealBalance: number
  depletionDate: string | null
  totalContributions: number
  totalGrossWithdrawals: number
  totalNetWithdrawals: number
  totalTaxPaid: number
}

export interface ProjectionResponse {
  months: ProjectionMonth[]
  summary: ProjectionSummary
}

export interface GoalSeekResponse {
  attainable: boolean
  requiredMonthlyContribution: number
  requiredYearlyContribution: number
  projection: ProjectionResponse
}

export interface TaxesInput {
  ordinaryIncomeTaxRate: number
  capitalGainsTaxRate: number
}

export interface ProjectionRequest {
  account: AccountInput
  taxes: TaxesInput
  birthDate: string | null
  deathAge: number | null
  startsOn: string | null
}

export interface GoalInput {
  kind: 'drawdown' | 'target_value'
  amount?: number
  atDate?: string
  amountInTodaysDollars?: boolean
}

export interface GoalSeekRequest extends ProjectionRequest {
  goal: GoalInput
}

export interface PortfolioProjectionRequest {
  accounts: AccountInput[]
  taxes: TaxesInput
  birthDate: string | null
  deathAge: number | null
  startsOn: string | null
}

export interface PortfolioTotalMonth {
  index: number
  date: string
  balance: number
  realBalance: number
}

export interface PortfolioProjectionResponse {
  accounts: ({ name: string } & ProjectionResponse)[]
  total: { months: PortfolioTotalMonth[]; horizonMonths: number }
}
