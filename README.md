# Control Escolar — SOA

School system built as a **Service-Oriented Architecture**:

```
 ┌──────────────┐      ┌───────────────────────┐      ┌───────────────────────────────┐
 │  React app   │ ───▶ │  API Gateway (NestJS) │ ───▶ │ Servicio 1 · auth-service      │ :8001  DB soa_auth
 │  (web, :5173)│ HTTP │  :3000/api            │ HTTP │ Servicio 2 · catalog-service   │ :8002  DB soa_catalog
 └──────────────┘ JWT- │  - validates token    │  +   │ Servicio 3 · enrollment-service│ :8003  DB soa_enrollment
                  like │  - checks roles       │ key  │ Servicio 4 · payments-service  │ :8004  DB soa_payments
                       │  - checks the plan    │      │ Servicio 5 · memberships-svc   │ :8005  DB soa_memberships
                       │    (memberships)      │      └───────────────┬───────────────┘
                 Bearer│  - composes responses │                      │ enrollment-service calls
                       │  - orchestrates sagas │                      ▼ catalog-service over HTTP
                       └───────────────────────┘
```

The React app **only** knows the gateway. The gateway **only** talks to the services through their
HTTP contracts. **No service reads another service's database.**

## Mapping to the reservation example

| Reservation system | This project | Laravel project |
|---|---|---|
| Service 1: authentication | Login, token issue/validation, user accounts and roles | `services/auth-service` |
| Service 2: CRUD of rooms | CRUD of periods, subjects, teachers, students and **courses** (class sections) | `services/catalog-service` |
| Service 3: booking logic | **Enrollment** (capacity, duplicates, active course/student, period not finished) and **grades** (assigned teacher, capture window, 0–10, final grade) | `services/enrollment-service` |
| Service 5: memberships | Plans **Basic / Bronze / Silver / Gold** bought with credits; authorization by plan (limits per cycle); credits bought with money (ledger); credit modules (tutoring, academic analysis) and time modules (study rooms by the hour, digital library by the minute) | `services/memberships-service` |
| Service 4: payments | Paperwork requests (constancia, kárdex, boleta, inscripción) with a payment **reference** (12 random digits + Luhn), card payments against a local bank simulator, cash at the window, admin review | `services/payments-service` |
| Nest consumes Laravel | `gateway` (NestJS) | |
| Front consumes Nest | `web` (React + Tailwind) | |

## Requirements

PHP 8.3+, Composer, Node 20+, MySQL (XAMPP/Laragon works).

## Setup (Windows)

1. Create the 5 databases: run `create_databases.sql` (phpMyAdmin → SQL).
2. If your MySQL user has a password, set `DB_PASSWORD` in each `services/*/.env.example` (or in `.env` after step 3).
3. Run `setup.bat` (composer install, `.env`, key, migrations + seeders, npm install).
4. Run `start-all.bat` and open http://localhost:5173

Manual equivalent per Laravel service: `composer install`, `copy .env.example .env`,
`php artisan key:generate`, `php artisan migrate --seed`, `php artisan serve --port=800X`.
Gateway: `npm install`, `copy .env.example .env`, `npm run start:dev`. Web: `npm install`, `npm run dev`.

> `SERVICE_KEY` must be identical in the 5 services and in `gateway/.env`.

**Already installed before memberships?** Run `create_databases.sql` (creates only `soa_memberships`) and then
`setup-memberships.bat`: it migrates/seeds payments-service (credit packs) and installs memberships-service **without erasing data**.

### Demo accounts (password `password`)

| Role | E-mail |
|---|---|
| Control Escolar (admin) | admin@escolar.test |
| Profesor | profesor@escolar.test |
| Alumno | alumno@escolar.test |

## Service contracts

All service routes are under `/api` and require the header `X-Service-Key`. The gateway forwards the
authenticated user as `X-User-Id` / `X-User-Role`; services re-check permissions (defense in depth).

**auth-service (8001)**

| Method | Path | Description |
|---|---|---|
| POST | /auth/login | `{email, password}` → `{token, expires_at, user}` |
| POST | /auth/validate | `{token}` → `{valid, user}` (used by the gateway on every request) |
| POST | /auth/logout | `{token}` → revokes it |
| GET/POST/PUT/DELETE | /users | account management (admin) |

**catalog-service (8002)**

