# Nestegg Plan 2/4: Domain Model, Auth, and CRUD API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Users can register, log in (session cookie), and CRUD their portfolios and accounts through a validated, ownership-enforced JSON API backed by Postgres — with functional tests for every endpoint.

**Architecture:** Doctrine entities `User → Portfolio → Account` mirror the SPEC hierarchy. Symfony `json_login` on the existing same-origin setup (no CORS bundle — the Vite proxy makes the SPA same-origin; final review of Plan 1 confirmed this). Controllers take `#[MapRequestPayload]` DTOs for validation; ownership is enforced by loading rows through owner-scoped repository queries (404 on other users' data). Functional tests run in-container against a migrated `nestegg_test` DB with per-test transaction rollback via `dama/doctrine-test-bundle`.

**Tech Stack:** Symfony 7.4, Doctrine ORM 3, PHP 8.5 backed enums, PHPUnit + dama/doctrine-test-bundle, Postgres 16.

## Global Constraints

- The stack from Plan 1 is running (`make up`). Anything that needs the DB runs **in-container**: `docker compose exec php <cmd>` or the make targets. Host-side `php`/`composer` are fine for dependency installs and DB-less commands.
- All rates are stored and transmitted as **decimal fractions** (`0.07` = 7%), never percent numbers. Applies to tax rates, ROI, inflation, escalation.
- Monetary amounts are `float` (DOUBLE PRECISION) — this is a planning tool, not accounting.
- All date fields are `DATE` columns mapped to `\DateTimeImmutable` (`Types::DATE_IMMUTABLE`), JSON format `YYYY-MM-DD`.
- Account types (backed enum values): `traditional_401k`, `roth_401k`, `traditional_ira`, `roth_ira`, `brokerage`, `plan_529`, `cash`. Drawdown frequency: `weekly`, `monthly`. Drawdown entry mode: `gross`, `net`.
- API error contract: validation failures return 422 (MapRequestPayload default); unauthenticated API requests return 401 JSON (not a redirect); rows owned by another user return 404 (never 403 — don't leak existence).
- Never expose `password` in any response. User JSON shape: `{"id","email","birthDate","deathAge"}`.
- Commit messages end with the repo's Co-Authored-By/Claude-Session trailer (see `git log`).
- Symfony maker commands are allowed for boilerplate, but the final code must match what each task specifies.

---

### Task 1: Test infrastructure and Plan-1 review fixes

**Files:**
- Modify: `backend/composer.json` (php version), via composer: add `dama/doctrine-test-bundle`
- Modify: `backend/phpunit.dist.xml` (dama extension)
- Modify: `Makefile` (test target bootstraps the test DB)
- Modify: `README.md` (Postgres port note)

**Interfaces:**
- Consumes: Plan 1 stack (services `php`, `db`; make targets).
- Produces: `make test` = create+migrate `nestegg_test` then run PHPUnit with per-test transaction rollback. Every later task's functional tests rely on this.

- [ ] **Step 1: Tighten the PHP requirement**

In `backend/composer.json`, change `"php": ">=8.2"` to `"php": ">=8.5"`.

- [ ] **Step 2: Install dama/doctrine-test-bundle**

```bash
cd /Users/isaacsaunders/workspace/nestegg/backend
composer require --dev dama/doctrine-test-bundle --no-interaction
```

Expected: installed; Flex registers the bundle for `test` in `config/bundles.php`.

- [ ] **Step 3: Register the PHPUnit extension**

In `backend/phpunit.dist.xml`, add inside `<phpunit>` (sibling of `<testsuites>`):

```xml
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

- [ ] **Step 4: Make `make test` bootstrap the test database**

In `Makefile`, replace the `test:` recipe with:

```makefile
test: ## Run backend test suite (bootstraps + migrates the test DB)
	$(COMPOSE) exec php php bin/console -e test doctrine:database:create --if-not-exists
	$(COMPOSE) exec php php bin/console -e test doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(COMPOSE) exec php php bin/phpunit
```

- [ ] **Step 5: Document the Postgres host port**

In `README.md`, add to the bullet list under "Getting started":

```markdown
- Postgres (host tools/GUIs): localhost:5433, db/user/password `nestegg`
```

- [ ] **Step 6: Verify**

Run: `cd /Users/isaacsaunders/workspace/nestegg && make test`
Expected: `nestegg_test` created, "no migrations" tolerated, existing health test passes with pristine output.

- [ ] **Step 7: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/phpunit.dist.xml backend/config/bundles.php backend/symfony.lock Makefile README.md
git commit -m "chore: test-DB bootstrap, dama rollback isolation, php>=8.5, README port note"
```

---

### Task 2: User entity and registration endpoint

**Files:**
- Create: `backend/src/Entity/User.php`
- Create: `backend/src/Repository/UserRepository.php`
- Create: `backend/src/Dto/RegisterRequest.php`
- Create: `backend/src/Controller/AuthController.php`
- Modify: `backend/config/packages/security.yaml` (password hasher + provider)
- Create: migration (generated)
- Test: `backend/tests/Controller/RegistrationTest.php`

**Interfaces:**
- Consumes: `make test` bootstrap (Task 1).
- Produces: `App\Entity\User` (`getId(): ?int`, `getEmail(): string`, `getBirthDate(): \DateTimeImmutable`, `getDeathAge(): int`, `getUserIdentifier(): string`); `POST /api/auth/register` accepting `{"email","password","birthDate","deathAge"?}` → 201 with user JSON. Task 3 wires login against this entity/provider; Task 4 references `User` as portfolio owner.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/RegistrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationTest extends WebTestCase
{
    public function testRegisterCreatesUser(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'isaac@example.com',
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
            'deathAge' => 92,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('isaac@example.com', $data['email']);
        self::assertSame('1990-06-15', $data['birthDate']);
        self::assertSame(92, $data['deathAge']);
        self::assertArrayHasKey('id', $data);
        self::assertArrayNotHasKey('password', $data);
    }

    public function testDeathAgeDefaultsTo90(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'default@example.com',
            'password' => 'correct horse battery staple',
            'birthDate' => '1985-01-01',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(90, $data['deathAge']);
    }

    public function testDuplicateEmailRejected(): void
    {
        $client = self::createClient();
        $payload = [
            'email' => 'dupe@example.com',
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
        ];
        $client->jsonRequest('POST', '/api/auth/register', $payload);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/auth/register', $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testInvalidPayloadRejected(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'not-an-email',
            'password' => 'short',
            'birthDate' => '1990-06-15',
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test` (from repo root)
Expected: FAIL — 404 on `/api/auth/register`.

- [ ] **Step 3: Create the User entity and repository**

Create `backend/src/Entity/User.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $birthDate;

    #[ORM\Column(options: ['default' => 90])]
    private int $deathAge = 90;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getBirthDate(): \DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function getDeathAge(): int
    {
        return $this->deathAge;
    }

    public function setDeathAge(int $deathAge): static
    {
        $this->deathAge = $deathAge;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    /** @return array{id: int|null, email: string, birthDate: string, deathAge: int} */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'birthDate' => $this->birthDate->format('Y-m-d'),
            'deathAge' => $this->deathAge,
        ];
    }
}
```

Create `backend/src/Repository/UserRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }
}
```

- [ ] **Step 4: Create the DTO**

Create `backend/src/Dto/RegisterRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 10, max: 4096)]
        public string $password,
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $birthDate,
        #[Assert\Range(min: 1, max: 120)]
        public int $deathAge = 90,
    ) {
    }
}
```

- [ ] **Step 5: Configure hasher/provider and write the controller**

In `backend/config/packages/security.yaml`, ensure these keys (merge into the generated file, keeping the `when@test` hasher block Flex created):

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email
```

