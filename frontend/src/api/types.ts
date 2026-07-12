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
