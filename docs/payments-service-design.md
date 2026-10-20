# payments-service — Diseño

> Estado: implementado (2026-10-19) en `services/payments-service`, `gateway/src/payments` y `web/src/pages/{student,admin}/Payments.jsx`.
> Sustituye al diseño anterior (checkout alojado + webhooks + conciliación SPEI), que no tenía pantallas y no se ajustaba al flujo de la escuela.
> Identificadores en inglés; textos al usuario en español.

## 1. Flujo

**Alumno**
1. Elige un documento (constancia, boleta, kárdex, comprobante de inscripción, pago de inscripción). El precio está en la tabla `document_types`; el cliente nunca manda el monto.
2. Se genera una **referencia de pago** de 13 dígitos (12 aleatorios + dígito Luhn), vigente `PAYMENTS_REFERENCE_TTL_HOURS` (72 h). Si ya tiene una referencia viva para el mismo documento, se le regresa esa.
3. Paga:
   - **Tarjeta**: se valida contra el banco simulado (`test_cards`). Aprobada → alerta **Aprobado** y la referencia queda aprobada. Rechazada (fondos, CVV, vencida, bloqueada, no reconocida) → alerta **Rechazado**; la referencia sigue pendiente y puede reintentar.
   - **Efectivo**: alerta **Pendiente** con la referencia, monto y fecha límite para pagar en caja.
   - **Transferencia**: no disponible por el momento (alerta; el servicio responde 422).
4. Con la referencia aprobada puede ver e imprimir el documento.

**Control Escolar (admin)**
1. Ve las referencias **nuevas** (`reviewed_at` vacío) y contadores.
2. Revisa el método de pago:
   - Efectivo: captura la cantidad recibida; debe cubrir el total (se registra el cambio) y aprueba la referencia.
   - Tarjeta: ve los intentos, número de autorización, cargo y saldo de la tarjeta después del cargo.
   - Transferencia: alerta "no disponible por el momento".
3. Cambia el estado (cada decisión marca la referencia como revisada):

| Estado | Cuándo se permite |
|---|---|
| Aprobado | Hay un pago comprobado (tarjeta validada o efectivo recibido) |
| Rechazado | No hay pago y la referencia ya caducó |
| Pendiente | No hay pago y la referencia sigue vigente |

El profesor no participa.

## 2. Datos (`soa_payments`)

- `document_types`: `code`, `name`, `description`, `price`, `is_active`.
- `charges` (referencias): `reference` único, `student_id` (id de catalog, sin FK entre bases), `document_type_id`, `concept`, `amount` (inmutable), `status` (`pending|approved|rejected`), `payment_method` (`card|cash`), `expires_at`, `paid_at`, `status_reason`, `reviewed_at/by`, `created_by`.
- `test_cards`: banco local de pruebas. `number`, `brand`, `exp_month/year`, `cvv_hash` (bcrypt), `balance`, `status` (`active|blocked`). El número nunca se serializa completo.
- `payment_transactions`: cada intento. Tarjeta: `card_brand`, `card_last4`, `authorization_code`, `card_balance_after`, `decline_reason`. Efectivo: `amount_received`, `change_given`, `recorded_by`.
- `charge_audit_logs`: historial solo de inserción (`created`, `card_declined`, `card_approved`, `cash_selected`, `cash_received`, `status_changed`, `reviewed`).

`expires_at` es `DATETIME` y no `TIMESTAMP`: en MySQL/MariaDB la primera columna `TIMESTAMP NOT NULL` recibe `ON UPDATE CURRENT_TIMESTAMP` y la fecha de vencimiento se movería en cada actualización.

## 3. Reglas

- Pago y cambio de estado dentro de `DB::transaction` con `lockForUpdate` sobre la referencia (y la tarjeta), para que dos pagos simultáneos no se apliquen dos veces.
- Montos comparados en centavos enteros (`Money`).
- Una referencia aprobada no se paga dos veces (409); una caducada o rechazada no recibe pagos (409).
- Seguridad: el número de tarjeta pasa por gateway → servicio solo para validarlo; se guardan los últimos 4 dígitos y la marca. Es una simulación local: no debe usarse con tarjetas reales.

## 4. Composición en el gateway

- `/api/payments/charges*` agrega nombre y matrícula del alumno desde catalog.
- `/api/me/documents/:id` solo responde si payments dice que la referencia está aprobada; luego compone catalog (alumno, periodo) + enrollment (calificaciones).
