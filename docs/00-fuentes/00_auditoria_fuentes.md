# 00 — Auditoría de Fuentes

| Campo | Valor |
|---|---|
| Documento | 00 — Auditoría de Fuentes |
| Versión | 1.0 |
| Fecha | 2026-07-08 |
| Depende de | Fuentes externas (propuesta v5 y demo aprobado) |

## 1. Fuentes auditadas

| ID Fuente | Documento | Ubicación | Estado |
|---|---|---|---|
| F1 | `propuesta_panel_seguimiento.md` (v5, aceptada por dirección) | `00-fuentes/` (copia) | Auditada |
| F2 | Demo aprobado — HTML/CSS/JS vanilla (`index.html`, `js/app.js`, `js/usuarios.js`, `css/`) | Carpeta `Seguimiento de acuerdos` (Downloads) | Auditada |
| F3 | Diseño original `design/Panel de Acuerdos.dc.html` (Claude Design) | Misma carpeta, `design/` | Referencia visual |

## 2. Tabla resumen de hallazgos

| ID | Hallazgo | Fuente / sección | Severidad |
|---|---|---|---|
| H-01 | La máquina de estados del demo (4 estados: Pendiente, En proceso, Completado, Vencido) difiere de la definición aprobada para producción (3 estados: En proceso, Vencido, Concluido) | F2 `js/app.js` `EST{}` | 🟠 |
| H-02 | La propuesta no contempla corresponsables; es requisito nuevo confirmado por dirección | F1 §3 | 🟠 |
| H-03 | Recordatorios con anticipación fija (3 días, rango 1–7 global) vs. requisito nuevo: default global + override por acuerdo | F1 §5, F2 `CONFIG.diasRecordatorio` | 🟡 |
| H-04 | Matriz de permisos del demo permite a responsable/coordinador marcar "Completado"; la regla aprobada reserva "Concluido" exclusivamente a Dirección vía checklist de validación | F2 `js/usuarios.js` `puedeActualizar()` | 🟠 |
| H-05 | Los datos demo usan nombres plausibles y correos reales del dominio `@planjuarez.org` | F2 `js/usuarios.js` `USUARIOS_INICIALES` | 🟡 |
| H-06 | Las fechas del demo se generan relativas a "hoy" en tiempo de ejecución; `db.json` exige fechas fijas ISO | F2 `js/app.js` helper `d(n)` | 🟡 |
| H-07 | La propuesta promete "cada persona ve solo lo que le corresponde"; los corresponsables amplían la visibilidad y deben integrarse a esa matriz sin contradecirla | F1 §1.2, §3 | 🟡 |
| H-08 | No existen credenciales, tokens ni secretos expuestos en ninguna fuente | F1, F2, F3 | 🟢 |
| H-09 | El demo solo prueba la lógica de permisos (`node --test`); el resto de la lógica (recordatorios, filtros, captura) no tiene pruebas | F2 `tests/permisos.test.js` | 🟡 |
| H-10 | La propuesta deja abierta la integración con el tablero de metas estratégicas ("¿ahora o segunda fase?") sin decisión registrada | F1 §8 | 🟢 |

## 3. Detalle por hallazgo

### H-01 · Máquina de estados divergente — 🟠

- **Dónde se propone:** F2 define `EST = {pendiente, proceso, completado, vencido}` y captura con campo "Estado inicial" (pendiente | proceso).
- **Situación actual:** el demo aprobado visualmente usa 4 estados; la decisión de negocio posterior define 3: **En proceso** (default), **Vencido** (automático al pasar la fecha compromiso; regresa a En proceso al registrar avance con reprogramación), **Concluido** (solo Dirección).
- **Propuesta:** la conversión React implementa la máquina de 3 estados; el campo "Estado inicial" desaparece de la captura; el kanban pasa a 3 columnas; los concluidos se ocultan por defecto (solo visibles al filtrar por Concluido).
- **Impacto:** SRS (RF), modelo de datos (ENUM), API, demo React, plan de pruebas.
- **Acción sugerida:** documentar la máquina en SRS §Máquina de estados y replicarla en docs 02, 05 y 06. ✔ Aplicado en esta generación.

### H-02 · Corresponsables — 🟠

