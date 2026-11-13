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
├── assets/                  # Stimulus controllers, styles and the self-hosted webfont
├── bin/                     # console, phpunit
├── config/
│   ├── packages/            # Per-bundle configuration
│   ├── routes/              # Routing configuration
│   └── services.yaml        # Service container definition
├── docs/                    # Model, decisions, workflows, diagrams
├── migrations/              # Doctrine Migrations
├── public/                  # Front controller
├── src/
│   ├── Controller/          # HTTP controllers (Admin, Security, …)
│   ├── DataFixtures/        # Deterministic demo data
│   ├── DTO/                 # Form and view models
│   ├── Entity/              # Doctrine entities
│   ├── Enum/                # Backed enumerations
│   ├── Form/                # Symfony forms
│   ├── Repository/          # Doctrine repositories
│   ├── Security/            # Security services (user provider)
│   ├── Service/             # Application services
│   └── Kernel.php
├── templates/
│   ├── _components/         # Reusable UI components
│   ├── _partials/           # Shared includes (icons)
│   ├── admin/  customer/  box_office/  entry/   # Screens, feature-oriented
│   └── base.html.twig       # The application shell
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

### 3.1 The design system

The interface is built on **plain CSS**, served by AssetMapper: no build step, no Node
toolchain, nothing to install before the application runs.

```
assets/styles/
├── app.css          # the single entry point (@import manifest)
├── _fonts.css       # the self-hosted webfont
├── _tokens.css      # every colour, radius, spacing, shadow
├── _reset.css
├── _base.css        # element defaults
├── _layout.css      # the application shell
├── _components/     # one file per component
├── _utilities.css   # a small, whitelisted set
└── _print.css       # the printed tickets (Phase 11)
```

**The rules**

- The token file is the **only** place where a colour, a radius or a spacing value is
  written. A component never hard-codes a value.
- One component per file, named after the component.
- Utilities stay small; anything used twice belongs in a component.
- The icons are inline SVG paths in `templates/_partials/icons.html.twig` — no icon font,
  no sprite file, no dependency.
- A field's help text is **never printed**: it becomes a `form__hint` badge that reveals
  the text on hover or on focus. The form stays compact and the guidance stays available.
- A radio group is rendered as a row of **selectable cards** (`form__card`). The label of
  a choice is written `Title — description` and is split by the theme, so a card shows a
  title and a short description. A standalone checkbox uses the same card.
- A form maps a **DTO**, not the entity: an entity constructor takes arguments, and the
  form owns the values until the service saves them.
- The choices of a form are **scalars**. Symfony's choice list does not understand
  enumerations: an enum used as a choice value is rendered with its array index. The
  backing value (`SEAT`) is used instead, and the enum is rebuilt by the DTO.
- The fields that belong together share a line through `form__grid`.
- A list screen is a **toolbar** (the filters and the page size), a **table** and a
  **pagination bar** in the footer of the card. The page size is a `page-size` selector
  inside the filter form; the navigation is the `pagination` component.

### 3.2 Composing the components — a Twig constraint

Twig resolves the macros of a template **at compile time**, which has two consequences
that must be respected:

- A macro can only use the macros imported **inside its own body**. A macro that calls
  another macro must import it inside itself.
- The blocks of an `{% embed %}` are compiled **in the embedded template**: the macros
  imported by the caller are **not** available inside them.

The components therefore follow one pattern: they are **included** with plain data, and
the caller captures the dynamic markup first:

