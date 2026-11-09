# Decision Log

| | |
|---|---|
| **Project** | Performance Venue Ticketing - PFE Challenge EMC |
| **Subject** | Subject 1 of 4 |
| **Period** | 2026-11-08 → 2026-11-15 (deadline: 2026-11-15 at 23:59, Morocco time) |
| **Last updated** | **2026-11-09** |
| **Data model** | [`docs/MODELE.md`](MODELE.md) |

> This document records, in **dated** form, the assumptions, trade-offs, and technical choices made for the project. It explicitly answers the **"Questions to settle"** from the specification (section 2) and records decisions that the subject did not impose.

---

## 1. Dated log

### 2026-11-08 - Framing and subject selection

- Read the entire challenge and subject 1 ("Billetterie d'une salle de spectacle" / ticketing for a performance venue).
- **Decision:** choose subject 1 over the other three.
- **Justification:** it offers the most technical depth within the time allowed - **concurrency** management (a seat sold only once, including simultaneous purchases), **lifecycles** (hold, confirmation, expiry, cancellation, refund), and varied **pricing rules**. It is also the subject where data-model quality is most visible.
- **Decision:** do not code before having a validated data model. The chosen order is: requirements → domain → model → rules → architecture → code.
- **Assumption:** the single room in the subject is modeled as a `Venue` containing one `Room`, to keep evolvability without unnecessary complexity.

### 2026-11-09 - Domain analysis and modeling

- Identified the **seven business areas**: venue & seating, programming, pricing, inventory & reservations, payment & refunds, ticketing, identity & access; the Google Sheets integration is treated as a cross-cutting concern.
- **Structuring decision:** separate **three levels** - `Place` (physical), `LayoutPlace` (configuration), and `PerformancePlace` (sellable inventory). This is the key that allows reusing the room without duplicating seats or losing history.
- **Decision:** `Place` belongs to the `Room`; a `SeatingLayout` **maps** existing seats via `LayoutPlace` rather than creating new seats. A configuration is **versioned** and unmodifiable once used.
- **Decision:** a `Performance` references a `SeatingLayout` (not a free list of seats), allowing a different configuration per performance (standing pit, conference setup, etc.).
- **Decision:** `Reservation` is the **single aggregate** of the purchase journey; no separate `Order` entity for the MVP.
- **Decision:** `ReservationItem` carries a price **snapshot** (`tariff_name_snapshot`, `unit_price_snapshot`, `line_total_snapshot`).
- **Decision:** keep `PerformancePlace.currentReservation` (current allocation) **and** the `ReservationItem` history (complete history). The two answer different questions.

### 2026-11-09 - Final business rules

Validated the following rules (detailed in section 3):

- One performance per reservation.
- Configurable temporary hold, **10 minutes by default**.
- Payment failure: the seat stays blocked until expiry.
- Late payment after expiry: **full refund, no ticket issued**.
- Cancellation of a confirmed reservation: **administrator only, full refund**.
- Group tariff: **minimum 5 seats**, all at the group rate.
- Reduced tariff: **verified by box office/admin**, no online self-declaration.
- Google Sheets: **one row per ticket**, asynchronous idempotent synchronization.

---

## 2. Answers to the "Questions to settle"

The five questions from the specification, with the chosen answer and its justification.

### Q1. Does the price of an already-sold ticket change if the tariff is modified afterwards?

> **Answer: No. The price is frozen at sale time.**

**Justification.** Once the reservation is confirmed, the ticket must keep its original price: this is a commercial consistency and accounting traceability obligation. Concretely:

- `ReservationItem` stores `tariff_name_snapshot`, `unit_price_snapshot`, and `line_total_snapshot`.
- A tariff change for future sales is done by creating a **new version** of `PerformancePrice` (`version`, `is_current`) rather than overwriting the existing row.
- Already-confirmed reservations and tickets are never recalculated.

### Q2. Can a performance use a different room configuration (for example a standing pit for a concert)?

