# Nestegg

A savings/investing planning app: model financial paths as portfolios of
accounts, project growth forward, and goal-seek required contributions
backwards. See [docs/SPEC.md](docs/SPEC.md) for the v1 spec and
[CONTEXT.md](CONTEXT.md) for domain vocabulary.

## Stack

Symfony 7.4 JSON API (FrankenPHP) · Vue 3 + TypeScript (Vite) · Postgres 16 —
fully containerized with Docker Compose.

## Getting started

```bash
make up        # build and start everything
```

- App (Vite dev server, HMR): http://localhost:5173
- API (FrankenPHP): http://localhost:8000/api/health
- Postgres (host tools/GUIs): localhost:5433, db/user/password `nestegg`

## Common commands

| Command | What it does |
|---|---|
| `make up` / `make down` | start / stop the stack |
| `make logs` | tail all service logs |
| `make shell` | bash into the PHP container |
| `make db-shell` | psql into Postgres |
| `make test` | backend PHPUnit suite |
| `make test-front` | frontend Vitest suite |
| `make migrate` | run Doctrine migrations |
| `make fixtures` | load dev fixtures |
