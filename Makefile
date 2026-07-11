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
