# memberships-service — Diseño (membresías)

> Estado: implementado (2026-11-16) en `services/memberships-service` (Servicio 5, puerto 8005, BD `soa_memberships`),
> `gateway/src/memberships` y `web/src/pages/{student,teacher,admin}`.
> Identificadores en inglés; textos al usuario en español. Las membresías son solo para el rol **alumno**:
> admin y profesor nunca quedan limitados por un plan.

## 1. Qué se agregó

| Requisito | Implementación |
|---|---|
| Autorización | Además del rol (RolesGuard), el gateway revisa el **plan** del alumno: `PlanGuard` + `@RequiresFeature()` y `QuotaInterceptor` + `@UsesQuota()`. memberships-service vuelve a validar (defensa en profundidad). |
| 3 paquetes como membresía | **Bronze, Silver y Gold** (+ **Básico** gratuito por defecto). Se compran con créditos y duran 30 días. |
| Filtro de accesos y funciones | Cada plan tiene una fila por función (`plan_features`): incluida o no, con límite por ciclo o ilimitada. El admin la edita en *Membresías → Planes*. |
| Créditos (dinero → créditos) | El alumno compra un **paquete de créditos** como una referencia de pago normal (tarjeta del banco simulado o efectivo en caja). Al aprobarse, memberships-service los acredita. Todo movimiento queda en un **ledger** inmutable. |
| 2 módulos con créditos | **Asesorías con profesor** y **Análisis académico**. |
| Tiempo (días, horas, minutos) | El plan dura **días**; **Cubículos de estudio** se reservan por **horas** y la **Biblioteca digital** se usa por **minutos** (sesión con cronómetro). |

## 2. Planes (valores iniciales del seeder)

| Función | Básico | Bronze | Silver | Gold |
|---|---|---|---|---|
| Precio | Gratis | 120 créditos / 30 días | 250 / 30 días | 450 / 30 días |
| Referencias de trámites por ciclo | 2 | 4 | 8 | Ilimitado |
| Facturas CFDI por ciclo | No | No | 3 | Ilimitado |
| Pedidos de cafetería por ciclo | 3 | 10 | 30 | Ilimitado |
| Pronóstico del clima (horas y 5 días) | No | No | Sí | Sí |
| Regalo al comprar créditos | 0% | 0% | 5% | 15% |
| Asesorías por ciclo / descuento | No | 2 / 0% | 4 / 20% | Ilimitado / 40% |
| Análisis académicos por ciclo / costo | No | 1 / 30 cr | 3 / 15 cr | Ilimitado / gratis |
| Cubículos: horas incluidas / máx. por reservación / anticipación | No | 2 h / 1 h / 2 días | 6 h / 2 h / 7 días | 15 h / 4 h / 14 días |
| Biblioteca digital: minutos incluidos | 30 | 120 | 600 | 1800 |

**Ciclo**: el de la suscripción (`starts_at → ends_at`); en Básico, el mes calendario. Los contadores de uso
(`usage_counters`) se guardan por inicio de ciclo, así que se reinician solos.

## 3. Autorización por plan (flujo)

```
App → Gateway: AuthGuard (token) → RolesGuard (rol) → PlanGuard (@RequiresFeature)
                                 → QuotaInterceptor (@UsesQuota): POST /access/consume ──▶ memberships-service
                                       └─ llama al servicio destino; si falla → POST /access/release (compensación)
```

| Ruta del gateway | Regla |
|---|---|
| `POST /api/me/charges` (trámite) | Consume `paperwork.requests`. Si ya existe una referencia viva del mismo documento la regresa sin consumir. |
| `POST /api/me/charges/:id/invoice` | `@UsesQuota('invoicing.invoices')` — si el PAC falla, el uso se devuelve. |
| `POST /api/me/cafeteria/orders` | `@UsesQuota('cafeteria.orders')` |
| `GET /api/weather` | Sin `weather.forecast` se oculta el pronóstico (`forecast_locked: true`). |
| `POST /api/me/room-bookings`, `/me/tutoring-sessions`, `/me/analytics-reports` | `@RequiresFeature(...)`; memberships-service además cobra créditos/tiempo. |

Respuestas de memberships-service (el gateway las pasa sin cambios):