| Method | Path | Description |
|---|---|---|
| CRUD | /periods, /subjects, /teachers, /students, /courses | admin writes, everyone reads |
| GET | /periods/current | current period (+ `grade_capture_open`) |
| GET | /teachers/by-user/{id}, /students/by-user/{id} | resolve the logged user's record |
| PATCH | /courses/{id}/settings | assigned teacher: status (Activa/No activa) and units |

**enrollment-service (8003)** — calls catalog-service

| Method | Path | Description |
|---|---|---|
| POST | /enrollments, /enrollments/bulk | enroll (admin) with all business rules |
| DELETE | /enrollments/{id} | unenroll |
| GET | /enrollments/counts?course_ids= | occupancy per course |
| GET | /courses/{id}/roster | class list with grades (admin / assigned teacher) |
| PUT | /courses/{id}/grades | `{grades:[{enrollment_id, unit, score}]}` |
| GET | /students/{id}/enrollments | report of a student (admin / that student) |

**payments-service (8004)** — design in `docs/payments-service-design.md`

| Method | Path | Who | Description |
|---|---|---|---|
| GET | /document-types | any user | paperwork catalog with prices |
| POST | /charges | student (`{document_type_id}`) / admin (+ `student_id`) | generates a reference; reuses a live pending one for the same document |
| GET | /students/{id}/charges | admin / that student | references, payments and totals |
| GET | /charges/{id}, /charges/by-reference/{ref} | admin / owner student | with payments and history (reference validated with Luhn first) |
| POST | /charges/{id}/pay | owner student | `{method: card\|cash\|transfer, card?}` → `{result: approved\|rejected\|pending, message}`; transfer → 422 "no disponible" |
| GET | /charges | admin | filters `status, method, new=1, expired=1, student_id, q` |
| GET | /charges/summary | admin | counters: new, pending, expired, approved, rejected, collected |
| POST | /charges/{id}/cash | admin | `{amount_received}` must cover the total; records the change and approves |
| PATCH | /charges/{id}/status | admin | `approved` only with a verified payment · `rejected` only if expired and unpaid · `pending` only if alive and unpaid |
| POST | /charges/{id}/review | admin | marks the reference as reviewed |
| GET | /charges/{id}/status | **internal only** (`X-Service-Name`, no user) | `{status, paid, paid_at}` |

**memberships-service (8005)** — design in `docs/memberships-design.md`. Only students have memberships; admin and teacher are never limited by a plan.

| Method | Path | Who | Description |
|---|---|---|---|
| GET / PUT | /plans, /plans/{id} | any / admin | plans with every feature (quota per cycle, flag, included time, %, cost); admin edits price, days and limits |
| GET | /me/membership | student | plan, cycle, usage of each feature, credit balance |
| POST | /me/subscription {plan_code}, /me/subscription/cancel, /resume | student | buy / change plan with credits (unused days refunded), auto renewal on/off |
| GET / POST | /access/check?feature=, /access/consume, /access/release | gateway | authorization by plan: `403 PLAN_REQUIRED` / `PLAN_LIMIT`; release = compensation |
| GET / POST | /credit-packs, /me/wallet, /credit-purchases, /credit-purchases/{id}/sync | student | money → credits: a paid credit-pack reference (payments-service) is exchanged once |
| GET / POST | /wallets, /wallets/{userId}/adjust, /credit-transactions, /credit-purchases, /credit-purchases/{id}/resolve, /summary, /subscriptions | admin | wallets, ledger (accountability), purchases in review |
| GET / POST | /me/time, /me/time/purchase | student | days left of the plan, hours (study rooms) and minutes (library); extra time with credits |
| GET / POST | /study-rooms, /study-rooms/{id}/bookings, /room-bookings, /room-bookings/{id}/cancel | student / admin | **time module 1**: bookings by the hour |
| GET / POST | /library/resources, /library-sessions, /library-sessions/{id}/close | student / admin | **time module 2**: sessions charged by the minute |
| GET / POST / PATCH | /tutoring/quote, /tutoring-sessions, /tutoring-sessions/{id}/cancel, PATCH /tutoring-sessions/{id} | student / teacher / admin | **credits module 1**: tutoring with a teacher |
| GET / POST | /analytics/quote, /analytics-reports, /analytics-reports/{id} | student | **credits module 2**: academic analysis |

