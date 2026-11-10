# Development Guide

| | |
|---|---|
| **Project** | Performance Venue Ticketing — PFE Challenge EMC 2026 |
| **Subject** | Subject 1 of 4 — Billetterie d'une salle de spectacle |
| **Phase** | 0 — Project inspection and technical foundation |
| **Last updated** | 2026-11-10 |
| **Related documents** | [`docs/MODELE.md`](MODELE.md) · [`docs/DECISIONS.md`](DECISIONS.md) · [`docs/WORKFLOWS.md`](WORKFLOWS.md) · [`README.md`](../README.md) |

---

## 1. Purpose of this document

This document is the **operational companion** of the project. It records:

1. the **development roadmap** (the 18 phases) and their status;
2. the **coding conventions** every contributor must follow;
3. the **technical foundation** as verified during Phase 0;
4. the **known issues and blockers** carried forward to later phases.

Design decisions live in [`docs/DECISIONS.md`](DECISIONS.md). The data model lives in
[`docs/MODELE.md`](MODELE.md). Business workflows live in [`docs/WORKFLOWS.md`](WORKFLOWS.md).

---

## 2. Technical foundation (verified on 2026-11-10)

Versions below were **measured on the working machine**, not assumed.

| Component | Version | Source of truth |
|---|---|---|
| PHP | 8.2.12 (ZTS, x64) | `php -v` |
| Composer | 2.9.8 | `composer --version` |
| Symfony | 7.4.23 (LTS — EOL 11/2029) | `bin/console about` |
| Doctrine ORM | 3.7 | `composer show doctrine/orm` |
| Doctrine Migrations | 3.7 | `composer show doctrine/doctrine-migrations-bundle` |
| PHPUnit | 11.5.57 | `vendor/bin/phpunit --version` |
| Doctrine Fixtures | 4.3 | `composer show doctrine/doctrine-fixtures-bundle` |
| MySQL | 8.0.46 (Docker container `mysql:8.0`) | `SELECT VERSION()` |
| Docker Desktop | 4.89.0 | `docker version` |
| Docker Compose | v5.5.0 | `docker compose version` |

### 2.1 Required PHP extensions

| Extension | Status | Notes |
|---|---|---|
| `pdo_mysql`, `mysqli` | present | Required by Doctrine DBAL. |
| `bcmath` | present | **Required** — monetary arithmetic uses `bcadd` / `bccomp`, never floats. |
| `mbstring` | polyfilled | Provided by `symfony/polyfill-mbstring`. |
| `intl` | **absent** | `symfony/intl` currently relies on the polyfills (`symfony/polyfill-intl-*`). Install `ext-intl` before the Docker image is finalised (Phase 16). |
| `gd` | absent | Needed for PDF/QR rendering (Phase 11). |
| `zip` | present | — |

### 2.2 Environment files

| File | Committed? | Role |
|---|---|---|
| `.env` | **yes** | Non-secret defaults only. **Must be committed**, otherwise a fresh clone cannot boot: Symfony's Dotenv aborts with `Unable to read the ".env" environment file`. |
| `.env.example` | **yes** | Documented template. Copy to `.env.local` and override what you need. |
| `.env.test` | **yes** | Public test placeholder secret. |
| `.env.dev` | no | Ignored — may hold a machine-specific secret. |
| `.env.local`, `.env.*.local`, `.env.local.php` | no | Ignored — **real credentials live here**. |

> **Rule.** Real secrets (database passwords, API keys, Google service-account keys) go in
> `.env.local` or in real environment variables. Environment variables always win over files.
> `.env` and `.env.example` must never contain a real credential.

### 2.3 Docker services

| Service | Image | Ports | Purpose |
|---|---|---|---|
| `database` | `mysql:8.0` | `3306` | MySQL 8 — the only database target. |
| `mailer` | `axllent/mailpit` | `1025` (SMTP), `8025` (web UI) | Mail catcher for local development. |

The PHP application container is **not** part of the Compose stack yet — it is delivered in Phase 16.

---

## 3. Repository layout

```
.
├── assets/                  # Stimulus controllers and CSS (AssetMapper, no build step)
├── bin/                     # console, phpunit
├── config/
│   ├── packages/            # Per-bundle configuration
│   ├── routes/              # Routing configuration
│   └── services.yaml        # Service container definition
├── docs/                    # Model, decisions, workflows, diagrams
├── migrations/              # Doctrine Migrations
├── public/                  # Front controller
├── src/
│   ├── Controller/          # HTTP controllers (Admin, BoxOffice, Customer, Entry)
│   ├── DataFixtures/        # Deterministic demo data
│   ├── Entity/              # Doctrine entities
│   ├── Enum/                # Backed enumerations
│   ├── Repository/          # Doctrine repositories
│   ├── Service/             # Application services (Venue, Event, Booking, Payment, Ticket, Reporting)
│   └── Kernel.php
├── templates/               # Twig templates
├── tests/
│   ├── Unit/                # Isolated business rules
│   ├── Integration/         # Repositories, persistence, transactions
│   ├── Functional/          # HTTP routes, forms, authorization
│   ├── DoctrineTestTrait.php # Shared kernel + test-database bootstrap
│   └── bootstrap.php
├── translations/            # Translation catalogues
├── compose.yaml             # MySQL 8 + Mailpit
└── .env / .env.example      # Environment configuration
```