> **Answer: Yes. Each performance references its own versioned configuration.**

**Justification.** A multi-purpose room does not have the same layout for a concert and a conference. The model supports this natively:

- `Performance.seatingLayoutId` points to a `SeatingLayout` (e.g., "Concert configuration with standing pit v2").
- `Place` is typed (`SEAT` or `STANDING_SLOT`): standing zones are represented by anonymous slots (`ST-001`…), reusing the same allocation mechanism.
- A configuration **already used** by a performance becomes unmodifiable: any evolution creates a **new version**, preserving the integrity of past sales.

### Q3. What happens to a seat whose payment fails?

> **Answer: It stays blocked until the hold expires, then becomes available again. A late payment is refunded with no ticket issued.**

**Justification.** A payment failure should not unnecessarily penalize the customer or release the seat too early:

- On failure, `Payment` moves to `FAILED`; the reservation stays `HELD` until its `expiresAt`. The customer can **retry**.
- Each attempt is **recorded** (multiple `Payment` rows possible for one reservation).
- At expiry, the inventory becomes `AVAILABLE` and the reservation moves to `EXPIRED`.
- If the provider notifies a success **after** expiry (late payment), the capture is recorded, a **full refund** is initiated, and **no ticket is issued**. A seat already sold to another customer is never automatically reallocated.

### Q4. What happens to sold tickets if a performance is cancelled?

> **Answer: The performance is cancelled, tickets become `VOID`, confirmed reservations are cancelled, and full refunds are issued.**

**Justification.** Cancelling a performance is an administrative act that commits the venue to its customers:

- `Performance.status = CANCELLED`.
- `PerformancePlace` rows move to `BLOCKED` (with reason "Performance cancelled") and release the current allocation.
- `Ticket` rows for the performance move to `VOID` and are rejected at entry.
- Confirmed reservations move to `CANCELLED` and trigger a full refund.
- **History is preserved**: no deletion, to maintain accounting and traceability.

### Q5. Who is allowed to sell which tariff?

> **Answer: Rights depend on the tariff and the role. The online channel is reserved for the full tariff; reduced, group, and invitation tariffs go through the box office or an admin.**

**Justification.** Two notions that are often confused must be distinguished: the customer's **eligibility** and the seller's **authorization**.

| Tariff | Who can apply it? | Customer eligibility |
|---|---|---|
| **Full** | Customer (online), box office, admin | Everyone. |
| **Reduced** | Box office or admin, after checking proof | Supporting document required, checked in person. |
| **Group** | Box office or admin only | Minimum 5 seats in the same reservation, all at the group rate. |
| **Invitation** | Box office or admin only | By authorization. |

- `Tariff.requiresEligibilityProof` indicates whether verification is required.
- Checks are performed **server-side**, never just hidden in the interface.
- `ROLE_CUSTOMER` is **not** a seller role.

---

## 3. Other structuring decisions

### 3.1 Configurable temporary hold

- Hold duration **configurable** by the administrator, **10 minutes by default**.
- `expiresAt` is **stored at hold creation**: changing the policy does not affect holds in progress.

### 3.2 One performance per reservation

- Simplifies the total, expiry, payment, cancellation, refunds, and statistics.
- Two shows → two reservations.

### 3.3 Payment

- Multiple **attempts** possible (failures retained).
- A single successful transaction, covering the full amount in the MVP.
- Payment is recorded **independently** of the reservation (`Payment`), enabling history and refunds.

### 3.4 Refund

- **Full refunds** only in the MVP.
- Each refund is its own record (`Refund`), with its own state - including on failure.
- The total of successful refunds cannot exceed the captured amount.

### 3.5 Ticketing

- One ticket per confirmed item, with a unique number and an **opaque QR token** (no personal data encoded).
- `VALID → USED` is **atomic** (row lock) to prevent double entry.
- Tickets are downloadable as PDF; the box office can print them.

### 3.6 Statistics

