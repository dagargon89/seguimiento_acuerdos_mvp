# ADR-014 — Solicitud de conclusión (revisión responsable/corresponsable → aprobación/rechazo)

| Campo | Valor |
|---|---|
| Documento | ADR-014 |
| Versión | 1.0 |
| Fecha | 2026-07-30 |
| Estado | Aceptada |
| Depende de | ADR-012 (conclusión por coordinador de área), ADR-007 (visibilidad abierta), spec `docs/superpowers/specs/2026-07-29-mejoras-revision-edicion-wysiwyg-design.md` |
| Modifica | Flujo de conclusión (regla №4 de CLAUDE.md); `docs/05-api/05_*.md` (nuevos métodos); `RecordatorioService.php` (silencio + congelamiento) |

## 1. Contexto

ADR-012 dejó dos actores capaces de concluir directo: Dirección (cualquier acuerdo) y la coordinación de área (los de su área). El responsable y los corresponsables del acuerdo —quienes hacen el trabajo— no tenían forma de señalar "esto ya está listo" salvo pedirlo fuera del sistema. Dirección/coordinación pidió un tercer camino: que responsable/corresponsables puedan **solicitar** la conclusión, y que la conclusión directa se convierta en una **aprobación** cuando viene de una solicitud (o en un **rechazo** con motivo, si falta algo).

## 2. Decisión

### a) Dos caminos a `concluido`, uno amplía al otro

Se mantiene la conclusión directa de ADR-012 (Dirección/coordinador de área, sin pasos previos). Se añade un segundo camino, exclusivo para responsable/corresponsables, que pasa por revisión:

1. **Solicitar** (`POST /acuerdos/{id}/solicitar-conclusion`) — responsable o corresponsable del acuerdo. Denegado → 403 + auditoría `intento_solicitar_conclusion`.
2. **Aprobar** — reutiliza `PATCH /acuerdos/{id}/concluir` (mismo endpoint y mismos permisos de ADR-012); si el acuerdo venía con una solicitud pendiente, además de concluir se cierra la revisión y se audita `aprobar_conclusion`.
3. **Rechazar** (`POST /acuerdos/{id}/rechazar-conclusion`) — mismos permisos que aprobar (Dirección o coordinador de su área). Requiere `motivo`; deja el acuerdo activo y editable para que el responsable corrija y vuelva a solicitar.

### b) Flag `revision_estado`, independiente del enum `estado`

El ciclo de vida `en_proceso` → `vencido` → `concluido` (regla del núcleo de dominio, CLAUDE.md) no cambia. La revisión vive en una columna aparte de `acuerdos`:

```sql
revision_estado             ENUM('sin_solicitud','pendiente','rechazada') NOT NULL DEFAULT 'sin_solicitud'
revision_solicitada_por_id  BIGINT UNSIGNED NULL   -- FK usuarios(id) ON DELETE SET NULL
revision_solicitada_at      DATETIME NULL
revision_motivo_rechazo     TEXT NULL
```

Transiciones: `sin_solicitud`→`pendiente` (al solicitar); `pendiente`→`sin_solicitud` (al aprobar, junto con `estado='concluido'`); `pendiente`→`rechazada` (al rechazar, con motivo); `rechazada`→`pendiente` (al volver a solicitar). Editar el acuerdo (contenido) **no** toca este flag.

### c) Congelamiento de `vencido` y silencio de recordatorios en `pendiente`

Mientras `revision_estado='pendiente'`: el job diario NO marca el acuerdo como `vencido` (aunque la fecha compromiso ya pasó), y no le envía recordatorios ni solicitudes de avance al responsable/corresponsables. El acuerdo sigue **visible para todos** (ADR-007), marcado "en revisión" en la UI.

### d) Correos inmediatos (no cron)

Igual que ADR-009/010/011, estos correos salen al instante del evento, no del job diario:

- **Solicitar** → admins (rol `direccion`) + coordinador del área del acuerdo (plantilla `solicitud_conclusion`).
- **Aprobar** → responsable + corresponsables (plantilla `conclusion_aprobada`).
- **Rechazar** → responsable + corresponsables, con el motivo (plantilla `conclusion_rechazada`).

## 3. Consecuencias

- **Migración de esquema**: 4 columnas nuevas en `acuerdos` (DDL `docs/03-datos/panel_acuerdos_ddl.sql`, snapshot y `db.json`, regla №1). El enum `estado` y su expresión derivada de vencido no cambian.
- Doc 05 (contrato) incorpora `solicitarConclusion`/`rechazarConclusion` y los campos `revision_*` (regla №3, misma sesión).
- Reabrir y eliminar siguen siendo exclusivos de Dirección (ADR-012, sin cambio). La conclusión directa sin solicitud previa sigue funcionando igual y no dispara el correo de "aprobado".
- Pruebas: policies positivas/negativas de las tres rutas (cada denegación audita su `intento_*`); recordatorios/vencido congelados durante `pendiente`; aprobación y rechazo notifican correctamente.
- **Reversible**: quitar las dos rutas nuevas, ignorar `revision_estado` en `marcarVencidos()`/recordatorios, y las columnas quedan inertes (o se retiran en una migración posterior). La conclusión directa de ADR-012 no se ve afectada.