Create `backend/src/Controller/AuthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        if (null !== $users->findOneBy(['email' => $request->email])) {
            return $this->json(['error' => 'Email already registered.'], 409);
        }

        $user = new User();
        $user->setEmail($request->email)
            ->setBirthDate(new \DateTimeImmutable($request->birthDate))
            ->setDeathAge($request->deathAge)
            ->setPassword($hasher->hashPassword($user, $request->password));

        $em->persist($user);
        $em->flush();

        return $this->json($user->toJson(), 201);
    }
}
```

- [ ] **Step 6: Generate and review the migration**

```bash
make migration   # runs make:migration in the php container
make migrate     # applies it to the dev DB
```

Review the generated file under `backend/migrations/` — it must create only the `user` table with the unique email constraint. Delete any empty/spurious statements.

- [ ] **Step 7: Run tests to verify they pass**

Run: `make test`
Expected: all 5 tests pass (health + 4 registration), pristine output.

- [ ] **Step 8: Commit**

```bash
git add backend/src backend/tests backend/migrations backend/config
git commit -m "feat: User entity and registration endpoint"
```

---

### Task 3: Session login, logout, current-user endpoints

**Files:**
- Modify: `backend/config/packages/security.yaml` (json_login, logout, access_control, 401 entry point)
- Modify: `backend/src/Controller/AuthController.php` (me / updateMe)
- Create: `backend/src/Dto/UpdateMeRequest.php`
- Create: `backend/src/Security/JsonAuthenticationEntryPoint.php`
- Test: `backend/tests/Controller/AuthenticationTest.php`