- **Occupancy rate** = `SOLD` seats / sellable capacity (`AVAILABLE` + `HELD` + `SOLD`, excluding `BLOCKED`).
- **Gross revenue** = successful payments.
- **Net revenue** = gross − successful refunds.
- Pending or failed payments do not count.

### 3.7 Google Sheets integration (level 4)

- **One row per ticket** (not per reservation), for fine-grained tracking.
- **Asynchronous** synchronization via the **transactional outbox** pattern: the event is written to `outbox_message` in the same transaction as the sale, then processed by a worker.
- **Idempotency key** based on the ticket identifier.
- Retry on failure; a Google Sheets outage neither blocks nor cancels a sale.

### 3.8 Identity and roles

- Roles carried by `User`: `CUSTOMER`, `BOX_OFFICE`, `ADMIN`.
- The reservation's customer (`customer`) is **optional** (anonymous box-office sale); the author (`createdBy`) is always recorded.

---

## 4. Summary of business rules

| ID | Rule |
|---|---|
| BR-01 | A physical seat is sold only once per performance. |
| BR-02 | Availability is guaranteed by the database (transaction + lock), not just by the interface. |
| BR-03 | A reservation covers a single performance. |
| BR-04 | The hold is timestamped at creation; its duration is configurable (10 min by default). |
| BR-05 | A payment failure releases the seat at expiry, not immediately. |
| BR-06 | A late payment is refunded in full and issues no ticket. |
| BR-07 | The price of a confirmed sale is immutable (snapshot). |
| BR-08 | Only an administrator can cancel a confirmed reservation (full refund). |
| BR-09 | A customer can cancel their own unpaid hold. |
| BR-10 | Cancelling a performance cancels and refunds the associated tickets. |
| BR-11 | Reduced, group, and invitation tariffs require seller authorization. |
| BR-12 | The group tariff requires a minimum of 5 seats, all at the group rate. |
| BR-13 | A ticket is unique and can be used only once. |
| BR-14 | Google Sheets is updated asynchronously and idempotently. |

---

## 5. Out of scope (MVP)

These points are **excluded** from the first version to guarantee a solid delivery within seven days. None of them requires redesigning the model.

- Partial refunds and partial cancellation.
- Split payments / multiple successful payments per reservation.
- Online reduced-tariff requests (self-declaration).
- Several performances within one reservation.
- Editing a configuration already used.
- Promo codes, loyalty programs, dynamic pricing.
- Advanced reconciliation with the payment provider.
- Dedicated mobile scanning application.

---

## 6. Assumptions and points of attention

| # | Assumption / attention | Impact |
|---|---|---|
| A1 | The online payment provider is **not** imposed. The MVP provides a simulated payment plus a "cash / terminal" mode at the box office. | To be confirmed before level 4. |
| A2 | Google Sheets is the external API imposed for level 4. | Requires a Google Cloud project (service account). |
| A3 | The venue will initially contain a single `Room`. | The model remains ready for multiple rooms. |
| A4 | Reduced-tariff eligibility is checked **in person**. | No online supporting-document workflow. |
| A5 | QR codes are **opaque tokens** (no personal data encoded). | Compliance and security. |
| A6 | Demo data (fixtures) must be realistic: one venue, several events, several performances, sales. | Level 1. |

---

## 7. Traceability

| Decision | Date | Reference document |
|---|---|---|
| Subject 1 selected | 2026-11-08 | [`README.md`](../README.md) |
| Three-level model (`Place`/`LayoutPlace`/`PerformancePlace`) | 2026-11-09 | [`MODELE.md`](MODELE.md) |
| `Reservation` as the single aggregate | 2026-11-09 | [`MODELE.md`](MODELE.md) |
| Price snapshots | 2026-11-09 | [`MODELE.md`](MODELE.md) |
| Answers to the "Questions to settle" | 2026-11-09 | Section 2 of this document |
| Hold, payment, refund, and tariff rules | 2026-11-09 | Sections 3 and 4 of this document |
