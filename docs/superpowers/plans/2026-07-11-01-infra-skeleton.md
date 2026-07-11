# Nestegg Plan 1/4: Infrastructure Skeleton Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A containerized Symfony 7.4 JSON API + Vue 3 SPA + Postgres 16 stack that boots with `make up`, serves a tested `/api/health` endpoint through both FrankenPHP and the Vite proxy, and is pushed to a public GitHub repo.

**Architecture:** Monorepo with `backend/` (Symfony, pure JSON API) and `frontend/` (Vue 3 + Vite SPA). Three compose services: `php` (FrankenPHP), `frontend` (node dev server with HMR, proxying `/api` to `php`), `db` (Postgres 16, named volume). Makefile wraps all workflows. Scaffolding runs on the host (PHP 8.5.7 / Node 26 available) but everything executes in containers thereafter.

**Tech Stack:** PHP 8.5, Symfony 7.4 LTS, FrankenPHP 1.x, Vue 3 + TypeScript + Vite + Pinia + Vue Router + Vitest, Postgres 16, Docker Compose, GNU Make.

## Global Constraints

- Symfony version: `7.4.*` (LTS). PHP: 8.5 (matches host 8.5.7 so host-installed `vendor/` works in-container).
- Postgres: `16-alpine` image, database/user/password all `nestegg` (dev-only credentials).
- FrankenPHP image: `dunglas/frankenphp:1-php8.5`. If that tag doesn't exist at build time, fall back to `dunglas/frankenphp:php8.5` — do NOT drop to php8.4 (composer platform checks would fail).
- Node container image: `node:24-alpine`.
- Ports: PHP API on host `8000` (→ container 80), Vite on `5173`, Postgres on host `5433` (→ container 5432; host 5432 is occupied by another local project).
- Frontend is TypeScript. No financial math ever goes in the frontend (ADR-0001).
- All git commits on `main`. Commit messages end with the Co-Authored-By/Claude-Session trailer used in the repo's first commit.
- GitHub account: `IsaacLSaunders`, repo `nestegg`, public, push via SSH (`gh` already authenticated).

---

### Task 1: Symfony backend scaffold with tested health endpoint

**Files:**
- Create: `backend/` (via `composer create-project`)
- Create: `backend/src/Controller/HealthController.php`
- Create: `backend/tests/Controller/HealthControllerTest.php`
- Modify: `backend/.env` (DATABASE_URL)

**Interfaces:**
- Consumes: nothing (first code task)
- Produces: Symfony app rooted at `backend/`, route `GET /api/health` → `200 {"status":"ok"}`, PHPUnit runnable via `php bin/phpunit`. Later tasks add controllers under `App\Controller` and tests under `backend/tests/`.

- [ ] **Step 1: Scaffold Symfony and install packages**

```bash
cd /Users/isaacsaunders/workspace/nestegg
composer create-project symfony/skeleton:"7.4.*" backend --no-interaction
cd backend
composer require symfony/orm-pack symfony/security-bundle symfony/serializer-pack symfony/validator --no-interaction
composer require --dev symfony/test-pack symfony/maker-bundle doctrine/doctrine-fixtures-bundle --no-interaction
```

Expected: project created, packs installed without error. If Flex asks about Docker configuration, answer no (`--no-interaction` handles it).

- [ ] **Step 2: Point DATABASE_URL at the compose db service**

In `backend/.env`, replace the generated `DATABASE_URL` line with:

```dotenv
DATABASE_URL="postgresql://nestegg:nestegg@db:5432/nestegg?serverVersion=16&charset=utf8"
```

