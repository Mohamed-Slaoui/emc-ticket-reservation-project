# Ticketing — Performance Venue

A web application for **online and box-office ticketing** for a 400-seat performance venue,
built for the **PFE Challenge EMC**.

| | |
|---|---|
| **Subject** | **Subject 1 of 4 — Performance Venue Ticketing** |
| **Stack** | Symfony 7.4 · PHP 8.2+ · MySQL 8 · Doctrine ORM · Migrations · Twig |
| **Deadline** | Sunday, November 15, 2026 at 23:59 (Morocco time) |
| **Status** | 🟡 **Phase 2 complete — authentication and administration UI** |

---

## 1. Project description

The venue currently sells tickets at the box office and by phone, with a paper-based
tracking system — resulting in **seats sold twice** and no clear view of occupancy.

This application replaces that paper process with a single, database-enforced ticketing
engine that serves the box office and the website through **the same allocation logic**.

## 2. Problem being solved

| Today | Tomorrow |
|---|---|
| A seat can be sold twice — the paper ledger cannot be trusted. | A unique database constraint and a row lock make double-selling impossible. |
| Occupancy is unknown until the doors open. | Occupancy is computed live, per performance and per zone. |
| Revenue is tallied by hand, with no separation between sales and refunds. | Gross and net revenue are derived from the payment and refund records. |
| A customer cannot buy a ticket online. | Online purchase with a temporary hold, online payment and downloadable PDF tickets. |
| A ticket is a paper stub: it can be used twice, and cannot be invalidated. | A QR ticket is valid exactly once, and can be voided when the performance is cancelled. |

## 3. Main objectives

1. **Model the venue once and reuse it.** Describe the room and its seats, group them into
   zones, and reuse that description from one event to another.
2. **Never sell the same seat twice for the same show.** Guaranteed by the database, not by
   the interface.
3. **Make the rules server-side.** Tariff eligibility, group minimums, hold expiry and
   payment limits are checked by the application, never only hidden in the UI.
4. **Preserve the history.** Expired and cancelled reservations are never deleted; the
   records remain, so sales and revenue remain auditable.
5. **Deliver an operational dashboard.** Occupancy, revenue by tariff and synchronisation
   status, in one place.

## 4. Technology stack

| Component | Choice | Version |
|---|---|---|
| Language | PHP | ≥ 8.2 (8.2.12 used) |
| Framework | Symfony | 7.4 (LTS) |
| Database | MySQL | 8.0 |
| ORM | Doctrine ORM + Migrations | 3.7 |
| Templating | Twig (AssetMapper, Stimulus, Turbo) | 7.4 |
| Security | Symfony Security | 7.4 |
| Async | Symfony Messenger (Doctrine transport) | 7.4 |
| Tests | PHPUnit | 11.5 |
| Container | Docker Compose | — |
| Integration | Google Sheets API | — |

## 5. Main features