| Código | HTTP | Cuándo |
|---|---|---|
| `PLAN_REQUIRED` | 403 | La función no está en el plan (`upgrade_to` lista los planes que la incluyen) |
| `PLAN_LIMIT` | 403 | Se acabó el límite del ciclo |
| `INSUFFICIENT_CREDITS` | 422 | El monedero no alcanza (`needed`, `balance`) |
| `INSUFFICIENT_TIME` | 422 | No alcanzan las horas/minutos |

## 4. Créditos (accountability)

1. Alumno elige paquete → **gateway** orquesta: payments-service `POST /charges {document_type_id, category: credits}` → memberships-service `POST /credit-purchases {charge_id, student_id, pack_code}`.
2. Paga la referencia (tarjeta o efectivo) como cualquier trámite.
3. memberships-service pregunta a payments-service `GET /charges/{id}/status` (endpoint **interno**, `X-Service-Name: memberships-service`) y, si está aprobada y el **monto coincide** con el precio del paquete, acredita una sola vez (`charge_id` único). Si el monto no coincide → **en revisión**; el admin acredita o rechaza con motivo.
4. Tipo de cambio: $1 = 1 crédito; paquetes: 100 ($100), 550 ($500, +10%), 1200 ($1000, +20%), más el regalo del plan.

`wallets.balance` solo lo cambia `CreditLedger`, y cada cambio escribe una línea en `credit_transactions`
(`purchase | bonus | spend | refund | adjustment`, monto con signo, `balance_after`, origen y quién lo hizo). El saldo nunca queda negativo.

Cambiar de plan reembolsa la parte no usada del plan anterior (proporcional). Al vencer, el plan se **renueva solo**
si hay créditos; si no, el alumno pasa a Básico. Desactivar la renovación conserva el plan hasta que termina.

## 5. Módulos

| Módulo | Tipo | Reglas |
|---|---|---|
| Asesorías con profesor | Créditos | 60 créditos base − descuento del plan; con ≥ 12 h de anticipación, 08:00–20:00. El profesor confirma, rechaza (reembolso completo) o marca impartida. Cancelar con ≥ 12 h → reembolso. |
| Análisis académico | Créditos | El gateway compone la boleta (catalog + enrollment) y memberships calcula promedios, riesgo por materia y la calificación necesaria en las unidades restantes. |
| Cubículos de estudio | Horas | Bloques de 30 min, 07:00–21:00, sin traslapes por cubículo ni del mismo alumno. Primero usa las horas del plan y luego las extra. Cancelar antes de iniciar devuelve el tiempo. Hora extra: 25 créditos. |
| Biblioteca digital | Minutos | Abrir sesión toma el tiempo disponible como límite; al cerrar se cobran los minutos usados (redondeo hacia arriba). Al llegar al límite se cierra sola. 30 min extra: 10 créditos. |

El tiempo extra comprado no caduca; el incluido se reinicia cada ciclo. Todo movimiento de tiempo queda en `time_movements`.

## 6. Contrato de memberships-service (todas con `X-Service-Key`)

| Método | Ruta | Quién |
|---|---|---|
| GET | /plans · PUT /plans/{id} | cualquiera · admin |
| GET | /me/membership · POST /me/subscription {plan_code} · POST /me/subscription/cancel · /resume | alumno |
| GET/POST | /access/check?feature= · /access/consume · /access/release | gateway (en nombre del alumno) |
| GET | /credit-packs · /me/wallet | alumno |
| POST | /credit-purchases · /credit-purchases/{id}/sync | alumno |
| GET/POST | /credit-purchases · /credit-purchases/{id}/resolve · /wallets · /wallets/{userId}/adjust · /credit-transactions · /summary · /subscriptions | admin |
| GET/POST | /me/time · /me/time/purchase {module, quantity} | alumno |
| GET/POST | /study-rooms · /study-rooms/{id}/bookings?date= · /room-bookings · /room-bookings/{id}/cancel | alumno / admin |
| GET/POST | /library/resources · /library-sessions · /library-sessions/{id}/close | alumno / admin |
| GET/POST/PATCH | /tutoring/quote · /tutoring-sessions · /tutoring-sessions/{id}/cancel · PATCH /tutoring-sessions/{id} | alumno · profesor (resuelve su expediente en catalog-service) · admin |
| GET/POST | /analytics/quote · /analytics-reports · /analytics-reports/{id} | alumno |

Pruebas: `cd services/memberships-service && php -d extension=pdo_sqlite vendor/bin/phpunit` (27 pruebas).