payments-service changes for memberships: `document_types.category` (`paperwork` | `credits`), three credit packs
(`creditos_100`, `creditos_550`, `creditos_1200`), `GET /document-types?category=`, `POST /charges {category}` and the internal
`GET /charges/{id}/status` now returns `document_type_code`, `category` and `amount`. Add `memberships-service` to `PAYMENTS_INTERNAL_CALLERS`.

**Invoicing (CFDI 4.0, PAC TimbraCFDI)** — design in `docs/invoicing-design.md`

| Method | Path | Who | Description |
|---|---|---|---|
| GET | /invoicing/catalogs | any user | SAT regimes, CFDI uses, cancellation reasons |
| GET | /charges/{id}/invoice | admin / owner student | invoices of the reference, `can_invoice`, last fiscal data used |
| POST | /charges/{id}/invoice | admin / owner student | `{rfc, name, zip, regime, cfdi_use}` → builds the CFDI and stamps it at the PAC (only approved references) |
| GET | /invoices | admin | filters `status, student_id, q` |
| GET | /invoices/{id}, /invoices/{id}/xml | admin / owner student | printable data (QR, seals, original chain) / stamped XML |
| POST | /invoices/{id}/cancel | admin | `{reason: 01\|02\|03\|04, replacement_uuid?}` |
| GET | /invoices/{id}/sat-status | admin | live status at the SAT |

`php artisan invoicing:check` (in `services/payments-service`) checks the PAC token; `--stamp` stamps a test invoice.

**Test cards** (local bank, table `test_cards`; any holder name):

| Number | Exp. | CVV | Result |
|---|---|---|---|
| 4111 1111 1111 1111 | 12/30 | 123 | approved (balance $20,000) |
| 5555 5555 5555 4444 | 11/29 | 321 | approved (balance $5,000) |
| 4000 0000 0000 0002 | 12/30 | 123 | rejected: insufficient funds |
| 4000 0000 0000 0069 | 01/24 | 123 | rejected: expired card |
| 4000 0000 0000 0119 | 12/30 | 123 | rejected: blocked card |

A reference lives `PAYMENTS_REFERENCE_TTL_HOURS` (72 h by default).

**gateway (3000)** — what the app consumes

`POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`, `PUT /api/auth/password`,
`/api/periods`, `/api/subjects`, `/api/teachers`, `/api/students`, `/api/courses` (CRUD),
`GET /api/courses/:id/roster`, `POST /api/courses/:id/enrollments`, `DELETE /api/enrollments/:id`,
`PUT /api/courses/:id/grades`, `PATCH /api/courses/:id/settings`, `GET /api/me/courses` (teacher),
`GET /api/me/report` (student), `GET /api/dashboard` (admin), `GET /api/health` (public).
`GET /api/weather` (any role; `?lat=&lon=` or `?city=`): current weather + next 24 h + 5-day forecast from **OpenWeatherMap**,
called by the gateway with `OPENWEATHER_API_KEY` (never sent to the browser) and cached 10 minutes. Shown in the top-bar weather widget.
Payments: `GET /api/payments/document-types` (student, admin); student: `GET|POST /api/me/charges`, `GET /api/me/charges/:id`,
`POST /api/me/charges/:id/pay`, `GET /api/me/documents/:id` (paid document: payments + catalog + enrollment);
admin: `GET|POST /api/payments/charges`, `GET /api/payments/charges/summary`, `GET /api/payments/charges/:id`,
`PATCH /api/payments/charges/:id/status`, `POST /api/payments/charges/:id/review`, `POST /api/payments/charges/:id/cash`.
Invoicing: `GET /api/payments/invoicing/catalogs`; student: `GET|POST /api/me/charges/:id/invoice`, `GET /api/me/invoices/:id`, `GET /api/me/invoices/:id/xml`;
admin: `GET /api/payments/invoices`, `GET|POST /api/payments/charges/:id/invoice`, `GET /api/payments/invoices/:id`, `GET /api/payments/invoices/:id/xml`,
`POST /api/payments/invoices/:id/cancel`, `GET /api/payments/invoices/:id/sat-status`.
Memberships: `GET /api/memberships/plans`; student: `GET /api/me/membership`, `POST /api/me/subscription` (+ `/cancel`, `/resume`),
`GET /api/memberships/credit-packs`, `GET /api/me/wallet`, `POST /api/me/credit-purchases` (orchestration: payments reference + memberships purchase),
`POST /api/me/credit-purchases/:id/sync`, `GET /api/me/time`, `POST /api/me/time/purchase`, `GET /api/memberships/study-rooms`, `GET|POST /api/me/room-bookings`,
`POST /api/me/room-bookings/:id/cancel`, `GET /api/memberships/library/resources`, `GET|POST /api/me/library-sessions`, `POST /api/me/library-sessions/:id/close`,
`GET /api/me/tutoring/quote`, `GET /api/me/tutoring/options`, `GET|POST /api/me/tutoring-sessions`, `POST /api/me/tutoring-sessions/:id/cancel`,
`GET /api/me/analytics/quote`, `GET|POST /api/me/analytics-reports`, `GET /api/me/analytics-reports/:id`; teacher: `GET /api/me/tutoring-requests`, `PATCH /api/me/tutoring-requests/:id`;
admin: `GET /api/memberships/summary`, `PUT /api/memberships/plans/:id`, `GET /api/memberships/subscriptions`, `GET /api/memberships/wallets`,
`POST /api/memberships/wallets/:userId/adjust`, `GET /api/memberships/transactions`, `GET /api/memberships/purchases`, `POST /api/memberships/purchases/:id/resolve`,
`GET /api/memberships/tutoring-sessions`, `GET /api/memberships/room-bookings`.
Plan filter on existing routes: `POST /api/me/charges` spends `paperwork.requests`, `POST /api/me/charges/:id/invoice` spends `invoicing.invoices`,
`POST /api/me/cafeteria/orders` spends `cafeteria.orders` (students only), `GET /api/weather` hides the forecast without `weather.forecast`.

