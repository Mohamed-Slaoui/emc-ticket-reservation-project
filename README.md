# Ticketing — Performance Venue

A web application for **online and box-office ticketing** for a 400-seat performance venue, built for the **PFE Challenge EMC**.

| | |
|---|---|
| **Subject** | **Subject 1 of 4 — Performance Venue Ticketing** |
| **Stack** | Symfony 7.4 · PHP 8.2+ · MySQL 8 · Doctrine ORM · Migrations · Twig |
| **Deadline** | Sunday, November 15, 2026 at 23:59 (Morocco time) |
| **Status** | 🟡 **Level 1 (Model) in progress — no code yet** |

---

## About the project

The venue currently sells tickets at the box office and by phone, with a paper-based tracking system — resulting in **seats sold twice** and no clear view of occupancy.

The application aims to:

- describe the room and its seats (numbered, grouped into zones) and **reuse this description from one event to another**;
- schedule events with **one or more performances**;
- offer **several tariffs** (full, reduced, group, invitation);
- sell **online and at the box office**;
- track **sales, revenue, and occupancy**.

### The structuring design choice

The model separates **the physical venue** from **the sellable inventory**:

- `Place` — the physical seat (A12, or a standing slot);
- `LayoutPlace` — the seat placed in a zone, for a **versioned room configuration**;
- `PerformancePlace` — the seat available / held / sold **for a given performance**.

Thus, the same seat can be sold for a concert, held for a play, and available for a conference, without ever being sold twice for the same performance. Protection relies on **unique constraints** and **transactions with row locking** in the database.

---

## Challenge levels

| Level | Theme | Status | Notes |
|---|---|---|---|
| **1** | Model | 🟡 In progress | Data model designed, documented, and validated; entities, migrations, demo data, and back-office to be built. |
| **2** | Enforce the rules | ⚪ Not started | Business rules defined (hold, payment, refund, tariffs); to be implemented and tested. |
| **3** | Make it a product | ⚪ Not started | Accounts and roles, purchase journey, dashboard. |
| **4** | Open up to the world | ⚪ Not started | Google Sheets synchronization via transactional outbox (designed). |
| **5** | Deliver | ⚪ Not started | Docker + `INSTALL.md` (to be produced). |

> 🟡 = in progress · ⚪ = not started · ✅ = done

---

## Planned features

**Venue and seating management**
- Rooms, physical seats (numbered seats and standing slots), versioned configurations, zones.

**Programming**
- Events (concert, theatre, conference) with one or more performances.
- Each performance uses a room configuration.

**Pricing**
- Tariffs: full, reduced, group, invitation.
- Prices defined per performance, zone, and tariff, with version history.

**Sales**
- Online and box-office purchases on **the same availability engine**.
- Temporary seat hold (10 minutes by default, configurable).
- Online payment (simulated in the MVP) and at the counter (cash / terminal).
- Cancellation and full refund.

**Ticketing**
- PDF ticket with a unique QR code, downloadable online or printed at the box office.
- Entry validation with **single use**.

**Monitoring**
- Dashboard: occupancy by performance and zone, revenue by tariff and channel.

**Integration**
- Asynchronous and idempotent Google Sheets synchronization (one row per ticket).

---

## Technical stack

| Component | Choice |
|---|---|
| Back-end | Symfony 7.4 |
| Language | PHP 8.2 or higher |
| Database | MySQL 8 |
| ORM | Doctrine ORM + Migrations |
| Front-end | Twig (+ Stimulus/Turbo if needed) |
| Tests | PHPUnit |
| Container | Docker + Docker Compose (level 5) |
| Integration | Google Sheets API (level 4) |

---

## Documentation

| Document | Content |
|---|---|
| [`docs/MODELE.md`](docs/MODELE.md) | Data model: diagrams (conceptual, class, ER, sequences, lifecycles) and **explanation of the choices**. |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | **Dated** decision log and **answers to the "Questions to settle"**. |
| [`INSTALL.md`](INSTALL.md) | Step-by-step installation guide (to be produced for level 5). |

The source diagrams are in [`docs/`](docs/) (`.jpg`).

---

## Repository conventions

Layout:
```
src/
├── Controller/
├── Entity/
├── Repository/
├── Service/
├── Enum/
├── Form/
├── Security/
└── DataFixtures/

tests/
├── Unit/
├── Integration/
└── Functional/
```

---

## How to run the project

> **TBD** — the installation procedure will be documented here once the implementation and the Docker environment are in place (level 5). It will detail:
>
> - prerequisites (Docker, Docker Compose);
> - the launch command (`docker compose up`);
> - automatic migrations and demo data;
> - demo accounts (customer, box-office employee, administrator);
> - common troubleshooting.
>
> In the meantime, see [`INSTALL.md`](INSTALL.md) (to be produced).

---

## What works / what remains to be done

**Works**
- Requirements and domain analysis.
- Conceptual and relational data model, validated.
- Business rules and lifecycles defined.
- Answers to the "Questions to settle".

**Remaining**
- Initialize the Symfony 7.4 project.
- Implement the Doctrine entities and migrations.
- Write the demo fixtures.
- Implement the business services (allocation, payment, refund, ticketing).
- Write automated tests (unit, integration, functional).
- Develop the interfaces (customer, box office, administration).
- Set up authentication and roles.
- Integrate Google Sheets.
- Containerize and write `INSTALL.md`.

---

## Author

Project carried out as part of the **PFE Challenge EMC 2026**.

---

## License

Educational project — not intended for production use.