**Interfaces:**
- Consumes: `User`, provider, `POST /api/auth/register` (Task 2).
- Produces: `POST /api/auth/login` `{"email","password"}` → 200; `POST /api/auth/logout` → 200; `GET /api/me` → user JSON; `PATCH /api/me` `{"birthDate"?,"deathAge"?}` → user JSON. Everything under `/api` except `health` and `auth/(register|login)` requires auth and 401s as JSON. Tasks 4-6 tests log in via these endpoints.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/AuthenticationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    private function register(KernelBrowser $client, string $email = 'auth@example.com'): void
    {
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => $email,
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testLoginThenMe(): void
    {
        $client = self::createClient();
        $this->register($client);

        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'auth@example.com',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('auth@example.com', $data['email']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $client = self::createClient();
        $this->register($client, 'wrongpw@example.com');

        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'wrongpw@example.com',
            'password' => 'incorrect password entirely',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testMeWithoutSessionIs401Json(): void
    {
        $client = self::createClient();
        $client->jsonRequest('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testLogoutEndsSession(): void
    {
        $client = self::createClient();
        $this->register($client, 'logout@example.com');
        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/api/auth/logout');
        self::assertResponseIsSuccessful();

        $client->jsonRequest('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testPatchMeUpdatesPlanningFields(): void
    {
        $client = self::createClient();
        $this->register($client, 'patch@example.com');
        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => 'patch@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $client->jsonRequest('PATCH', '/api/me', ['deathAge' => 100, 'birthDate' => '1991-01-01']);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(100, $data['deathAge']);
        self::assertSame('1991-01-01', $data['birthDate']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test`
Expected: FAIL — login route 404 / me 404.

- [ ] **Step 3: Create the JSON 401 entry point**

Create `backend/src/Security/JsonAuthenticationEntryPoint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class JsonAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'Authentication required.'], 401);
    }
}
```

- [ ] **Step 4: Configure the firewall**

In `backend/config/packages/security.yaml`, configure the `main` firewall and access control (replace the generated `main` firewall body; keep `dev` firewall and `when@test` blocks):

```yaml
    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false
        main:
            lazy: true
            provider: app_user_provider
            entry_point: App\Security\JsonAuthenticationEntryPoint
            json_login:
                check_path: api_auth_login
                username_path: email
                password_path: password
            logout:
                path: api_auth_logout

    access_control:
        - { path: ^/api/health$, roles: PUBLIC_ACCESS }
        - { path: ^/api/auth/(register|login)$, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: ROLE_USER }
```

- [ ] **Step 5: Add route stubs and me endpoints**

In `backend/src/Controller/AuthController.php`, add inside the class:

```php
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        // Handled by the json_login authenticator; only reached on success.
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($user->toJson());
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the firewall.');
    }
```

Add a new DTO `backend/src/Dto/UpdateMeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateMeRequest
{
    public function __construct(
        #[Assert\Date]
        public ?string $birthDate = null,
        #[Assert\Range(min: 1, max: 120)]
        public ?int $deathAge = null,
    ) {
    }
}
```

Create `backend/src/Controller/MeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UpdateMeRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
final class MeController extends AbstractController
{
    #[Route('', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($user->toJson());
    }

    #[Route('', name: 'api_me_update', methods: ['PATCH'])]
    public function update(
        #[MapRequestPayload] UpdateMeRequest $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $request->birthDate) {
            $user->setBirthDate(new \DateTimeImmutable($request->birthDate));
        }
        if (null !== $request->deathAge) {
            $user->setDeathAge($request->deathAge);
        }
        $em->flush();

        return $this->json($user->toJson());
    }
}
```

Symfony's logout returns a 302 redirect by default, but the test asserts a 2xx. Set the logout response with an event listener — create `backend/src/EventListener/LogoutListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class)]
final class LogoutListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['status' => 'logged out']));
    }
}
```

Keep `logout: { path: api_auth_logout }` in security.yaml (no `target`).

- [ ] **Step 6: Run tests to verify they pass**

Run: `make test`
Expected: all tests pass (health + registration + 5 auth), pristine output.

- [ ] **Step 7: Commit**

```bash
git add backend/src backend/tests backend/config
git commit -m "feat: session login/logout and /api/me endpoints"
```

---

### Task 4: Portfolio entity and CRUD endpoints

**Files:**
- Create: `backend/src/Entity/Portfolio.php`
- Create: `backend/src/Repository/PortfolioRepository.php`
- Create: `backend/src/Dto/PortfolioInput.php`
- Create: `backend/src/Controller/PortfolioController.php`
- Create: migration (generated)
- Test: `backend/tests/Controller/PortfolioTest.php`
- Create: `backend/tests/ApiTestCase.php` (shared register+login helper)

**Interfaces:**
- Consumes: auth endpoints (Task 3), `User` (Task 2).
- Produces: `App\Entity\Portfolio` (`getId()`, `getName(): string`, `getOrdinaryIncomeTaxRate(): float`, `getCapitalGainsTaxRate(): float`, `getOwner(): User`, `getAccounts(): Collection`, `toJson(): array`); `PortfolioRepository::findOwnedBy(User $owner): array` and `findOneOwnedBy(int $id, User $owner): ?Portfolio`; routes `GET|POST /api/portfolios`, `GET|PUT|DELETE /api/portfolios/{id}`. Portfolio JSON: `{"id","name","ordinaryIncomeTaxRate","capitalGainsTaxRate","accounts":[...]}`. Task 5 nests accounts; Task 6 duplicates.

- [ ] **Step 1: Write the shared test base**

Create `backend/tests/ApiTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected function createAuthenticatedClient(string $email = 'owner@example.com'): KernelBrowser
    {
        self::ensureKernelShutdown(); // allows multi-client tests (e.g. alice + bob)
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => $email,
            'password' => 'correct horse battery staple',
            'birthDate' => '1990-06-15',
        ]);
        self::assertResponseStatusCodeSame(201);
        $client->jsonRequest('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        return $client;
    }

    /** @return array<mixed> */
    protected function json(KernelBrowser $client): array
    {
        return json_decode($client->getResponse()->getContent(), true);
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `backend/tests/Controller/PortfolioTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class PortfolioTest extends ApiTestCase
{
    public function testCreateAndListPortfolios(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Aggressive path',
            'ordinaryIncomeTaxRate' => 0.24,
            'capitalGainsTaxRate' => 0.15,
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->json($client);
        self::assertSame('Aggressive path', $created['name']);
        self::assertSame(0.24, $created['ordinaryIncomeTaxRate']);
        self::assertSame([], $created['accounts']);

        $client->jsonRequest('GET', '/api/portfolios');
        self::assertResponseIsSuccessful();
        $list = $this->json($client);
        self::assertCount(1, $list);
        self::assertSame($created['id'], $list[0]['id']);
    }

    public function testUpdatePortfolio(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Before',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $id = $this->json($client)['id'];

        $client->jsonRequest('PUT', "/api/portfolios/{$id}", [
            'name' => 'After',
            'ordinaryIncomeTaxRate' => 0.32,
            'capitalGainsTaxRate' => 0.20,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('After', $this->json($client)['name']);
        self::assertSame(0.32, $this->json($client)['ordinaryIncomeTaxRate']);
    }

    public function testDeletePortfolio(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Doomed',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $id = $this->json($client)['id'];

        $client->jsonRequest('DELETE', "/api/portfolios/{$id}");
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', "/api/portfolios/{$id}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testCannotSeeOthersPortfolios(): void
    {
        $alice = $this->createAuthenticatedClient('alice@example.com');
        $alice->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Alice private',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $aliceId = $this->json($alice)['id'];

        $bob = $this->createAuthenticatedClient('bob@example.com');
        $bob->jsonRequest('GET', "/api/portfolios/{$aliceId}");
        self::assertResponseStatusCodeSame(404);

        $bob->jsonRequest('GET', '/api/portfolios');
        self::assertSame([], $this->json($bob));
    }

    public function testValidationRejectsOutOfRangeRates(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Bad rates',
            'ordinaryIncomeTaxRate' => 24.0,
            'capitalGainsTaxRate' => 0.15,
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
```

Note: `createAuthenticatedClient` calls `self::createClient()` which boots one kernel per test — creating two clients in one test (`testCannotSeeOthersPortfolios`) requires `$alice = ...client...` then Symfony forbids a second `createClient()` after requests. **Implementation requirement:** in `ApiTestCase`, guard by calling `self::ensureKernelShutdown()` before each `self::createClient()` call.

- [ ] **Step 3: Run tests to verify they fail**

Run: `make test`
Expected: FAIL — 404 on `/api/portfolios`.

- [ ] **Step 4: Create entity, repository, DTO, controller**

Create `backend/src/Entity/Portfolio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PortfolioRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortfolioRepository::class)]
class Portfolio
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private float $ordinaryIncomeTaxRate = 0.22;

    #[ORM\Column]
    private float $capitalGainsTaxRate = 0.15;

    /** @var Collection<int, Account> */
    #[ORM\OneToMany(mappedBy: 'portfolio', targetEntity: Account::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $accounts;

    public function __construct()
    {
        $this->accounts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getOrdinaryIncomeTaxRate(): float
    {
        return $this->ordinaryIncomeTaxRate;
    }

    public function setOrdinaryIncomeTaxRate(float $rate): static
    {
        $this->ordinaryIncomeTaxRate = $rate;

        return $this;
    }

    public function getCapitalGainsTaxRate(): float
    {
        return $this->capitalGainsTaxRate;
    }

    public function setCapitalGainsTaxRate(float $rate): static
    {
        $this->capitalGainsTaxRate = $rate;

        return $this;
    }

    /** @return Collection<int, Account> */
    public function getAccounts(): Collection
    {
        return $this->accounts;
    }

    public function addAccount(Account $account): static
    {
        if (!$this->accounts->contains($account)) {
            $this->accounts->add($account);
            $account->setPortfolio($this);
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ordinaryIncomeTaxRate' => $this->ordinaryIncomeTaxRate,
            'capitalGainsTaxRate' => $this->capitalGainsTaxRate,
            'accounts' => array_map(
                static fn (Account $a): array => $a->toJson(),
                $this->accounts->toArray(),
            ),
        ];
    }
}
```

**Note:** `Account` does not exist until Task 5. For THIS task, create the entity WITHOUT the `$accounts` collection, `addAccount`, and with `'accounts' => []` hardcoded in `toJson()`. Task 5 adds the real collection. (This keeps each task shippable; the test asserts `accounts: []` either way.)

Create `backend/src/Repository/PortfolioRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Portfolio;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Portfolio> */
final class PortfolioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Portfolio::class);
    }

    /** @return Portfolio[] */
    public function findOwnedBy(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['id' => 'ASC']);
    }

    public function findOneOwnedBy(int $id, User $owner): ?Portfolio
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }
}
```

Create `backend/src/Dto/PortfolioInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PortfolioInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name,
        #[Assert\Range(min: 0, max: 1)]
        public float $ordinaryIncomeTaxRate = 0.22,
        #[Assert\Range(min: 0, max: 1)]
        public float $capitalGainsTaxRate = 0.15,
    ) {
    }
}
```

Create `backend/src/Controller/PortfolioController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PortfolioInput;
use App\Entity\Portfolio;
use App\Entity\User;
use App\Repository\PortfolioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/portfolios')]
final class PortfolioController extends AbstractController
{
    public function __construct(
        private readonly PortfolioRepository $portfolios,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_portfolios_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(array_map(
            static fn (Portfolio $p): array => $p->toJson(),
            $this->portfolios->findOwnedBy($user),
        ));
    }

    #[Route('', name: 'api_portfolios_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] PortfolioInput $input): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $portfolio = (new Portfolio())
            ->setOwner($user)
            ->setName($input->name)
            ->setOrdinaryIncomeTaxRate($input->ordinaryIncomeTaxRate)
            ->setCapitalGainsTaxRate($input->capitalGainsTaxRate);

        $this->em->persist($portfolio);
        $this->em->flush();

        return $this->json($portfolio->toJson(), 201);
    }

    #[Route('/{id}', name: 'api_portfolios_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->findOwnedOr404($id)->toJson());
    }

    #[Route('/{id}', name: 'api_portfolios_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] PortfolioInput $input): JsonResponse
    {
        $portfolio = $this->findOwnedOr404($id)
            ->setName($input->name)
            ->setOrdinaryIncomeTaxRate($input->ordinaryIncomeTaxRate)
            ->setCapitalGainsTaxRate($input->capitalGainsTaxRate);
        $this->em->flush();

        return $this->json($portfolio->toJson());
    }

    #[Route('/{id}', name: 'api_portfolios_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->em->remove($this->findOwnedOr404($id));
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function findOwnedOr404(int $id): Portfolio
    {
        /** @var User $user */
        $user = $this->getUser();
        $portfolio = $this->portfolios->findOneOwnedBy($id, $user);
        if (null === $portfolio) {
            throw $this->createNotFoundException();
        }

        return $portfolio;
    }
}
```

- [ ] **Step 5: Generate and review the migration**

```bash
make migration
make migrate
```

Review: creates only the `portfolio` table with FK to `user` (ON DELETE CASCADE).

- [ ] **Step 6: Run tests to verify they pass**

Run: `make test`
Expected: all pass (health + registration + auth + 5 portfolio), pristine output.

- [ ] **Step 7: Commit**

```bash
git add backend/src backend/tests backend/migrations
git commit -m "feat: Portfolio entity with owner-scoped CRUD API"
```

---

### Task 5: Account entity, enums, and CRUD endpoints

**Files:**
- Create: `backend/src/Enum/AccountType.php`, `backend/src/Enum/DrawdownFrequency.php`, `backend/src/Enum/DrawdownEntryMode.php`
- Create: `backend/src/Entity/Account.php`
- Create: `backend/src/Repository/AccountRepository.php`
- Create: `backend/src/Dto/AccountInput.php`
- Create: `backend/src/Controller/AccountController.php`
- Modify: `backend/src/Entity/Portfolio.php` (add the real `$accounts` collection + `addAccount` + real `toJson` accounts array, per Task 4's note)
- Create: migration (generated)
- Test: `backend/tests/Controller/AccountTest.php`

**Interfaces:**
- Consumes: `Portfolio`, `PortfolioRepository::findOneOwnedBy` (Task 4), `ApiTestCase` (Task 4).
- Produces: `App\Entity\Account` with `toJson(): array` shape:
  `{"id","portfolioId","name","type","startingBalance","startingBasis","annualReturnRate","inflationRate","horizonYears","contribution":{"monthlyAmount","escalationRate","startsOn","endsOn"},"drawdown":{"amount","frequency","entryMode","startsOn","endsOn","inflationIndexed"}}`;
  `AccountRepository::findOneOwnedBy(int $id, User $owner): ?Account`; routes `POST /api/portfolios/{id}/accounts`, `GET|PUT|DELETE /api/accounts/{id}`. Plan 3's engine consumes exactly the Account fields; Plan 4's UI edits them.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Controller/AccountTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AccountTest extends ApiTestCase
{
    private function createPortfolio(KernelBrowser $client): int
    {
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Main',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->json($client)['id'];
    }

    /** @return array<string, mixed> */
    private function accountPayload(): array
    {
        return [
            'name' => 'My 401k',
            'type' => 'traditional_401k',
            'startingBalance' => 50000.0,
            'annualReturnRate' => 0.07,
            'inflationRate' => 0.03,
            'horizonYears' => 40,
            'contribution' => [
                'monthlyAmount' => 1500.0,
                'escalationRate' => 0.02,
                'startsOn' => null,
                'endsOn' => '2041-07-01',
            ],
            'drawdown' => [
                'amount' => 4000.0,
                'frequency' => 'monthly',
                'entryMode' => 'net',
                'startsOn' => '2041-07-01',
                'endsOn' => null,
                'inflationIndexed' => true,
            ],
        ];
    }

    public function testCreateAccountUnderPortfolio(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);

        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        self::assertResponseStatusCodeSame(201);
        $data = $this->json($client);
        self::assertSame('My 401k', $data['name']);
        self::assertSame('traditional_401k', $data['type']);
        self::assertSame($pid, $data['portfolioId']);
        self::assertSame(1500.0, $data['contribution']['monthlyAmount']);
        self::assertSame('2041-07-01', $data['drawdown']['startsOn']);
        self::assertNull($data['drawdown']['endsOn']);
        self::assertTrue($data['drawdown']['inflationIndexed']);

        $client->jsonRequest('GET', "/api/portfolios/{$pid}");
        self::assertCount(1, $this->json($client)['accounts']);
    }

    public function testBrokerageAccountKeepsStartingBasis(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);

        $payload = $this->accountPayload();
        $payload['name'] = 'Taxable';
        $payload['type'] = 'brokerage';
        $payload['startingBasis'] = 30000.0;

        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $payload);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(30000.0, $this->json($client)['startingBasis']);
    }

    public function testUpdateAndDeleteAccount(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);
        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        $id = $this->json($client)['id'];

        $updated = $this->accountPayload();
        $updated['name'] = 'Renamed 401k';
        $updated['annualReturnRate'] = 0.05;
        $client->jsonRequest('PUT', "/api/accounts/{$id}", $updated);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed 401k', $this->json($client)['name']);
        self::assertSame(0.05, $this->json($client)['annualReturnRate']);

        $client->jsonRequest('DELETE', "/api/accounts/{$id}");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', "/api/accounts/{$id}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testCannotTouchOthersAccounts(): void
    {
        $alice = $this->createAuthenticatedClient('alice2@example.com');
        $pid = $this->createPortfolio($alice);
        $alice->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        $accountId = $this->json($alice)['id'];

        $bob = $this->createAuthenticatedClient('bob2@example.com');
        $bob->jsonRequest('GET', "/api/accounts/{$accountId}");
        self::assertResponseStatusCodeSame(404);
        $bob->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $this->accountPayload());
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidEnumRejected(): void
    {
        $client = $this->createAuthenticatedClient();
        $pid = $this->createPortfolio($client);

        $payload = $this->accountPayload();
        $payload['type'] = 'mattress';
        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", $payload);
        self::assertResponseStatusCodeSame(422);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test`
Expected: FAIL — 404 on the accounts routes.

- [ ] **Step 3: Create the enums**

`backend/src/Enum/AccountType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountType: string
{
    case Traditional401k = 'traditional_401k';
    case Roth401k = 'roth_401k';
    case TraditionalIra = 'traditional_ira';
    case RothIra = 'roth_ira';
    case Brokerage = 'brokerage';
    case Plan529 = 'plan_529';
    case Cash = 'cash';
}
```

`backend/src/Enum/DrawdownFrequency.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum DrawdownFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
```

`backend/src/Enum/DrawdownEntryMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum DrawdownEntryMode: string
{
    case Gross = 'gross';
    case Net = 'net';
}
```

- [ ] **Step 4: Create the Account entity**

`backend/src/Entity/Account.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Enum\DrawdownFrequency;
use App\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Portfolio $portfolio;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(enumType: AccountType::class)]
    private AccountType $type;

    #[ORM\Column]
    private float $startingBalance = 0.0;

    #[ORM\Column(nullable: true)]
    private ?float $startingBasis = null;

    #[ORM\Column]
    private float $annualReturnRate = 0.07;

    #[ORM\Column]
    private float $inflationRate = 0.03;

    #[ORM\Column]
    private int $horizonYears = 40;

    #[ORM\Column]
    private float $contributionMonthlyAmount = 0.0;

    #[ORM\Column]
    private float $contributionEscalationRate = 0.0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contributionStartsOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contributionEndsOn = null;

    #[ORM\Column(nullable: true)]
    private ?float $drawdownAmount = null;

    #[ORM\Column(enumType: DrawdownFrequency::class)]
    private DrawdownFrequency $drawdownFrequency = DrawdownFrequency::Monthly;

    #[ORM\Column(enumType: DrawdownEntryMode::class)]
    private DrawdownEntryMode $drawdownEntryMode = DrawdownEntryMode::Gross;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $drawdownStartsOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $drawdownEndsOn = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $drawdownInflationIndexed = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortfolio(): Portfolio
    {
        return $this->portfolio;
    }

    public function setPortfolio(Portfolio $portfolio): static
    {
        $this->portfolio = $portfolio;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): AccountType
    {
        return $this->type;
    }

    public function setType(AccountType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStartingBalance(): float
    {
        return $this->startingBalance;
    }

    public function setStartingBalance(float $startingBalance): static
    {
        $this->startingBalance = $startingBalance;

        return $this;
    }

    public function getStartingBasis(): ?float
    {
        return $this->startingBasis;
    }

    public function setStartingBasis(?float $startingBasis): static
    {
        $this->startingBasis = $startingBasis;

        return $this;
    }

    public function getAnnualReturnRate(): float
    {
        return $this->annualReturnRate;
    }

    public function setAnnualReturnRate(float $annualReturnRate): static
    {
        $this->annualReturnRate = $annualReturnRate;

        return $this;
    }

    public function getInflationRate(): float
    {
        return $this->inflationRate;
    }

    public function setInflationRate(float $inflationRate): static
    {
        $this->inflationRate = $inflationRate;

        return $this;
    }

    public function getHorizonYears(): int
    {
        return $this->horizonYears;
    }

    public function setHorizonYears(int $horizonYears): static
    {
        $this->horizonYears = $horizonYears;

        return $this;
    }

    public function getContributionMonthlyAmount(): float
    {
        return $this->contributionMonthlyAmount;
    }

    public function setContributionMonthlyAmount(float $amount): static
    {
        $this->contributionMonthlyAmount = $amount;

        return $this;
    }

    public function getContributionEscalationRate(): float
    {
        return $this->contributionEscalationRate;
    }

    public function setContributionEscalationRate(float $rate): static
    {
        $this->contributionEscalationRate = $rate;

        return $this;
    }

    public function getContributionStartsOn(): ?\DateTimeImmutable
    {
        return $this->contributionStartsOn;
    }

    public function setContributionStartsOn(?\DateTimeImmutable $date): static
    {
        $this->contributionStartsOn = $date;

        return $this;
    }

    public function getContributionEndsOn(): ?\DateTimeImmutable
    {
        return $this->contributionEndsOn;
    }

    public function setContributionEndsOn(?\DateTimeImmutable $date): static
    {
        $this->contributionEndsOn = $date;

        return $this;
    }

    public function getDrawdownAmount(): ?float
    {
        return $this->drawdownAmount;
    }

    public function setDrawdownAmount(?float $amount): static
    {
        $this->drawdownAmount = $amount;

        return $this;
    }

    public function getDrawdownFrequency(): DrawdownFrequency
    {
        return $this->drawdownFrequency;
    }

    public function setDrawdownFrequency(DrawdownFrequency $frequency): static
    {
        $this->drawdownFrequency = $frequency;

        return $this;
    }

    public function getDrawdownEntryMode(): DrawdownEntryMode
    {
        return $this->drawdownEntryMode;
    }

    public function setDrawdownEntryMode(DrawdownEntryMode $mode): static
    {
        $this->drawdownEntryMode = $mode;

        return $this;
    }

    public function getDrawdownStartsOn(): ?\DateTimeImmutable
    {
        return $this->drawdownStartsOn;
    }

    public function setDrawdownStartsOn(?\DateTimeImmutable $date): static
    {
        $this->drawdownStartsOn = $date;

        return $this;
    }

    public function getDrawdownEndsOn(): ?\DateTimeImmutable
    {
        return $this->drawdownEndsOn;
    }

    public function setDrawdownEndsOn(?\DateTimeImmutable $date): static
    {
        $this->drawdownEndsOn = $date;

        return $this;
    }

    public function isDrawdownInflationIndexed(): bool
    {
        return $this->drawdownInflationIndexed;
    }

    public function setDrawdownInflationIndexed(bool $indexed): static
    {
        $this->drawdownInflationIndexed = $indexed;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'portfolioId' => $this->portfolio->getId(),
            'name' => $this->name,
            'type' => $this->type->value,
            'startingBalance' => $this->startingBalance,
            'startingBasis' => $this->startingBasis,
            'annualReturnRate' => $this->annualReturnRate,
            'inflationRate' => $this->inflationRate,
            'horizonYears' => $this->horizonYears,
            'contribution' => [
                'monthlyAmount' => $this->contributionMonthlyAmount,
                'escalationRate' => $this->contributionEscalationRate,
                'startsOn' => $this->contributionStartsOn?->format('Y-m-d'),
                'endsOn' => $this->contributionEndsOn?->format('Y-m-d'),
            ],
            'drawdown' => [
                'amount' => $this->drawdownAmount,
                'frequency' => $this->drawdownFrequency->value,
                'entryMode' => $this->drawdownEntryMode->value,
                'startsOn' => $this->drawdownStartsOn?->format('Y-m-d'),
                'endsOn' => $this->drawdownEndsOn?->format('Y-m-d'),
                'inflationIndexed' => $this->drawdownInflationIndexed,
            ],
        ];
    }
}
```

- [ ] **Step 5: Update Portfolio with the real accounts collection**

In `backend/src/Entity/Portfolio.php`, add the `$accounts` collection property, constructor init, `getAccounts()`, `addAccount()`, and the real `toJson()` accounts mapping exactly as shown in Task 4 Step 4's full listing (the parts Task 4 deferred).

- [ ] **Step 6: Create repository, DTOs, controller**

`backend/src/Repository/AccountRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Account> */
final class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findOneOwnedBy(int $id, User $owner): ?Account
    {
        return $this->createQueryBuilder('a')
            ->join('a.portfolio', 'p')
            ->where('a.id = :id')
            ->andWhere('p.owner = :owner')
            ->setParameter('id', $id)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
```

`backend/src/Dto/AccountInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Enum\DrawdownFrequency;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ContributionInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public float $monthlyAmount = 0.0,
        #[Assert\Range(min: 0, max: 1)]
        public float $escalationRate = 0.0,
        #[Assert\Date]
        public ?string $startsOn = null,
        #[Assert\Date]
        public ?string $endsOn = null,
    ) {
    }
}

