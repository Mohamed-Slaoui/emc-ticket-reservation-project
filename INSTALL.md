# Installation guide

## Prerequisites
- Docker Desktop (with Compose v2) **or** PHP 8.2 + MySQL 8.0 on the host.
- Composer 2.x.

---

## 1. Dockerized app (recommended)

The Docker stack runs both the MySQL database and the PHP application in containers.

```bash
# 1. Stop any previous containers and rebuild from scratch
docker compose down
docker compose build --no-cache
docker compose up -d

# 2. Apply migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 3. Seed demo data
docker compose exec app php bin/console doctrine:fixtures:load --env=dev --no-interaction

# 4. Expire any holds that are already past due (Optional) 
docker compose exec app php bin/console app:holds:expire

```


The application is then available at <http://localhost:8000>.

> **`DEFAULT_URI`**. The `.env` sets `DEFAULT_URI=http://localhost`. When browsing
> through the mapped port (`localhost:8000`), generated redirect URLs may use port 80.
> Override it in `.env.local` with `DEFAULT_URI=http://localhost:8000` if redirects land
> on the wrong port.

---

## 2. Host app (regular installation)

**Prerequisites:** PHP 8.2+ with `pdo_mysql` and `bcmath`, Composer 2, and Docker
(with Compose) for the database.

```bash
# 1. Install the dependencies
composer install

# 2. Start MySQL 8
docker compose up -d database

# 3. Create the database
php bin/console doctrine:database:create

# 4. Apply the migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 5. Load the demo data (venues, rooms, layouts, shows, tariffs, accounts)
php bin/console doctrine:fixtures:load --no-interaction

# 6. Run the application
php -S localhost:8000 -t public
```

The application is then available at <http://localhost:8000>.

---

## 3. Demo accounts (loaded by fixtures)

All accounts use password **`demo1234`**.

| Email | Role | Tariff permissions |
|---|---|---|
| `admin@billetterie.demo` | Administrator | Sells every tariff everywhere |
| `caisse@billetterie.demo` | Box office | Reduced + invitation |
| `vendor@billetterie.demo` | Box office | Reduced only |
| `caisse-limited@billetterie.demo` | Box office | None (full + group only) |
| `client@billetterie.demo` | Customer | — |
| `souad@billetterie.demo` | Customer | — |

---

## 4. Role workflows

### Administrator

1. **Create the venue** — from `/admin/venues`.
2. **Create rooms** — one or more rooms inside the venue.
3. **Create physical places** — numbered seats and standing slots per room.
4. **Create a seating layout version** — a version is unique within its room.
5. **Define zones** and map places to them — a place appears only once per layout.
6. **Create the event** — from `/admin/events`.
7. **Schedule the performance** and select **one** layout — inventory is generated from it.
8. **Configure tariffs and prices** — per performance, per zone, per tariff.
9. **Configure hold duration** — default 10 minutes; applies to new holds only.
10. **Publish the event** — validated before publication (performance, layout, inventory, prices).
11. **Grant tariff permissions** to box-office sellers — from the account form's "Tariff Permissions" section.
12. **Open the dashboard** — occupancy and revenue.
13. **Cancel confirmed reservations** — administrator only, always triggers a full refund.

### Box office

1. **Open `/box-office`** — search on-sale shows by event, venue or room.
2. **Open the seat map** — same seat map the customer sees.
3. **Choose seats** and the tariff you are authorized to apply:
   - Full + group: every seller
   - Reduced: only if `can_sell_reduced_tariff` is granted **and** the supporting document is verified in person
   - Invitation: only if `can_sell_invitation_tariff` is granted
4. **Sell** — choose cash or card terminal. One transaction: reservation confirmed, seats SOLD, payment recorded, one ticket per seat issued.
5. **Search reservations** — by reference or customer email at `/box-office/reservations`.
6. **Print tickets** — one block per seat with its claim code.

### Customer

1. **Browse** the published programme at `/` — no account needed to browse.
2. **Sign up** (`/register`) or sign in — an account is required to hold seats and pay.
3. **Select a show** from the programme, pick seats on the seat map.
4. **Hold** seats — 10 minutes by default to complete payment.
5. **Pay** — simulated payment; card ending in `0000` is refused, all others approved.
6. **Download tickets** — one ticket per seat with a unique QR token.
7. **Cancel unpaid holds** — releases seats back on sale.

---

## 4. Environment variables

See `.env.example`. Real secrets go in `.env.local` (never committed).

---

## 5. Verification

```bash
php vendor/bin/phpunit
php bin/console lint:container
php bin/console doctrine:schema:validate
```
