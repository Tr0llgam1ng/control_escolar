# Control Escolar — SOA

School system built as a **Service-Oriented Architecture**:

```
 ┌──────────────┐      ┌───────────────────────┐      ┌───────────────────────────────┐
 │  React app   │ ───▶ │  API Gateway (NestJS) │ ───▶ │ Servicio 1 · auth-service      │ :8001  DB soa_auth
 │  (web, :5173)│ HTTP │  :3000/api            │ HTTP │ Servicio 2 · catalog-service   │ :8002  DB soa_catalog
 └──────────────┘ JWT- │  - validates token    │  +   │ Servicio 3 · enrollment-service│ :8003  DB soa_enrollment
                  like │  - checks roles       │ key  │ Servicio 4 · payments-service  │ :8004  DB soa_payments
                       │                       │      └───────────────┬───────────────┘
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
| Service 4: payments | Paperwork requests (constancia, kárdex, boleta, inscripción) with a payment **reference** (12 random digits + Luhn), card payments against a local bank simulator, cash at the window, admin review | `services/payments-service` |
| Nest consumes Laravel | `gateway` (NestJS) | |
| Front consumes Nest | `web` (React + Tailwind) | |

## Requirements

PHP 8.3+, Composer, Node 20+, MySQL (XAMPP/Laragon works).

## Setup (Windows)

1. Create the 4 databases: run `create_databases.sql` (phpMyAdmin → SQL).
2. If your MySQL user has a password, set `DB_PASSWORD` in each `services/*/.env.example` (or in `.env` after step 3).
3. Run `setup.bat` (composer install, `.env`, key, migrations + seeders, npm install).
4. Run `start-all.bat` and open http://localhost:5173

Manual equivalent per Laravel service: `composer install`, `copy .env.example .env`,
`php artisan key:generate`, `php artisan migrate --seed`, `php artisan serve --port=800X`.
Gateway: `npm install`, `copy .env.example .env`, `npm run start:dev`. Web: `npm install`, `npm run dev`.

> `SERVICE_KEY` must be identical in the 4 services and in `gateway/.env`.

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
Payments: `GET /api/payments/document-types` (student, admin); student: `GET|POST /api/me/charges`, `GET /api/me/charges/:id`,
`POST /api/me/charges/:id/pay`, `GET /api/me/documents/:id` (paid document: payments + catalog + enrollment);
admin: `GET|POST /api/payments/charges`, `GET /api/payments/charges/summary`, `GET /api/payments/charges/:id`,
`PATCH /api/payments/charges/:id/status`, `POST /api/payments/charges/:id/review`, `POST /api/payments/charges/:id/cash`.

### SOA patterns you can point out

- **Centralized authentication**: only auth-service issues/validates tokens (stored hashed, sha256).
- **API Gateway**: single entry point, role guard, CORS, error pass-through (`service` field says which one failed).
- **Composition**: `/api/me/report` joins catalog + enrollment data; `/api/courses` adds occupancy.
- **Orchestration with compensation (saga)**: creating a teacher/student creates the account in auth-service and the record in catalog-service; if the second step fails the account is deleted.
- **Service-to-service call**: enrollment-service asks catalog-service for the course, the period window and the teacher.
- **Fault tolerance**: if a service is down the gateway answers 503 for that service only; `/api/health` shows the status of each one (also visible on the login screen).
- **Database per service**: `soa_auth`, `soa_catalog`, `soa_enrollment`, `soa_payments`.
- **Composition across 3 services**: `/api/me/documents/:id` only returns a document when payments-service says the reference is approved, then joins catalog + enrollment data.

## Tests

The tests use SQLite in memory. XAMPP ships `pdo_sqlite` disabled; either enable `extension=pdo_sqlite` in `php.ini`
or load it per run as below.

```bash
cd services/auth-service && php -d extension=pdo_sqlite vendor/bin/phpunit        # 5 tests
cd services/catalog-service && php -d extension=pdo_sqlite vendor/bin/phpunit     # 7 tests
cd services/enrollment-service && php -d extension=pdo_sqlite vendor/bin/phpunit  # 7 tests (catalog faked with Http::fake)
cd services/payments-service && php -d extension=pdo_sqlite vendor/bin/phpunit    # 23 tests (references, card/cash, admin rules)
# payments against MySQL/MariaDB (create the database soa_payments_test first):
cd services/payments-service && DB_CONNECTION=mysql DB_DATABASE=soa_payments_test vendor/bin/phpunit
```