final readonly class DrawdownInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?float $amount = null,
        public DrawdownFrequency $frequency = DrawdownFrequency::Monthly,
        public DrawdownEntryMode $entryMode = DrawdownEntryMode::Gross,
        #[Assert\Date]
        public ?string $startsOn = null,
        #[Assert\Date]
        public ?string $endsOn = null,
        public bool $inflationIndexed = true,
    ) {
    }
}

final readonly class AccountInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name,
        public AccountType $type,
        #[Assert\PositiveOrZero]
        public float $startingBalance = 0.0,
        #[Assert\PositiveOrZero]
        public ?float $startingBasis = null,
        #[Assert\Range(min: -1, max: 1)]
        public float $annualReturnRate = 0.07,
        #[Assert\Range(min: 0, max: 1)]
        public float $inflationRate = 0.03,
        #[Assert\Range(min: 1, max: 100)]
        public int $horizonYears = 40,
        #[Assert\Valid]
        public ContributionInput $contribution = new ContributionInput(),
        #[Assert\Valid]
        public DrawdownInput $drawdown = new DrawdownInput(),
    ) {
    }
}
```

**Split these into three files** (PSR-4): `backend/src/Dto/ContributionInput.php`, `backend/src/Dto/DrawdownInput.php`, `backend/src/Dto/AccountInput.php` — one class per file, contents exactly as above.

**Implementation requirement:** invalid enum strings in the payload (e.g. `"type": "mattress"`) must produce 422, not 500 — MapRequestPayload handles this via the serializer's backed-enum denormalizer; verify with the test.

`backend/src/Controller/AccountController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AccountInput;
use App\Entity\Account;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\PortfolioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PortfolioRepository $portfolios,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/portfolios/{portfolioId}/accounts', name: 'api_accounts_create', methods: ['POST'], requirements: ['portfolioId' => '\d+'])]
    public function create(int $portfolioId, #[MapRequestPayload] AccountInput $input): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $portfolio = $this->portfolios->findOneOwnedBy($portfolioId, $user);
        if (null === $portfolio) {
            throw $this->createNotFoundException();
        }

        $account = new Account();
        $account->setPortfolio($portfolio);
        $this->apply($account, $input);

        $this->em->persist($account);
        $this->em->flush();

        return $this->json($account->toJson(), 201);
    }

    #[Route('/api/accounts/{id}', name: 'api_accounts_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->findOwnedOr404($id)->toJson());
    }

    #[Route('/api/accounts/{id}', name: 'api_accounts_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] AccountInput $input): JsonResponse
    {
        $account = $this->findOwnedOr404($id);
        $this->apply($account, $input);
        $this->em->flush();

        return $this->json($account->toJson());
    }

    #[Route('/api/accounts/{id}', name: 'api_accounts_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->em->remove($this->findOwnedOr404($id));
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function apply(Account $account, AccountInput $input): void
    {
        $account
            ->setName($input->name)
            ->setType($input->type)
            ->setStartingBalance($input->startingBalance)
            ->setStartingBasis($input->startingBasis)
            ->setAnnualReturnRate($input->annualReturnRate)
            ->setInflationRate($input->inflationRate)
            ->setHorizonYears($input->horizonYears)
            ->setContributionMonthlyAmount($input->contribution->monthlyAmount)
            ->setContributionEscalationRate($input->contribution->escalationRate)
            ->setContributionStartsOn(self::date($input->contribution->startsOn))
            ->setContributionEndsOn(self::date($input->contribution->endsOn))
            ->setDrawdownAmount($input->drawdown->amount)
            ->setDrawdownFrequency($input->drawdown->frequency)
            ->setDrawdownEntryMode($input->drawdown->entryMode)
            ->setDrawdownStartsOn(self::date($input->drawdown->startsOn))
            ->setDrawdownEndsOn(self::date($input->drawdown->endsOn))
            ->setDrawdownInflationIndexed($input->drawdown->inflationIndexed);
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }

    private function findOwnedOr404(int $id): Account
    {
        /** @var User $user */
        $user = $this->getUser();
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if (null === $account) {
            throw $this->createNotFoundException();
        }

        return $account;
    }
}
```

- [ ] **Step 7: Generate and review the migration; run tests**

```bash
make migration
make migrate
make test
```

Expected: migration creates only the `account` table (FK to portfolio, CASCADE); all tests pass including the 6 new account tests, pristine output.

- [ ] **Step 8: Commit**

```bash
git add backend/src backend/tests backend/migrations
git commit -m "feat: Account entity with enums and owner-scoped CRUD API"
```

---

### Task 6: Portfolio duplication, dev fixtures, and push

**Files:**
- Modify: `backend/src/Controller/PortfolioController.php` (duplicate route)
- Modify: `backend/src/Entity/Portfolio.php` + `backend/src/Entity/Account.php` (`duplicate()` methods)
- Create: `backend/src/DataFixtures/AppFixtures.php`
- Test: `backend/tests/Controller/PortfolioDuplicateTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `POST /api/portfolios/{id}/duplicate` → 201 with the cloned portfolio JSON (name suffixed " (copy)", all accounts cloned); `make fixtures` seeds demo user `demo@nestegg.local` / password `demo-password-123` with one portfolio ("Baseline") containing a Traditional 401k and a Brokerage account. Plan 4's UI uses duplication as scenario-forking.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/PortfolioDuplicateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class PortfolioDuplicateTest extends ApiTestCase
{
    public function testDuplicateClonesPortfolioAndAccounts(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Original',
            'ordinaryIncomeTaxRate' => 0.24,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $pid = $this->json($client)['id'];
        $client->jsonRequest('POST', "/api/portfolios/{$pid}/accounts", [
            'name' => 'My 401k',
            'type' => 'traditional_401k',
            'startingBalance' => 10000.0,
            'annualReturnRate' => 0.07,
            'inflationRate' => 0.03,
            'horizonYears' => 30,
        ]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', "/api/portfolios/{$pid}/duplicate");
        self::assertResponseStatusCodeSame(201);
        $copy = $this->json($client);
        self::assertSame('Original (copy)', $copy['name']);
        self::assertSame(0.24, $copy['ordinaryIncomeTaxRate']);
        self::assertNotSame($pid, $copy['id']);
        self::assertCount(1, $copy['accounts']);
        self::assertSame('My 401k', $copy['accounts'][0]['name']);
        self::assertNotNull($copy['accounts'][0]['id']);

        $client->jsonRequest('GET', '/api/portfolios');
        self::assertCount(2, $this->json($client));
    }

    public function testCannotDuplicateOthersPortfolio(): void
    {
        $alice = $this->createAuthenticatedClient('alice3@example.com');
        $alice->jsonRequest('POST', '/api/portfolios', [
            'name' => 'Private',
            'ordinaryIncomeTaxRate' => 0.22,
            'capitalGainsTaxRate' => 0.15,
        ]);
        $pid = $this->json($alice)['id'];

        $bob = $this->createAuthenticatedClient('bob3@example.com');
        $bob->jsonRequest('POST', "/api/portfolios/{$pid}/duplicate");
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test`
Expected: FAIL — 404 on `/duplicate` route (POST to it hits the accounts-create route pattern? No — different path; expect plain 404).

- [ ] **Step 3: Implement duplication**

Add to `backend/src/Entity/Account.php`:

```php
    public function duplicate(): self
    {
        $clone = new self();
        $clone->name = $this->name;
        $clone->type = $this->type;
        $clone->startingBalance = $this->startingBalance;
        $clone->startingBasis = $this->startingBasis;
        $clone->annualReturnRate = $this->annualReturnRate;
        $clone->inflationRate = $this->inflationRate;
        $clone->horizonYears = $this->horizonYears;
        $clone->contributionMonthlyAmount = $this->contributionMonthlyAmount;
        $clone->contributionEscalationRate = $this->contributionEscalationRate;
        $clone->contributionStartsOn = $this->contributionStartsOn;
        $clone->contributionEndsOn = $this->contributionEndsOn;
        $clone->drawdownAmount = $this->drawdownAmount;
        $clone->drawdownFrequency = $this->drawdownFrequency;
        $clone->drawdownEntryMode = $this->drawdownEntryMode;
        $clone->drawdownStartsOn = $this->drawdownStartsOn;
        $clone->drawdownEndsOn = $this->drawdownEndsOn;
        $clone->drawdownInflationIndexed = $this->drawdownInflationIndexed;

        return $clone;
    }
```

Add to `backend/src/Entity/Portfolio.php`:

```php
    public function duplicate(): self
    {
        $clone = new self();
        $clone->setOwner($this->owner)
            ->setName($this->name.' (copy)')
            ->setOrdinaryIncomeTaxRate($this->ordinaryIncomeTaxRate)
            ->setCapitalGainsTaxRate($this->capitalGainsTaxRate);
        foreach ($this->accounts as $account) {
            $clone->addAccount($account->duplicate());
        }

        return $clone;
    }
```

Add to `backend/src/Controller/PortfolioController.php`:

```php
    #[Route('/{id}/duplicate', name: 'api_portfolios_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id): JsonResponse
    {
        $copy = $this->findOwnedOr404($id)->duplicate();
        $this->em->persist($copy);
        $this->em->flush();

        return $this->json($copy->toJson(), 201);
    }
```

- [ ] **Step 4: Write fixtures**

Replace `backend/src/DataFixtures/AppFixtures.php`:

```php
<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\Portfolio;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Enum\DrawdownFrequency;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setEmail('demo@nestegg.local')
            ->setBirthDate(new \DateTimeImmutable('1990-06-15'))
            ->setDeathAge(90)
            ->setPassword($this->hasher->hashPassword($user, 'demo-password-123'));
        $manager->persist($user);

        $portfolio = (new Portfolio())
            ->setOwner($user)
            ->setName('Baseline')
            ->setOrdinaryIncomeTaxRate(0.22)
            ->setCapitalGainsTaxRate(0.15);

        $k401 = (new Account())
            ->setName('Employer 401k')
            ->setType(AccountType::Traditional401k)
            ->setStartingBalance(50000.0)
            ->setAnnualReturnRate(0.07)
            ->setInflationRate(0.03)
            ->setHorizonYears(40)
            ->setContributionMonthlyAmount(1500.0)
            ->setContributionEscalationRate(0.02)
            ->setContributionEndsOn(new \DateTimeImmutable('2041-07-01'))
            ->setDrawdownAmount(4000.0)
            ->setDrawdownFrequency(DrawdownFrequency::Monthly)
            ->setDrawdownEntryMode(DrawdownEntryMode::Net)
            ->setDrawdownStartsOn(new \DateTimeImmutable('2041-07-01'));

        $brokerage = (new Account())
            ->setName('Taxable brokerage')
            ->setType(AccountType::Brokerage)
            ->setStartingBalance(25000.0)
            ->setStartingBasis(20000.0)
            ->setAnnualReturnRate(0.06)
            ->setInflationRate(0.03)
            ->setHorizonYears(40)
            ->setContributionMonthlyAmount(500.0);

        $portfolio->addAccount($k401)->addAccount($brokerage);
        $manager->persist($portfolio);
        $manager->flush();
    }
}
```

(If `backend/src/DataFixtures/AppFixtures.php` doesn't exist from the Flex recipe, create it.)

- [ ] **Step 5: Run everything, load fixtures**

```bash
make test
make fixtures
```

Expected: full suite passes; fixtures load without error into the dev DB.

- [ ] **Step 6: Commit and push**

```bash
git add backend/src backend/tests
git commit -m "feat: portfolio duplication endpoint and demo fixtures"
git push origin main
```
