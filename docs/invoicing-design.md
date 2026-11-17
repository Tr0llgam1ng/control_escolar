# Facturación electrónica (CFDI 4.0) — Diseño

> Estado: implementado (2026-11-02) en `services/payments-service/app/Invoicing`, `gateway/src/invoicing` y
> `web/src/components/invoicing.jsx`, `web/src/pages/InvoiceView.jsx`, `web/src/pages/admin/Invoices.jsx`.
> PAC: **TimbraCFDI** de ProFact — https://developers.timbracfdi33.mx/reference

## 1. Dónde vive

La facturación es parte de **payments-service** (Servicio 4): una factura solo existe para una referencia pagada y
los montos, el método de pago y el alumno ya están en su base (`soa_payments`). Ningún otro servicio habla con el PAC.

```
React ──▶ Gateway (NestJS) ──▶ payments-service ──HTTPS + Bearer──▶ PAC TimbraCFDI ──▶ SAT
          roles, rutas          arma el CFDI 4.0,                 sella (autosellado) y
          nombre del alumno     guarda XML/QR/UUID                 timbra
```

## 2. Flujo

1. La referencia queda **aprobada** (tarjeta o efectivo).
2. El alumno (o Control Escolar por él) abre **Factura** y captura sus datos fiscales: RFC, nombre, C.P., régimen y uso del CFDI.
   Sin RFC se usa `XAXX010101000` → régimen 616, uso S01 y el C.P. de la escuela.
3. payments-service:
   - bloquea la referencia y verifica que esté aprobada y sin factura vigente;
   - arma el XML CFDI 4.0 **sin sello** (`CfdiBuilder`): Ingreso, PUE, MXN, `FormaPago` 01 (efectivo) o 04 (tarjeta);
   - lo envía en Base64 a `POST /api/v2/Timbrado/TimbraCFDI`;
   - guarda el XML timbrado, UUID, QR y cadena original (`invoices`).
4. El alumno ve la **representación impresa** (con QR y sellos), la imprime/guarda en PDF y descarga el XML.
5. Control Escolar ve todas las facturas en **Facturas** y puede **cancelar** (motivos SAT 01–04; el 01 pide el UUID que sustituye).
   Después de cancelar se puede emitir otra factura para la misma referencia.

## 3. Impuestos

El precio de `document_types` es el **total pagado**. Con `INVOICING_TAX_MODE=iva` se desglosa el IVA:
base = total / 1.16 ajustada al centavo para que `base + IVA == total` (ej. $150.00 → 129.31 + 20.69).
Con `exento` el traslado va como `TipoFactor="Exento"` (servicios educativos, art. 15 LIVA).

## 4. Datos (`invoices`)

`charge_id`, `student_id`, `status` (`stamping` transitorio · `stamped` · `cancelled` · `failed`), `uuid` único, `series`, `folio` (= id),
datos del receptor, `payment_form`, `subtotal/tax/total`, `xml`, `qr_png` (Base64), `original_chain`, `stamped_at`,
datos de cancelación y `error_code/error_message` cuando el PAC rechaza. Cada evento queda en `charge_audit_logs`
(`invoice_stamped`, `invoice_failed`, `invoice_cancelled`).

## 5. Reglas

- Solo referencias `approved`; una factura `stamped` por referencia (409 si ya hay una).
- El monto nunca lo manda el cliente (`amount` prohibido).
- RFC con formato SAT; régimen compatible con persona física (13) o moral (12); uso CFDI compatible con el régimen.
- Si el PAC rechaza (error del SAT) se responde 422 con `[CódigoSAT] mensaje`, la factura queda `failed` y el pago no cambia.
  Si el PAC no responde → 503.
- La llamada al PAC va fuera de la transacción; un registro `stamping` evita dos timbrados simultáneos (caduca a los 5 min).

## 6. PAC TimbraCFDI

| Operación | Ruta (`{url}/v2/Timbrado/…`) | Uso |
|---|---|---|
| TimbraCFDI | `{XmlComprobanteBase64, IdComprobante}` | timbrar |
| CancelaCFDI | `{RfcEmisor, FolioFiscal, MotivoCancelacion, FolioFiscalSustitucion}` | cancelar |
| ConsultaEstatusSat | `{FolioFiscal}` | estatus en el SAT |
| ObtieneCFDI | `{FolioFiscal, RfcEmisor}` | recuperar XML |
| ObtieneTimbresDisponibles | `{RfcEmisor}` | `php artisan invoicing:check` |

Errores: `{Categoria, Codigo, CodigoSat, Mensaje, MensajeSat, Valores}` con HTTP 400/401/500.

### Pruebas vs. producción

| | Pruebas (default) | Producción |
|---|---|---|
| URL | `https://pruebas.timbracfdi33.mx:1444/api` | `https://timbracfdi33.mx:1444/api` |
| Token | `mvpNUXmQfK8=` (público) | el que entrega ProFact |
| Emisor | `IIA040805DZ4` (emisor de prueba) | RFC de la escuela, registrado con su CSD (`RegistraEmisor` o panel) |
| Receptor de prueba | `EKU9003173C9` · ESCUELA KEMPER URGATE · 42501 · 603 · G03 | datos reales del alumno |

Para cambiar a producción solo se editan las variables `INVOICING_*` del `.env` de payments-service.
