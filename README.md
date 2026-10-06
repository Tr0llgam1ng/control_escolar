# Control Escolar — SOA

School system built as a **Service-Oriented Architecture**:

```
 ┌──────────────┐      ┌───────────────────────┐      ┌───────────────────────────────┐
 │  React app   │ ───▶ │  API Gateway (NestJS) │ ───▶ │ Servicio 1 · auth-service      │ :8001  DB soa_auth
 │  (web, :5173)│ HTTP │  :3000/api            │ HTTP │ Servicio 2 · catalog-service   │ :8002  DB soa_catalog
 └──────────────┘ JWT- │  - validates token    │  +   │ Servicio 3 · enrollment-service│ :8003  DB soa_enrollment
                  like │  - checks roles       │ key  └───────────────┬───────────────┘
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
| Nest consumes Laravel | `gateway` (NestJS) | |
| Front consumes Nest | `web` (React + Tailwind) | |

## Requirements

PHP 8.3+, Composer, Node 20+, MySQL (XAMPP/Laragon works).

## Setup (Windows)

1. Create the 3 databases: run `create_databases.sql` (phpMyAdmin → SQL).
2. If your MySQL user has a password, set `DB_PASSWORD` in each `services/*/.env.example` (or in `.env` after step 3).
3. Run `setup.bat` (composer install, `.env`, key, migrations + seeders, npm install).
4. Run `start-all.bat` and open http://localhost:5173

Manual equivalent per Laravel service: `composer install`, `copy .env.example .env`,
`php artisan key:generate`, `php artisan migrate --seed`, `php artisan serve --port=800X`.
Gateway: `npm install`, `copy .env.example .env`, `npm run start:dev`. Web: `npm install`, `npm run dev`.

> `SERVICE_KEY` must be identical in the 3 services and in `gateway/.env`.

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

**gateway (3000)** — what the app consumes

`POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`, `PUT /api/auth/password`,
`/api/periods`, `/api/subjects`, `/api/teachers`, `/api/students`, `/api/courses` (CRUD),
`GET /api/courses/:id/roster`, `POST /api/courses/:id/enrollments`, `DELETE /api/enrollments/:id`,
`PUT /api/courses/:id/grades`, `PATCH /api/courses/:id/settings`, `GET /api/me/courses` (teacher),
`GET /api/me/report` (student), `GET /api/dashboard` (admin), `GET /api/health` (public).

### SOA patterns you can point out

- **Centralized authentication**: only auth-service issues/validates tokens (stored hashed, sha256).
- **API Gateway**: single entry point, role guard, CORS, error pass-through (`service` field says which one failed).
- **Composition**: `/api/me/report` joins catalog + enrollment data; `/api/courses` adds occupancy.
- **Orchestration with compensation (saga)**: creating a teacher/student creates the account in auth-service and the record in catalog-service; if the second step fails the account is deleted.
- **Service-to-service call**: enrollment-service asks catalog-service for the course, the period window and the teacher.
- **Fault tolerance**: if a service is down the gateway answers 503 for that service only; `/api/health` shows the status of each one (also visible on the login screen).
- **Database per service**: `soa_auth`, `soa_catalog`, `soa_enrollment`.

## Tests

```bash
cd services/auth-service && php artisan test        # 5 tests
cd services/catalog-service && php artisan test     # 7 tests
cd services/enrollment-service && php artisan test  # 7 tests (catalog faked with Http::fake)
```