---

## 4. Development conventions

These rules are binding for every phase.

### 4.1 General

- **PSR-12** and Symfony coding standards. One class per file, strict typing on
  new value objects and DTOs, meaningful names, no abbreviations.
- **Constructor injection** everywhere; no service locator, no static helpers.
- **Business logic never lives in a controller.** Controllers translate HTTP to a
  command and back. Domain rules live in entities and services.
- **Entities are never returned directly as API/JSON payloads.** Use DTOs.
- **Validate every input on the server side.** Client-side validation is a convenience, never a guarantee.
- **Money is always a decimal string** handled with `bcadd` / `bccomp` — never a float.
- **Database constraints first.** A rule that can be expressed as a `UNIQUE`, `FOREIGN KEY`
  or `CHECK` constraint *must* be, and the service layer re-checks it inside the transaction.
- **Everything that must succeed or fail together runs in one transaction**, with row-level
  locking (`SELECT … FOR UPDATE`) when allocating inventory.
- **History is preserved.** Expired or cancelled records are never deleted; state is changed instead.
- **Pagination** on any collection that can grow (seat maps, reservations, tickets).
- **Authorisation is enforced server-side** on every route and every ownership-sensitive operation.

### 4.2 Testing

Tests are part of the feature, not a later stage. Every phase ships its tests.

| Suite | Scope | Example |
|---|---|---|
| `tests/Unit` | Pure business rules, no database. | `ReservationTest`, `PerformancePlaceTest` |
| `tests/Integration` | Repositories, persistence, transactions, real database constraints. | `UniqueConstraintsTest` |
| `tests/Functional` | HTTP routes, forms, authentication, authorisation, full workflows. | `FixturesTest` |

Rules:

- Test **observable behaviour**, never implementation details.
- Never weaken an assertion to make a suite green. A failing test is a finding, not an obstacle.
- Test data is deterministic and shared through `src/DataFixtures`.
- The suite must be runnable against MySQL 8 — no SQLite fallback, because the schema
  depends on MySQL-specific behaviour.

### 4.3 Documentation

- `README.md` is a **living document**: it is updated in the same phase as any feature,
  dependency, command, integration or setup requirement it introduces.
- `docs/DECISIONS.md` gets a **dated entry** for every significant decision, with the
  problem, the chosen option, the alternatives and the impact.
- `docs/MODELE.md` and `docs/WORKFLOWS.md` are updated whenever the data model or a
  workflow changes.
- `docs/DEVELOPMENT.md` (this file) tracks the roadmap and the known issues.

---

## 5. Roadmap and progress

Status legend: `Pending` · `In progress` · `Review required` · `Completed`.

| Phase | Description | Status |
|---|---|---|
| 0 | Project inspection and technical foundation | **Completed** |
| 1 | Core domain foundation (entities, enums, migration) | **Review required** |
| 2 | Authentication, users and authorisation | Pending |
| 3 | Venue, room and physical place administration | Pending |
| 4 | Seating layout and zone management | Pending |
| 5 | Events and performances | Pending |
| 6 | Tariffs and performance pricing | Pending |
| 7 | Inventory availability and reservation foundation | Pending |
| 8 | Customer booking experience | Pending |
| 9 | Box-office sales | Pending |
| 10 | Online payment integration | Pending |
| 11 | Ticket issuance and entry validation | Pending |
| 12 | Administrator reservation and performance management | Pending |
| 13 | Reporting and dashboards | Pending |
| 14 | Google Sheets integration | Pending |
| 15 | Hardening, quality and security | Pending |
| 16 | Docker and deployment preparation | Pending |
| 17 | Final documentation and delivery | Pending |

### 5.1 Phase dependency map

```
Phase 0  (foundation)          ← current
   └─ Phase 1  domain model
        ├─ Phase 2  authentication & roles
        │    ├─ Phase 3  venue / room / places
        │    │    └─ Phase 4  seating layouts & zones
        │    │         └─ Phase 5  events & performances
        │    │              └─ Phase 6  tariffs & pricing
        │    │                   └─ Phase 7  reservations & holds
        │    │                        ├─ Phase 8  customer booking
        │    │                        ├─ Phase 9  box-office sales
        │    │                        ├─ Phase 10 online payment
        │    │                        ├─ Phase 11 tickets & entry
        │    │                        └─ Phase 12 admin reservation control
        │    │                             └─ Phase 13 reporting
        │    └─ Phase 14 Google Sheets (needs Phase 11 tickets)
        └─ Phase 15 hardening → Phase 16 Docker → Phase 17 delivery
```

### 5.2 Verification commands

Run these at the end of every phase.