> **Status.** The features below are the **approved scope** of the project. Nothing is
> implemented yet — the roadmap is tracked in [§17](#17-development-progress).

### Venue and seating management
Rooms, physical places (numbered seats and standing slots), versioned configurations, zones.

### Programming
Events (concert, theatre, conference) with one or more performances. Each performance uses
exactly one room configuration and generates its own sellable inventory.

### Pricing
Four tariffs — full, reduced, group, invitation. Prices defined per performance, per zone
and per tariff, with version history, so the price of an already-sold ticket never changes.

### Sales
Online and box-office purchases on **the same availability engine**. Temporary seat hold
(10 minutes by default, configurable). Online payment and counter payment (cash / terminal).
Cancellation and full refund.

### Ticketing
PDF ticket with a unique QR code, downloadable online or printed at the box office. Entry
validation with **single use**.

### Monitoring
Dashboard: occupancy by performance and zone, gross and net revenue, revenue by tariff.

### Integration
Asynchronous, idempotent Google Sheets synchronisation — one row per ticket.

## 6. User roles and permissions

| Role | Can do |
|---|---|
| `CUSTOMER` | Browse published events, select seats, hold and pay for a reservation, download their own tickets, cancel their own unpaid holds. |
| `BOX_OFFICE` | Everything a customer can do, **plus**: sell at the counter, apply the reduced / group / invitation tariffs, verify eligibility in person, print tickets, search reservations. |
| `ADMIN` | Everything above, **plus**: configure the venue, layouts, events, prices and the booking policy, publish an event, cancel a confirmed reservation, issue a refund, cancel a performance, view the dashboard. |

Three rules are enforced server-side and are the basis of the whole authorisation model:

- The **full** tariff is the only one a customer may apply online.
- The **reduced** tariff requires in-person verification of a supporting document.
- The **group** and **invitation** tariffs are reserved for the box office and administrators.

A box-office employee **cannot** cancel a confirmed reservation — that is an administrator
action, and it always triggers a full refund.

There is **no public registration**: the accounts are created by the venue
administrator, from the administration screens.

Signing in is protected by a CSRF token, the passwords are hashed (bcrypt), and a
disabled account cannot sign in — it is reported as an unknown address, so the visitor
learns nothing about the accounts that exist.

### The interface

The administration screens share a single **design system**: a token file holds every
colour, radius, spacing and shadow, and the components (button, card, badge, table, form,
alert, pagination, icon set) are reused across the screens. The stylesheet is plain CSS
served by AssetMapper — **no build step and no Node toolchain** — and the webfont is
self-hosted, so the application makes no external request at runtime.

The forms are compact by design: the related fields share a line, the roles are a row of
selectable cards, and the guidance is a small badge that reveals its text on hover — so a
whole screen fits in one viewport.

The lists are **paginated**: a screen shows a page of rows — six by default — and the
visitor chooses the size from a selector next to the filters. The navigation keeps the
filters and the chosen size, so moving to another page never loses the context.

## 7. Architecture overview

```
                    ┌──────────────────────────────────────────┐
   Browser  ───────►│  Symfony application                     │
                    │                                          │
                    │  Controller  →  Service  →  Entity       │
                    │      │           │           │          │
                    │      │           │           │          │
                    │      ▼           ▼           ▼          │
                    │   Form/DTO    Validation   Doctrine      │
                    │                                  │       │
                    └──────────────────────────────────┼───────┘
                                                       ▼
                                                  MySQL 8
                                                       ▲
                    ┌──────────────────────────────────┼───────┐
                    │  Messenger worker (outbox)       │       │
                    │  OutboxMessage → Google Sheets  │       │
                    └──────────────────────────────────────────┘
```

**Three design decisions shape everything:**

1. **The physical venue is separated from the sellable inventory.**
   `Place` (the physical seat) → `LayoutPlace` (the seat placed in a zone of a versioned
   configuration) → `PerformancePlace` (the seat available / held / sold **for one
   performance**). The same seat can therefore be sold for Friday's concert, held for
   Saturday's play and available for Sunday's conference, with no contradiction.

2. **The rules live in the database.** Unique constraints, foreign keys and transactions
   with row locking — not just JavaScript. A prior availability check cannot prevent two
   simultaneous requests from completing; only a row lock inside a transaction can.

3. **One sales flow, two channels.** Online and box-office sales use the same allocation
   engine. The channel (`ONLINE` / `BOX_OFFICE`) is an attribute of the reservation, not a
   parallel model.

## 8. Main domain concepts

| Concept | Nature | Example |
|---|---|---|
| `Place` | **Physical** | Seat A-12, which exists regardless of performances. |
| `SeatingLayout` → `LayoutZone` → `LayoutPlace` | **Configuration** | "Seat A-12 belongs to the Orchestre zone in the Standard configuration v1." |
| `PerformancePlace` | **Sellable inventory** | "Seat A-12 is available for Friday's concert." |
| `Event` → `Performance` | **Programming** | "Hamlet" performed on 12 November at 20:00. |
| `Reservation` → `ReservationItem` | **Purchase** | One reservation for one performance, one item per seat. |
| `Payment` / `Refund` | **Money** | Several attempts per reservation, one successful capture, refunds tracked separately. |
| `Ticket` | **Admission** | One ticket per confirmed item, unique number and opaque QR token. |
| `User` | **Identity** | `CUSTOMER`, `BOX_OFFICE` or `ADMIN`. |
| `BookingPolicy` | **Configuration** | Hold duration, 10 minutes by default. |
| `OutboxMessage` | **Integration** | A pending synchronisation event, written in the sale transaction. |

The full model, with its rationale, is in [`docs/MODELE.md`](docs/MODELE.md).

## 9. Current folder structure

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
│   ├── Service/             # Application services
│   └── Kernel.php
├── templates/               # Twig templates
├── tests/
│   ├── Unit/                # Isolated business rules
│   ├── Integration/         # Repositories, persistence, transactions
│   └── Functional/          # HTTP routes, forms, authorisation
├── translations/            # Translation catalogues
├── compose.yaml             # MySQL 8 + Mailpit
└── .env / .env.example      # Environment configuration
```

## 10. Installation and startup

**Prerequisites:** PHP 8.2+ with `pdo_mysql` and `bcmath`, Composer 2, and Docker
(with Compose) for the database.

```bash
# 1. Install the dependencies
composer install

# 2. Start MySQL 8 and Mailpit
docker compose up -d database

# 3. Create the database
php bin/console doctrine:database:create

# 4. Apply the migrations
php bin/console doctrine:migrations:migrate

# 5. Load the demo data (venue, 400 seats, tariffs, demo accounts)
php bin/console doctrine:fixtures:load

# 6. Run the application
php -S localhost:8000 -t public
```

The application is then reachable on <http://localhost:8000>, and Mailpit on
<http://localhost:8025>.

> **Current state (Phase 1).** The schema and the demo data exist: steps 1–6 all work from
> a fresh clone. The application has **no interface yet** — the HTTP layer arrives in
> Phase 2 and later.

## 11. Environment configuration

| Variable | Purpose | Default |
|---|---|---|
| `APP_ENV` | Application environment | `dev` |
| `APP_SECRET` | Symfony secret | placeholder in `.env`, **must be overridden** |
| `DATABASE_URL` | MySQL connection string | `mysql://app:!ChangeMe!@127.0.0.1:3306/app` |
| `MESSENGER_TRANSPORT_DSN` | Outbox worker transport | `doctrine://default?auto_setup=0` |
| `MAILER_DSN` | Mail transport | `null://null` |
| `DEFAULT_URI` | Base URL used by the CLI | `http://localhost` |

**Files and their policy:**

| File | Committed | Role |
|---|---|---|
| `.env` | **yes** | Non-secret defaults. Required for the app to boot. |
| `.env.example` | **yes** | Documented template — copy to `.env.local`. |
| `.env.test` | **yes** | Public test placeholder. |
| `.env.local`, `.env.*.local` | no | **Real credentials belong here.** |

> Real environment variables always take precedence over the files. Never commit a secret.

## 12. Database and migrations

The schema is managed by Doctrine Migrations. Every uniqueness and integrity rule from the
model is expressed as a database constraint.

```bash
php bin/console doctrine:migrations:migrate          # apply
php bin/console doctrine:migrations:migrate first    # roll back
php bin/console doctrine:migrations:status           # inspect
php bin/console doctrine:schema:validate             # mapping ↔ database check
```

Applying the migrations is **enough to install the venue** — the initial migration creates the
schema, and `Version20261113215513` also **seeds the four tariff categories** (full, reduced,
group, invitation) into the `tariff` table. The four tariffs are part of the product, not of the
demo data: no fixture load is needed, and no fifth category can be inserted — the codes are
`CHECK`-constrained and the labels are a generated column. (The development fixtures also load
them; the two paths derive from the same `TariffCode` enum.)

The test database uses the `app_test` suffix automatically.

## 13. Running the tests

```bash
php vendor/bin/phpunit                 # whole suite
php vendor/bin/phpunit --testdox       # readable output
php vendor/bin/phpunit tests/Unit      # one suite
```

The suite runs against **MySQL 8** — there is no SQLite fallback, because the schema
depends on MySQL-specific behaviour. The database must therefore be running first
(`docker compose up -d database`).

> **Current state (Phase 1).** The suite covers the domain model: entity mapping and
> relationships, enumeration values, the `PerformancePlace` state invariants, the
> reservation / payment / ticket / refund lifecycles, and the database uniqueness
> constraints. The HTTP-level suites arrive with the first interface (Phase 3).

## 14. Running the asynchronous workers

The Google Sheets synchronisation runs out of band, through a Symfony Messenger worker
that drains the transactional outbox.

```bash
php bin/console messenger:consume async
```

The worker is safe to run concurrently: each message is claimed atomically and the
idempotency key prevents duplicate rows in Google Sheets.

> **Current state (Phase 0).** The Messenger transport is configured and the
> `messenger_messages` table is created by the migrations, but the outbox itself arrives
> with the domain model in Phase 1 and the worker is exercised in Phase 14.

## 15. Google Sheets integration

One row per **ticket**, written asynchronously.

The sale transaction also writes an `outbox_message` row — in the **same** transaction. A
Google Sheets outage therefore never blocks nor rolls back a successful sale. The worker
retries failed messages with a backoff, counts the attempts, and uses the ticket
identifier as the idempotency key so a retry can never duplicate a row.

Credentials come from a Google service account and are supplied through the environment
(`GOOGLE_APPLICATION_CREDENTIALS`), never committed. The feature is delivered in the
integration phase; the design is documented in
[`docs/DECISIONS.md`](docs/DECISIONS.md) §3.7.

## 16. Documentation

| Document | Content |
|---|---|
| [`docs/MODELE.md`](docs/MODELE.md) | Data model: conceptual, class and ER diagrams, with the rationale for every choice. |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Dated decision log and the answers to the "questions to settle". |
| [`docs/WORKFLOWS.md`](docs/WORKFLOWS.md) | The approved business workflows, transcribed from the sequence diagrams. |
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | Roadmap, coding conventions, technical foundation, known issues. |
| [`INSTALL.md`](INSTALL.md) | Step-by-step installation guide *(delivered with the deployment phase)*. |

The source diagrams are in [`docs/`](docs/) (`.jpg`).

## 17. Development progress

Status legend: `Pending` · `In progress` · `Review required` · `Completed`.

| Phase | Description | Status |
|---|---|---|
| 0 | Project inspection | **Completed** |
| 1 | Core domain foundation | **Completed** |
| 2 | Authentication and authorization | **Completed** |
| 3 | Venue and room administration | **Completed** |
| 4 | Seating layouts and zones | **Completed** |
| 5 | Events and performances | **Completed** |
| 6 | Tariffs and pricing | **Review required** |
| 7 | Reservation foundation | Pending |
| 8 | Customer booking | Pending |
| 9 | Box-office sales | Pending |
| 10 | Online payments | Pending |
| 11 | Tickets and entry | Pending |
| 12 | Administrative controls | Pending |
| 13 | Reporting | Pending |
| 14 | Google Sheets | Pending |
| 15 | Hardening and security | Pending |
| 16 | Docker and deployment | Pending |
| 17 | Final delivery | Pending |

**What exists today**

- The Symfony 7.4 skeleton, the MySQL 8 Docker stack and the environment configuration.
- **The complete domain model** — 19 entities, 13 enumerations, 19 repositories, the
  initial migration and the demo data (one venue, one room, 400 seats, three zones, the
  four tariffs, three accounts, the booking policy).
- The domain rules enforced at the **entity** level: the `PerformancePlace` state
  invariant, the reservation / payment / ticket / refund lifecycles, the price snapshots,
  and the refund cap.
- The uniqueness rules enforced at the **database** level and proven by integration tests.
- **Authentication and authorisation** — sign in, sign out, the role hierarchy
  (`ADMIN` ⊃ `BOX_OFFICE` ⊃ `CUSTOMER`), the access rules, and the administration of the
  accounts (list, create, edit, enable, disable, delete, reset password). A disabled account
  cannot sign in.
- **The design system** — a self-hosted webfont, the Ocean Teal palette, and a set of
  reusable components (buttons, cards, badges, tables, forms, alerts, pagination, icons).
  The application shell — sidebar, top bar, content area — is responsive.
- **The administration of the physical venue** — the venues, their rooms and the places
  those rooms hold, with a bulk creator that fills a whole row of seats at a time.
- **Paginated lists** — every collection screen shows a page of rows and lets the visitor
  choose the size (6 by default, up to 96). The navigation keeps the filters and the
  chosen size.
- **The seating layouts** — the **versioned configurations** of a room: the zones that
  group its seats, and the **seat map** that draws the room and maps each seat to a zone.
  A configuration used by a performance is frozen — any evolution creates a new version.
- **The events and their performances** — a programme, its dated shows, the configuration
  each show sells and the **inventory** it generates. An event is published only when it is
  complete: the administration shows the four checks and the reason when one fails.
- **The prices** — set per zone and per tariff, and **versioned**: changing a price creates
  a new version and retires the old one, so an already sold ticket keeps its price. An event
  is published only when every zone and every tariff is priced.

**What comes next**

Phase 7 opens the inventory: reservation holds, availability, and the foundation of the
booking flow.

## 18. Known limitations and MVP boundaries

The following are **explicitly out of scope** for the first version. None of them requires
redesigning the model:

- Partial refunds and partial reservation cancellation — only full refunds exist.
- Split payments, or several successful payments per reservation.
- Online reduced-tariff requests — the customer cannot self-declare eligibility.
- Several performances within a single reservation — one reservation covers one show.
- Editing a seating configuration already used by a performance — a new version is needed.
- Promo codes, loyalty programmes, dynamic pricing.
- Advanced reconciliation with the payment provider.
- A dedicated mobile scanning application.

Known technical issues carried forward are listed in
[`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) §6.

---

## Repository conventions

The layout follows the Symfony conventions:

```
src/
├── Controller/     # Admin, BoxOffice, Customer, Entry
├── DataFixtures/
├── Entity/
├── Enum/
├── Repository/
├── Service/        # Venue, Event, Booking, Payment, Ticket, Reporting
└── Kernel.php

tests/
├── Unit/
├── Integration/
└── Functional/
```

Coding standards, testing rules and the approval workflow are documented in
[`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md).

---

## Author

Project carried out as part of the **PFE Challenge EMC 2026**.

---

## License

Educational project — not intended for production use.