(Host-side test runs don't touch the DB in this plan; the URL only needs to be right in-container.)

- [ ] **Step 3: Write the failing health-endpoint test**

Create `backend/tests/Controller/HealthControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthReturnsOk(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['status' => 'ok'], json_decode($client->getResponse()->getContent(), true));
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/HealthControllerTest.php`
Expected: FAIL — response status 404 (route does not exist).

- [ ] **Step 5: Implement the health controller**

Create `backend/src/Controller/HealthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd backend && php bin/phpunit`
Expected: PASS (1 test, 3 assertions), no other tests broken.

- [ ] **Step 7: Commit**

```bash
cd /Users/isaacsaunders/workspace/nestegg
git add backend
git commit -m "feat: scaffold Symfony 7.4 backend with tested /api/health endpoint"
```

---

### Task 2: Vue 3 frontend scaffold with API proxy

**Files:**
- Create: `frontend/` (via `create-vue`)
- Modify: `frontend/vite.config.ts`

**Interfaces:**
- Consumes: backend route `GET /api/health` (Task 1) — used only to verify the proxy in Task 4.
- Produces: Vue 3 + TS app rooted at `frontend/`; dev server on port 5173 proxying `/api/*` to `$VITE_API_PROXY_TARGET` (default `http://localhost:8000`). Later tasks add views under `frontend/src/views/` and Pinia stores under `frontend/src/stores/`.

- [ ] **Step 1: Scaffold the Vue app**

```bash
cd /Users/isaacsaunders/workspace/nestegg
npm create vue@latest frontend -- --ts --router --pinia --vitest --eslint --prettier
cd frontend && npm install
```

Expected: project created with TypeScript, Router, Pinia, Vitest, ESLint, Prettier; `npm install` completes.

- [ ] **Step 2: Configure the dev-server proxy**

Edit `frontend/vite.config.ts` — add a `server` block to the exported config (keep existing plugins/resolve untouched):

```ts
export default defineConfig({
  // ...existing plugins/resolve config stays as generated...
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: false,
      },
    },
  },
})
```

- [ ] **Step 3: Verify unit tests and build pass**

Run: `cd frontend && npm run test:unit -- --run && npm run build`
Expected: the generated example test passes; `vite build` completes into `dist/`.

- [ ] **Step 4: Commit**

```bash
cd /Users/isaacsaunders/workspace/nestegg
git add frontend
git commit -m "feat: scaffold Vue 3 + TS frontend with /api dev proxy"
```

---

### Task 3: Docker Compose stack and Makefile

**Files:**
- Create: `docker/php/Dockerfile`
- Create: `compose.yaml`
- Create: `Makefile`
- Create: `.gitignore` (root)

**Interfaces:**
- Consumes: `backend/` (Task 1), `frontend/` (Task 2).
- Produces: services named `php`, `frontend`, `db`; Make targets `up down logs ps shell front-shell db-shell test test-front migrate migration fixtures`. Later plans run everything through these targets.

- [ ] **Step 1: Write the FrankenPHP Dockerfile**

Create `docker/php/Dockerfile`:

```dockerfile
FROM dunglas/frankenphp:1-php8.5

RUN install-php-extensions pdo_pgsql intl zip opcache apcu

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
```

- [ ] **Step 2: Write compose.yaml**

Create `compose.yaml` at the repo root:

```yaml
services:
  php:
    build: docker/php
    ports:
      - "8000:80"
    environment:
      SERVER_NAME: ":80"
      DATABASE_URL: "postgresql://nestegg:nestegg@db:5432/nestegg?serverVersion=16&charset=utf8"
    volumes:
      - ./backend:/app
    depends_on:
      db:
        condition: service_healthy

  frontend:
    image: node:24-alpine
    working_dir: /app
    command: sh -c "npm install && npm run dev -- --host"
    environment:
      VITE_API_PROXY_TARGET: "http://php:80"
    ports:
      - "5173:5173"
    volumes:
      - ./frontend:/app

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: nestegg
      POSTGRES_USER: nestegg
      POSTGRES_PASSWORD: nestegg
    ports:
      - "5433:5432"
    volumes:
      - dbdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U nestegg"]
      interval: 5s
      timeout: 3s
      retries: 10

volumes:
  dbdata:
```

- [ ] **Step 3: Write the Makefile**

Create `Makefile` at the repo root (recipe lines are TABs, not spaces):

```makefile
COMPOSE = docker compose

.PHONY: up down build logs ps shell front-shell db-shell test test-front migrate migration fixtures

up: ## Build and start the full stack
	$(COMPOSE) up -d --build

down: ## Stop the stack
	$(COMPOSE) down

build: ## Rebuild images
	$(COMPOSE) build

logs: ## Tail all service logs
	$(COMPOSE) logs -f

ps: ## Show service status
	$(COMPOSE) ps

shell: ## Bash into the PHP container
	$(COMPOSE) exec php bash

front-shell: ## Shell into the frontend container
	$(COMPOSE) exec frontend sh

db-shell: ## psql into the database
	$(COMPOSE) exec db psql -U nestegg nestegg

test: ## Run backend test suite
	$(COMPOSE) exec php php bin/phpunit

test-front: ## Run frontend unit tests
	$(COMPOSE) exec frontend npm run test:unit -- --run

migrate: ## Run doctrine migrations
	$(COMPOSE) exec php php bin/console doctrine:migrations:migrate --no-interaction

migration: ## Generate a migration from entity diff
	$(COMPOSE) exec php php bin/console make:migration

fixtures: ## Load dev fixtures
	$(COMPOSE) exec php php bin/console doctrine:fixtures:load --no-interaction
```

- [ ] **Step 4: Write the root .gitignore**

Create `.gitignore`:

```gitignore
.DS_Store
*.log
```

(Symfony and create-vue ship their own `.gitignore` files inside `backend/` and `frontend/`.)

- [ ] **Step 5: Commit**

```bash
cd /Users/isaacsaunders/workspace/nestegg
git add docker compose.yaml Makefile .gitignore
git commit -m "feat: add FrankenPHP/Vite/Postgres compose stack and Makefile"
```

---

### Task 4: Boot the stack and verify end-to-end

**Files:**
- Create: `README.md`

**Interfaces:**
- Consumes: everything above.
- Produces: a verified-running stack; README documenting the `make` workflow for every later plan.

- [ ] **Step 1: Start the stack**

Run: `cd /Users/isaacsaunders/workspace/nestegg && make up`
Expected: three services build/pull and start; `make ps` shows `php`, `frontend`, `db` all `Up` (db `healthy`). If the `dunglas/frankenphp:1-php8.5` tag fails to pull, switch the Dockerfile to `dunglas/frankenphp:php8.5` and re-run.

- [ ] **Step 2: Verify the API through FrankenPHP**

Run: `curl -s http://localhost:8000/api/health`
Expected: `{"status":"ok"}`

- [ ] **Step 3: Verify the API through the Vite proxy**

Run: `curl -s http://localhost:5173/api/health` (retry for up to ~60s while the frontend container finishes `npm install`)
Expected: `{"status":"ok"}` — proves frontend→php container networking.

- [ ] **Step 4: Verify tests run in-container**

Run: `make test`
Expected: PHPUnit passes (health test) inside the php container.

- [ ] **Step 5: Write the README**

Create `README.md`:

```markdown
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
```

- [ ] **Step 6: Commit**

```bash
cd /Users/isaacsaunders/workspace/nestegg
git add README.md
git commit -m "docs: add README with make workflow"
```

---

### Task 5: Create public GitHub repo and push

**Files:** none (remote operation)

**Interfaces:**
- Consumes: local `main` branch with all prior commits.
- Produces: `git@github.com:IsaacLSaunders/nestegg.git` as `origin`; all later plans push here.

- [ ] **Step 1: Create the repo and push**

```bash
cd /Users/isaacsaunders/workspace/nestegg
gh repo create nestegg --public --source=. --push \
  --description "Savings/investing planning: project portfolios forward, goal-seek contributions backwards. Symfony + Vue + Postgres."
```

Expected: repo created under IsaacLSaunders, `main` pushed, `origin` remote set.

- [ ] **Step 2: Verify**

Run: `git remote -v && gh repo view --json url -q .url`
Expected: SSH origin pointing at IsaacLSaunders/nestegg; URL prints.