```twig
{% set body %}
    <p>Some content…</p>
    {{ button('Do it', path('somewhere')) }}
{% endset %}
{% include '_components/card.html.twig' with { title: 'Title', body: body } %}
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
| `tests/Functional` | HTTP routes, forms, authentication, authorisation, full workflows. | `LoginTest`, `UserManagementTest` |

Rules:

- Test **observable behaviour**, never implementation details.
- Never weaken an assertion to make a suite green. A failing test is a finding, not an obstacle.
- Test data is deterministic and shared through `src/DataFixtures`.
- The suite must be runnable against MySQL 8 — no SQLite fallback, because the schema
  depends on MySQL-specific behaviour.
- A **concurrency** guarantee is only proven by a test that really runs concurrently: a
  sequential test proves nothing. `ConcurrentSchedulingTest` spawns **two processes** that
  wait for the same barrier and then schedule an overlapping performance in the same
  room; exactly one of them may succeed. The helper it spawns lives in
  `tests/Fixtures/`. A test like that is only worth keeping if it **fails** when the
  guarantee is removed — check it before believing it.

### 4.3 Symfony 7 — the traps worth remembering

They were all met during Phase 2; they are recorded so that the next phases do not
rediscover them.

| Trap | What happens | The rule |
|---|---|---|
| `ROLE_USER` is **not** added automatically | A custom user entity that returns only its business roles is denied by every `access_control` rule that expects `ROLE_USER`. | `User::getRoles()` always merges `ROLE_USER`; `getBusinessRoles()` returns the stored ones. |
| The generic `UserChecker` was **removed** | With an entity provider, a **disabled account still signs in**. | The check is made in `App\Security\ActiveUserProvider`, which reports a disabled account as *not found*. |
| An invalid form answers **422**, not 200 | `assertResponseIsSuccessful()` fails on a rejected form. | Assert `422` and look for the error in the form. |
| A failed `form_login` **redirects** to the login page | It does not answer 401; the error travels through the session. | Assert the redirect, then follow it and look for the alert. |
| `firewalls.main.form_login` uses `login_path`, not `path` | The configuration is refused at build time. | `login_path` + `check_path`. |
| A macro cannot use the imports of the template body | "Macros imported in the body … are not available because the body was not rendered." | Import inside the macro. |
| The blocks of an `{% embed %}` cannot use the caller's macros | "Unknown 'button' function". | `{% include %}` + captured markup — see §3.2. |
| DomCrawler cannot fill a **multi-checkbox** group | "Input '…[roles][]' cannot take … as a value". | Use radio buttons for a single choice — which is also what the role hierarchy implies. |
| `assertResponseIsSuccessful()` takes a **message**, not a URI | The URI is silently used as the failure message. | Use `$client->followRedirect()` and assert the path. |
| `InputBag::getInt()` **throws** on an empty value | A filter form submits `room=` when "every room" is chosen, and the screen answers **400** ("Input value \"room\" is invalid and flag \"FILTER_NULL_ON_FAILURE\" was not set"). | Read the parameter as a **string** and cast it by hand: `(int) $request->query->getString('room')`. `getString()` does not filter. |
| The CSRF tokens `submit`, `authenticate` and `logout` are **stateless** by default | They are validated with a double-submit cookie and a header. A plain form posts its token *before* the script rewrites it, so the sign in falls back on the `Origin` header — and it fails as soon as the session has recorded another strategy. | The application keeps its state in the session, so its tokens are kept there too: `stateless_token_ids: []` in `config/packages/csrf.yaml`. |
| **A form that re-renders after an error must answer 422, not 200** | Turbo refuses a 200 that is not a redirect: *"Form responses must redirect to another location"* — and the administrator sees **nothing at all**, no error, no message. A 422 is rendered by Turbo like an invalid form. | `RendersUnprocessableForm::renderUnprocessable()` in the controllers. It is asserted by the tests, because a silent failure is invisible to a test that does not assert the status code. |
| A **pessimistic lock** needs an open transaction | `find($class, $id, LockMode::PESSIMISTIC_WRITE)` outside a transaction throws, and the lock would be released immediately anyway. | The lock is taken inside `EntityManager::wrapInTransaction()`, before the check it protects. |

### 4.4 The lists — pagination

Every screen that shows a collection follows the same three rules.

| Rule | Where |
|---|---|
| The page and the page size are read from the query string by **`PageRequest`**, which clamps the page size to the allowed choices. | `src/DTO/PageRequest.php` |
| The repository returns a **page** and a `Pagination` descriptor; the query stays a plain `LIMIT/OFFSET`. | `src/Repository/*Repository.php` |
| The page size is chosen in a **selector inside the filter form**, and the navigation keeps the size and the filters of the current view. | `templates/_components/page_size.html.twig`, `templates/_components/pagination.html.twig` |

The default page size is **6** (`Pagination::DEFAULT_PER_PAGE`) and the visitor may
choose 6, 12, 24, 48 or 96 (`Pagination::ALLOWED_PER_PAGE`). A page size outside that
list falls back to the default, so a hand-crafted URL cannot ask the database for an
unreasonable number of rows.

The filter forms and the pagination links carry `data-turbo="false"`: another page of a
list is another document, and a plain navigation does not depend on the JavaScript.

### 4.5 The seating layouts

A configuration is **versioned**: the version is the highest one of the room plus one, and it
is never typed by hand. Evolving a configuration therefore means **creating a new
version** — the old one keeps the seats it sold.

| Rule | Where |
|---|---|
| The version is assigned by the server. | `SeatingLayoutService::create()` |
| A zone code is unique within its configuration. | `LayoutZoneService` + the unique key |
| A place appears **once** per configuration, and it must be a place of the room. | `LayoutPlaceService` + the unique key |
| A configuration used by a performance is **frozen**. | `SeatingLayoutService::assertIsEditable()` |
| Deleting a zone **releases** its seats: they go back to the unmapped seats of the room, they are not deleted. | `LayoutZoneService::delete()` |
| Deleting a configuration removes its **seat map and its zones** first — the foreign keys are strict. | `SeatingLayoutService::delete()` |
| A room that holds a configuration cannot be deleted. | `RoomService::delete()` |

### 4.6 The events and their performances

An **event** is a programme; a **performance** is one of its dated shows. A performance uses
**exactly one** seating configuration, and that configuration generates its **inventory**:
one sellable seat per seat of the configuration.

| Rule | Where |
|---|---|
| The inventory is generated when the performance is scheduled — never by hand. | `PerformanceService::generateInventory()` |
| A seat the configuration marks as unavailable is generated **blocked**: it is not on sale, but it stays in the configuration. | `PerformanceService` |
| An event is **published** only when it is complete. The checks are shown to the administrator. | `EventService::publicationChecks()` |
| A performance that already holds a sale cannot be deleted — it is cancelled instead. | `PerformanceService::delete()` |
| An event that has performances is **kept**: the shows are the programme itself, and deleting them is a deliberate act. | `EventService::delete()` |
| A **room** cannot host two overlapping performances — whichever configuration they use. The room is reached through the configuration. | `PerformanceService::assertRoomIsFree()` |
| The intervals are **half-open**: a show ending at 14:00 and a show starting at 14:00 do not overlap. A show without an end date occupies the room indefinitely. | `PerformanceRepository::findOverlappingInRoom()` |
| The room row is **locked before** the conflict is checked, inside a transaction: two simultaneous schedulings cannot both pass. | `PerformanceService::lockRoom()` |
| The end of a show must be **strictly after** its start, and the sale must close when the show starts. | `PerformanceService::assertScheduleIsCoherent()` |

The publication checks are the four of the workflow:

1. the event has a performance;
2. the performance has a configuration;
3. the inventory is generated;
4. the prices are set — every zone × the four catalogue tariffs has a current price.

### 4.7 The prices

A price is set per **zone** and per **tariff**, and it is **versioned**: a change
retires the previous version and creates the next one, so the price of an already
sold ticket is never altered. The reservations reference the exact price row that was
sold.

A **tariff** is not administered. The venue sells exactly four pricing categories —
full, reduced, group, invitation — fixed by the application (`App\Enum\TariffCode`),
seeded by the migrations (and the development fixtures), and enforced by the database:
`ck_tariff_code` (`code IN (…)`), a **generated `name`** computed from the code, and
no setter on the entity. The eligibility rule (a supporting document is verified in
person for the reduced tariff) and the channel rule (full tariff only online) are
derived from the code. `/admin/tariffs` is a read-only reference: it documents the
rules instead of editing them.

| Rule | Where |
|---|---|
| The catalogue is the `TariffCode` enum — one definition for the seed, the migration, the matrix and the reference screen. | `App\Enum\TariffCode` + `TariffRepository::catalogue()` |
| The four categories cannot be created, renamed, removed or deactivated. | `ck_tariff_code` + generated `name` + no write path |
| A catalogue row that goes missing counts as **unpriced** — an incomplete catalogue can never be published as priced. | `PerformancePriceService::missingPriceCount()` |
| Saving the matrix creates a new version when a price differs from the current one. Unchanged cells are left alone. | `PerformancePriceService::save()` |
| Clearing a cell **unsets** the price of that combination. Pricing stays incomplete and the event cannot be published until it is filled again. | `PerformancePriceService::save()` |
| The save runs in a transaction in which the performance row is locked. | `PerformancePriceService::save()` |
| The retire is flushed **before** the new version is inserted: the generated column unique key `current_combo` forbids two rows claiming the same combination at once. | `PerformancePriceService::save()` |
| The compilation of zones × tariffs is deduplicated by the generated column — the database is the only guarantee. | `uk_performance_price_current` |
| An unreadable price is rejected: the administrator sees the reason, the form is not saved. | `PerformancePriceService::parseAmount()` + `PricingValidationException` (rendered by `RendersUnprocessableForm`) |

### 4.8 The reservation engine

WF-1, Phase 7 (docs/WORKFLOWS.md). Both the customer channel (Phase 8) and the box office
(Phase 9) go through the same engine — one availability rule, INV-10.

| Rule | Where |
|---|---|
| The hold locks the inventory rows, then re-checks availability — two holds issued at the same moment are serialised and only one sees a seat still on sale. | `ReservationService::hold()` + `PerformancePlaceRepository::findForLockByIds()` |
| The full critical section is one transaction: availability re-checked, reservation + items created with price snapshots, seats marked HELD until the booking policy's expiry. | `ReservationService::hold()` |
| The hold expiry is computed once, at hold creation. Changing the policy afterwards does not affect holds in progress (BR-04). | `ReservationService::holdExpiry()` |
| The online channel sells the full tariff only (Q5); reduced needs the document verified in person, at the counter. | `ReservationService::hold()` — `SalesChannel::Online` |
| The group tariff needs at least 5 seats in the same reservation, all at the group rate. | `ReservationService::hold()` — BR-12 |
| A hold is confirmed as `CONFIRMED` only while not expired; after expiry the call is refused (INV-03). | `Reservation::confirm()` |
| The hold expiry engine — reservation → `EXPIRED`, seats → `AVAILABLE`, items → `EXPIRED`; history is kept. Exposed as the `app:holds:expire` command for the scheduler. | `ReservationService::expireDueHolds()` + `ExpireHoldsCommand` |
| An unpaid hold is cancelled by its author (or the customer of that reservation); the seats are released, the items `CANCELLED` (BR-09). | `ReservationService::cancelHeldReservation()` |
| A confirmed reservation is cancelled by an administrator through the refund flow, never here. | Phase 10/12 |

### 4.9 The cascading deletions

The foreign keys are strict, so a deletion removes **what it holds**, in this order:

| Deleted | Removes |
|---|---|
| A **place** | Its mappings in the seating configurations. |
| A **configuration** | Its seat map, then its zones. |
| A **room** | Its configurations, then its seats. |
| A **venue** | Its rooms — and through them their configurations and their seats. |

The one thing that survives is what a **performance has already sold**: a configuration used
by a performance, or a seat mapped in such a configuration, cannot be deleted — the
deletion is refused with the reason.

The confirmation of the button says what is going to be removed, so the administrator is
never surprised.

The seat map is a **plain form**, not a Symfony form: it holds one checkbox per seat of the
room — four hundred of them — and the browser sends the checked seats. The zone colours
come from the token file and follow the position of the zone in the configuration.

### 4.10 Documentation

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
| 1 | Core domain foundation (entities, enums, migration) | **Completed** |
| 2 | Authentication, users and authorisation | **Completed** |
| 3 | Venue, room and physical place administration | **Completed** |
| 4 | Seating layout and zone management | **Completed** |
| 5 | Events and performances | **Completed** |
| 6 | Tariffs and performance pricing | **Completed** |
| 7 | Inventory availability and reservation foundation | **Review required** |
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
| F10 | The `EntityManager` was captured in `setUp()` **before** the fixtures were loaded, and `loadFixtures()` rebooted the kernel — which orphans the manager and makes Doctrine report detached entities as "non-persisted new entities". | Two integration tests failed for a reason unrelated to the domain. | - |
| F6 | No static analysis (PHPStan) or code-style tool (ECS / PHP-CS-Fixer) is configured. | Quality gate is limited to PHPUnit. | Phase 15 |
| F7 | `templates/base.html.twig` still contains the Symfony demo favicon and the `hello` Stimulus controller. | Cosmetic; not project-specific. | Phase 3 |
| F8 | The Docker stack has no PHP application container. | The app must be run from the host. | Phase 16 |
| F16 | On Windows, **two concurrent requests** that both need a Doctrine proxy class race on the same file: `rename()` fails with *"Access is denied"*, and the request answers 500. | The first requests after a `cache:clear` are fragile. | Phase 16 |
| F17 | On the Symfony CLI development server, a request carrying Turbo's `X-Turbo-Request-Id` header can hang in the browser: the response is produced by the server but never delivered to the page. | The in-app navigation (the links, the forms) can appear frozen. The list screens answer **200** and the server is healthy — the request simply never completes in the browser. | Phase 16 |

### 6.1 Issues already resolved

| # | Issue | Resolution |
|---|---|---|
| F1 | `.env` was gitignored while being mandatory to boot. | **Phase 0** — `.env` is committed and `.env.example` added. |
| F4 | The refund cap was not enforced in memory. | **Phase 1** — the refund registers itself on its payment. |
| F5 | Performances were compared by object identity. | **Phase 1** — the comparison uses the identifier. |
| F9 | A blocked seat could be held, and releasing it put it back on sale. | **Phase 1** — `hold()` rejects it, `release()` keeps it out of sale. |
| F10 | The `EntityManager` was orphaned by the fixture bootstrap. | **Phase 1** — resolved lazily, through `DoctrineTestTrait`. |
| F11 | The schema did not deliver the guarantees the model promised: several current prices could coexist for one combination, the inventory state invariant was PHP-only, and a second booking-policy row was possible. | **Phase 1 (patch)** — migration `Version20261110221207` adds a generated-column unique key on `performance_price`, the `ck_performance_place_state` check constraint, and a unique key on `booking_policy.singleton`. |
| F12 | The account form could not delete an account, and `booking_policy.updated_by_id` blocked the deletion of an account that had changed the policy. | **Phase 2** — the deletion was added with its history-preserving guards, and the reference became `ON DELETE SET NULL` (migration `Version20261112154148`). |
| F13 | The forms mapped the entities directly, but their constructors require arguments, and a form class named `PlaceType` collided with the `App\Enum\PlaceType` import. | **Phase 3** — the forms now map dedicated DTOs, and the enum is referenced by its fully qualified name. |
| F14 | The place code was not checked before the insert, so a duplicate code reached the unique key and answered 500. | **Phase 3** — `PlaceService` refuses the code and the reason is shown next to the field. |
| F15 | The list screens showed every row: the places screen held 400 of them, and the filter forms answered **400** because an empty filter (`room=`) is rejected by `InputBag::getInt()`. | **Phase 3** — the lists are paginated (6 rows by default, the visitor chooses the size), and the filters are read as strings. |
| F18 | A seating configuration could not be administered: the version had to be typed by hand, the zones had no screen, and the seats could not be mapped. | **Phase 4** — the configurations are versioned automatically, the zones and the seat map have their own screen, and a configuration used by a performance is frozen. |
| F19 | Deleting a configuration failed with a **foreign key violation**: its zones and its seat map still referenced it. | **Phase 4** — the deletion removes the seat map and the zones first. The same guard was added to the room, which could not be deleted while it held a configuration. |
| F20 | The sign in answered *"Invalid CSRF token"* once the session had recorded another CSRF strategy. The `submit`, `authenticate` and `logout` tokens were **stateless** (double-submit cookie + header), but a plain form posts its token before the script rewrites it — so the sign in fell back on the `Origin` header and broke as soon as a Turbo submission had used the cookie. | **Phase 4** — the tokens are stored in the session (`config/packages/csrf.yaml`), and the sign in and the sign out are plain navigations. |
| F21 | A venue, a room or a place could not be deleted as soon as it held something: the foreign keys are strict, and the administration refused the deletion instead of removing what it held. | **Phase 4** — the deletions cascade: a venue takes its rooms, their configurations and their seats; a room takes its configurations and its seats; a place leaves the configurations it was mapped in. Only what a performance has already sold survives, and the confirmation warns about it. |
| F22 | The event form was named `EventType`, like the enumeration it uses, and the route parameter `eventId` did not map to the `$event` argument. | **Phase 5** — the enumeration is referenced by its fully qualified name, and the parameter is mapped with `#[MapEntity(id: 'eventId')]`. |
| F26 | A form that could not be saved (a conflicting show, a duplicate code) re-rendered with **200**. Turbo refuses a 200 that is not a redirect, so the page did not change and the administrator saw nothing — the error was silent. | **Phase 5** — such a form answers **422**, like an invalid form: Turbo renders it, and the administrator sees the message. |
| F27 | Retiring and creating a price version in a single flush hit the generated-column unique key `uk_performance_price_current`: Doctrine inserts the new row before it updates the old one, and for a moment two rows claim to be the current price of the same combination. | **Phase 6** — the retire is flushed first, freeing the unique key before the new version is inserted. |
| F28 | The four tariffs were seeded **only** by the development fixtures — a production database built from migrations alone would have had no catalogue: no tariff to price, no matrix, no way to publish an event. | **Phase 6** — migration `Version20261113215513` installs the four tariffs (built from `TariffCode`), so production gets the catalogue through migrations alone. |
| F29 | The tariff screen let an administrator rename a tariff, deactivate it, or flag a different eligibility rule — the four approved categories could drift from what the reports and the eligibility rules assume, and a fifth category could not be excluded by the application. | **Phase 6** — the catalogue is fixed: no write path remains in the UI, no setter exists on the entity, the codes are `CHECK`-constrained (`ck_tariff_code`), the label is a generated column, and the eligibility rule is derived from the code. |
| F23 | The demo data had no event, and a fixture that queried the database before flushing found nothing: the inventory of the performance was empty. | **Phase 5** — the demo data holds a draft event, and the fixture flushes before it queries. |
| F24 | Deleting an event that had a performance answered **500**: the foreign key of the performances is strict. | **Phase 5** — the deletion is refused with a clear alert ("This event has N performances: delete them first"), and the performances are deleted one by one, each with its inventory. |
| F25 | Two performances could be scheduled on the same room at the same time: the room was double-booked, and nothing in the model forbade it. | **Phase 5** — the room is derived through the configuration, the room row is locked, and the scheduling is refused when the room is occupied. |

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