- **Dónde se propone:** requisito nuevo de dirección (no está en F1 ni F2).
- **Situación actual:** un acuerdo tiene un solo responsable; nadie más recibe avisos ni da seguimiento.
- **Propuesta:** relación N:M `acuerdo_corresponsables`. Corresponsables tienen **seguimiento completo**: ven el acuerdo, registran avances/observaciones, cambian a "En proceso" tras reprogramación y reciben recordatorios. No pueden concluir (nadie salvo Dirección).
- **Impacto:** modelo de datos, matriz de permisos, API, UI (captura, drawer, checklist), recordatorios.
- **Acción sugerida:** integrado en docs 01, 03, 05. ✔

### H-03 · Recordatorios personalizables — 🟡

- **Situación actual:** un solo aviso previo global (3 días), día del compromiso y seguimiento de vencido, sin personalización por acuerdo.
- **Propuesta:** configuración global editable por Dirección (lista de días de anticipación, ej. `[7,3,1]` + día del compromiso + seguimiento de vencido) y **override por acuerdo** (JSON en `acuerdos.recordatorio_dias`). El job diario materializa los envíos.
- **Impacto:** modelo de datos, API, UI de captura/edición, job de recordatorios.
- **Acción sugerida:** integrado en docs 01, 03, 05. ✔

### H-04 · Solo Dirección concluye — 🟠

- **Situación actual (demo):** `puedeActualizar()` permite al responsable y a la coordinación de su área marcar cualquier estado, incluido "Completado"; la Dirección solo consulta.
- **Propuesta:** responsables/corresponsables/coordinación actualizan avance y observaciones; el estado **Concluido** solo lo asigna Dirección desde la nueva vista *Checklist de validación*. La Dirección deja de ser solo lectora.
- **Impacto:** `usuarios.js`→matriz de permisos nueva, SRS, API (endpoint `PATCH /acuerdos/{id}/concluir` restringido), pruebas negativas 403.
- **Acción sugerida:** matriz de permisos v2 en SRS §Roles. ✔

### H-05 · PII plausible en datos demo — 🟡

- **Situación actual:** `USUARIOS_INICIALES` usa nombres completos y correos `@planjuarez.org` que podrían coincidir con personas reales.
- **Propuesta:** en `db.json` (regla de fidelidad v2) todos los correos usan dominio `@demo.test` y nombres claramente ficticios.
- **Acción sugerida:** aplicado en `demo-ux/app/src/lib/mock/db.json`. ✔ No se transcribe aquí ningún dato personal de la fuente.

### H-06 · Fechas relativas vs. `db.json` — 🟡

- **Situación actual:** el demo calcula fechas con `d(n)` relativo al día de carga, lo que garantiza escenarios vivos (vencidos, por vencer) en cualquier fecha.
- **Propuesta:** `db.json` usa fechas fijas ISO (`Y-m-d`); el mock (`api.mock.ts`) conserva el realismo re-basando las fechas relativas a `hoy` en memoria al cargar, sin alterar el archivo espejo.
- **Acción sugerida:** documentado en 09_demo_ux_guia. ✔

### H-07 · Visibilidad con corresponsables — 🟡

- **Propuesta:** regla de visibilidad v2: Dirección ve todo; Coordinación ve su área + acuerdos donde participa; Responsable ve los acuerdos donde es responsable **o corresponsable**. Sin contradicción con F1 §1.2: el corresponsable "le corresponde" el acuerdo.
- **Acción sugerida:** matriz en SRS. ✔

### H-09 · Cobertura de pruebas del demo — 🟡

- **Propuesta:** el plan de pruebas (doc 06) cubre máquina de estados exhaustiva, generación de recordatorios y permisos; el demo React incorpora pruebas de la lógica portada (Vitest) desde el Sprint D.
- **Acción sugerida:** matriz de trazabilidad en doc 06. ✔

### H-10 · Tablero de metas — 🟢

- **Situación:** decisión pospuesta en la reunión (F1 §8). Se registra como *fuera del MVP* en SRS §Consideraciones futuras. Sin acción para esta fase.

## 4. Conclusión

Ninguna fuente contiene secretos expuestos (H-08). Los hallazgos 🟠 son divergencias de reglas de negocio entre demo aprobado y decisiones posteriores de dirección; todos quedan resueltos por diseño en los documentos 01–08 de esta misma generación. No hay bloqueantes para iniciar la Fase 0.