```bash
# 1. Environment
docker compose up -d database
composer install

# 2. Database
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test
php bin/console doctrine:schema:validate
php bin/console doctrine:fixtures:load --env=test

# 3. Quality
php vendor/bin/phpunit --testdox
php bin/console lint:container
composer validate
```

> **Availability at Phase 0.** Steps 2 (migrations, schema validation, fixtures) and the
> PHPUnit suite depend on the domain model, which is delivered in Phase 1. Until then,
> `doctrine:migrations:migrate` reports *"there are no registered migrations"* and
> PHPUnit reports *"No tests executed!"* — both are the **expected** Phase 0 state, not
> failures. The commands become meaningful as soon as Phase 1 lands.
>
> **Since Phase 1**, all the commands above are effective: the schema is created by the
> migration, the fixtures populate the demo data, and the suite runs 81 tests.

---

## 6. Known issues and blockers

Carried forward from the Phase 0 inspection. Each one names its target phase.

| # | Issue | Impact | Target |
|---|---|---|---|
| F2 | `ext-intl` is not installed; `symfony/intl` currently relies on its polyfills. | Locale-dependent behaviour may differ from production. | Phase 16 |
| F3 | `ext-gd` is not installed. | PDF/QR rendering (Phase 11) will need it. | Phase 16 |
| F4 | ~~A `Payment` did not own its `Refund` collection, so the refund cap was not enforced in memory~~ — **fixed in Phase 1**: the refund registers itself on its payment, and both the successful and the pending refunds are counted against the captured amount. | Financial rule could be bypassed inside a single request. | — |
| F5 | ~~`PerformancePlace::hold()` compared performances by object identity~~ — **fixed in Phase 1**: the comparison is made on the identifier, with a fallback to object identity for entities that are not persisted yet. | Spurious allocation failures. | — |
| F9 | ~~A `BLOCKED` seat could still be held, and releasing one silently put it back on sale~~ — **fixed in Phase 1**: `hold()` rejects a blocked seat, and `release()` only frees the allocation while keeping the seat out of sale. | An out-of-sale seat could be sold. | — |
| F10 | The `EntityManager` was captured in `setUp()` **before** the fixtures were loaded, and `loadFixtures()` rebooted the kernel — which orphans the manager and makes Doctrine report detached entities as "non-persisted new entities". | Two integration tests failed for a reason unrelated to the domain. | — |
| F6 | No static analysis (PHPStan) or code-style tool (ECS / PHP-CS-Fixer) is configured. | Quality gate is limited to PHPUnit. | Phase 15 |
| F7 | `templates/base.html.twig` still contains the Symfony demo favicon and the `hello` Stimulus controller. | Cosmetic; not project-specific. | Phase 3 |
| F8 | The Docker stack has no PHP application container. | The app must be run from the host. | Phase 16 |

### 6.1 Issues already resolved

| # | Issue | Resolution |
|---|---|---|
| F1 | `.env` was gitignored while being mandatory to boot. | **Phase 0** — `.env` is committed and `.env.example` added. |
| F4 | The refund cap was not enforced in memory. | **Phase 1** — the refund registers itself on its payment. |
| F5 | Performances were compared by object identity. | **Phase 1** — the comparison uses the identifier. |
| F9 | A blocked seat could be held, and releasing it put it back on sale. | **Phase 1** — `hold()` rejects it, `release()` keeps it out of sale. |
| F10 | The `EntityManager` was orphaned by the fixture bootstrap. | **Phase 1** — resolved lazily, through `DoctrineTestTrait`. |

---

## 7. Approval workflow

Every phase follows the same gate. **The assistant never commits or pushes without an
explicit, unambiguous instruction from the project owner.**

1. **Propose** — phase name, objective, scope, files to touch, dependencies, verification
   commands, and any decision needing approval. Then stop and wait.
2. **Implement** — only the approved scope. Nothing extra, nothing in parallel.
3. **Verify** — run the full verification list in §5.2 and fix what the implementation broke.
4. **Report** — what was implemented, which files changed, the decisions taken, the tests
   executed **with their real results**, the documentation updates, the known issues, and a
   suggested commit message. Then stop.
5. **Commit** — only on an explicit instruction that authorises the commit.
6. **Push** — only on a separate, explicit instruction that authorises the push.

> "Looks good", "continue", "go to the next phase" or a green test suite **do not** authorise
> a commit or a push. A commit authorisation does not imply a push authorisation.

---

## 8. Code review checklist

Before presenting a phase:

- [ ] The scope matches the approved scope — nothing extra was added.
- [ ] No business rule was changed, and no entity relationship was modified silently.
- [ ] Every new rule that can be a database constraint **is** one.
- [ ] Money is handled with `bcadd` / `bccomp`, never floats.
- [ ] Every transition that must be atomic runs inside a transaction with row locking.
- [ ] Tests cover the **new** behaviour, and the whole suite is green.
- [ ] No assertion was weakened to obtain a green run.
- [ ] `README.md` and the relevant `docs/` files are synchronised with the change.
- [ ] No secret, API key or credential appears in a tracked file.
- [ ] The report states the **actual** test results, including anything skipped or failing.