### SOA patterns you can point out

- **Centralized authentication**: only auth-service issues/validates tokens (stored hashed, sha256).
- **API Gateway**: single entry point, role guard, CORS, error pass-through (`service` field says which one failed).
- **Composition**: `/api/me/report` joins catalog + enrollment data; `/api/courses` adds occupancy.
- **Orchestration with compensation (saga)**: creating a teacher/student creates the account in auth-service and the record in catalog-service; if the second step fails the account is deleted.
- **Service-to-service call**: enrollment-service asks catalog-service for the course, the period window and the teacher.
- **Fault tolerance**: if a service is down the gateway answers 503 for that service only; `/api/health` shows the status of each one (also visible on the login screen).
- **Database per service**: `soa_auth`, `soa_catalog`, `soa_enrollment`, `soa_payments`, `soa_memberships`.
- **External provider behind one service**: only payments-service talks to the PAC (TimbraCFDI); the gateway and the app never see its token.
- **Authorization by plan (memberships)**: after the token (AuthGuard) and the role (RolesGuard), `PlanGuard` / `QuotaInterceptor` ask memberships-service whether the student's plan allows the call; the quota is taken before routing and **given back if the routed service fails** (compensation).
- **Orchestration of 2 services for money → credits**: the gateway creates the reference in payments-service and registers it in memberships-service; memberships-service asks payments-service (internal endpoint) whether it was paid and credits it once (ledger).
- **Composition across 3 services**: `/api/me/documents/:id` only returns a document when payments-service says the reference is approved, then joins catalog + enrollment data.

## Tests

The tests use SQLite in memory. XAMPP ships `pdo_sqlite` disabled; either enable `extension=pdo_sqlite` in `php.ini`
or load it per run as below.

```bash
cd services/auth-service && php -d extension=pdo_sqlite vendor/bin/phpunit        # 5 tests
cd services/catalog-service && php -d extension=pdo_sqlite vendor/bin/phpunit     # 7 tests
cd services/enrollment-service && php -d extension=pdo_sqlite vendor/bin/phpunit  # 7 tests (catalog faked with Http::fake)
cd services/payments-service && php -d extension=pdo_sqlite vendor/bin/phpunit    # 41 tests (references, card/cash, admin rules, credit packs, invoicing with the PAC faked)
cd services/memberships-service && php -d extension=pdo_sqlite vendor/bin/phpunit # 27 tests (plans, authorization by plan, credits, time and credit modules)
# payments against MySQL/MariaDB (create the database soa_payments_test first):
cd services/payments-service && DB_CONNECTION=mysql DB_DATABASE=soa_payments_test vendor/bin/phpunit
```
