# 08 — Backlog de mejoras (post-producción)

| Campo | Valor |
|---|---|
| Documento | 08 — Backlog de mejoras / próximas implementaciones |
| Versión | 1.0 |
| Fecha | 2026-07-23 |
| Estado app | En producción (servidor único; ver nota operativa §4) |
| Depende de | 01_SRS, 05_API, 07_roadmap_sprints |

## 1. Contexto

La app cubre el ciclo de vida completo del acuerdo (captura por lote, corresponsables,
avances/reprogramación, enlaces, estados v2, conclusión por Dirección/coordinación,
auditoría), panel de 5 vistas con paginación, checklist, resumen, job diario de
recordatorios (Gmail + Google Calendar + resumen + solicitud de avances), configuración
global, administración de usuarios/áreas, perfil (nombre, contraseña, color de avatar) y
PWA instalable.

Los huecos identificados **no son correctivos**: son oportunidades para capitalizar los
datos que ya se capturan (reportería, gestión, colaboración). Este documento es el
backlog para retomar después; cada ítem trae valor, alcance técnico, esfuerzo y criterios
de aceptación.

Convenciones: **Impacto** = Alto/Medio · **Esfuerzo** = S (≤1 día) / M (2–4 días) / L (>1 semana) ·
**Estado** = ⬜ pendiente · 🟨 en curso · ✅ hecho.

## 2. Tabla resumen (priorizada)

| # | Mejora | Tier | Impacto | Esfuerzo | Estado |
|---|---|---|---|---|---|
| 1 | Exportar resumen/panel a PDF/XLSX | Quick win | Alto | M | ✅ |
| 2 | Filtro por área + rango de fechas en el Panel | Quick win | Alto | S–M | ✅ |
| 3 | Timeline/bitácora por acuerdo en el Drawer | Quick win | Medio | S–M | ✅ |
| 4 | Acciones en lote (concluir/reprogramar/reasignar) | Quick win | Medio | M | ⬜ |
| 5 | Gestión de Reuniones (crear/titular/acta) | Media | Alto | M–L | ⬜ |
| 6 | Dashboard de cumplimiento (métricas de gestión) | Media | Alto | M–L | ⬜ |
| 7 | Adjuntar archivos como evidencia (Google Drive) | Media | Medio | L | ⬜ |
| 8 | Vista de bitácora/actividad (auditoría) para Dirección | Media | Medio | S–M | ⬜ |
| 9 | Notificaciones in-app (campana) | Media | Medio | M | ⬜ |
| 10 | Google Tasks por usuario | Grande | Medio | L | ⬜ |
| 11 | Integración tablero de metas estratégicas (H-10) | Grande | Alto | L | ⬜ |
| 12 | Archivado anual de concluidos + papelera (soft-delete) | Grande | Medio | M–L | ⬜ |

## 3. Detalle por ítem

### 1. Exportar resumen/panel a PDF/XLSX  ✅
- **Por qué:** Dirección comparte estados en reunión; hoy no hay salida imprimible.
- **Alcance:** XLSX del listado filtrado y del `/resumen`; PDF del resumen ejecutivo.
- **Solución (cliente, sin backend):** librería `write-excel-file` v4 cargada con `import()` dinámico
  (chunk aparte, code-split); el PDF del resumen se resuelve con `window.print()` + `@media print`
  que aísla `#resumen-print`. Helper puro `lib/exportar.ts` (con tests) + efectos `lib/exportarXlsx.ts`.
- **Tocar (hecho):** `Panel.tsx` (botón "Exportar (N)" en la toolbar), `ResumenModal.tsx`
  (botones XLSX/PDF + nodo imprimible), `styles/legacy-demo.css` (`@media print`).
- **Aceptación:** el archivo respeta los filtros activos (se exporta `lista`/`r` ya acotados por el
  backend, así que no hay datos fuera del ámbito del actor); PDF con identidad PJ ("Participa Juárez").

### 2. Filtro por área + rango de fechas en el Panel  ✅
- **Por qué:** hoy el Panel solo filtra por estado y responsable; el área no se puede filtrar pese a ser eje del dominio.
- **Alcance:** `GET /acuerdos` ya soporta `desde`/`hasta`, `q`, `responsable_id`, `estado`, `mios`, `page`, `per_page`. **Falta `area_id`** como query param.
- **Tocar:** `AcuerdosController::index` (+ `AcuerdoModel`), `FiltrosAcuerdos` (types.ts / doc 05), toolbar de `Panel.tsx` (Select de área + date range; el calendario ya usa `desde/hasta`).
- **Aceptación:** filtros combinables; respeta visibilidad; se refleja en el contador y la paginación.

### 3. Timeline/bitácora por acuerdo en el Drawer  ✅
- **Por qué:** el historial de un acuerdo está disperso; verlo cronológico da trazabilidad.
- **Alcance:** unir `avances` (tipos: avance/reprogramacion/validacion/reapertura) + eventos de `auditoria` de ese acuerdo en una línea de tiempo.
- **Tocar:** `Drawer.tsx` (nueva sección), posible `GET /acuerdos/{id}/actividad` o ampliar el detalle existente.
- **Aceptación:** orden cronológico, autor + fecha por evento, sin exponer datos sensibles.

### 4. Acciones en lote  ⬜
- **Por qué:** operar acuerdo por acuerdo es lento en reuniones de revisión.
- **Alcance:** selección múltiple en la tabla del Panel → concluir / reprogramar / reasignar responsable.
- **Tocar:** `Panel.tsx` (checkboxes + barra de acciones), reusar endpoints (`concluir`, `avances`/reprogramación, `update`) o endpoint batch nuevo; respetar Policies por acuerdo (auditar cada uno).
- **Aceptación:** una acción denegada no aborta el resto; feedback por ítem; todo auditado.

### 5. Gestión de Reuniones (el hueco más ligado al propósito)  ⬜
- **Por qué:** la app "sustituye minutas narradas", pero **la reunión se autogenera** con nombre fijo (`"Reunión de dirección · <fecha>"`, ver `Captura.tsx`) vía `ReunionModel::obtenerOCrear`. No hay forma de titularla, ver su agenda ni su "acta".
- **Alcance:** CRUD de reuniones; que la captura elija/cree una reunión con título propio; vista "acta" (acuerdos de esa reunión, exportable — enlaza con #1).
- **Tocar:** nuevos endpoints `reuniones` (hoy **no existen** en `Routes.php`), `ReunionModel`, nueva página + entrada de nav, ajuste de `Captura.tsx` y de la vista "por reunión" del Panel.
- **Aceptación:** una reunión con título real agrupa sus acuerdos; el "por reunión" del Panel refleja los títulos.

### 6. Dashboard de cumplimiento  ⬜
- **Por qué:** convertir el registro en herramienta de gestión.
- **Alcance:** tasa de conclusión a tiempo por área/persona, tendencia de vencidos en el tiempo, tiempo promedio hasta conclusión, top de vencidos.
- **Tocar:** extender `ResumenController` (o `GET /metricas`); nueva vista con gráficas (usar la skill `dataviz` para el diseño de las gráficas).
- **Aceptación:** métricas por ámbito del actor (Dirección global, coordinación su área); rangos de fecha.

### 7. Adjuntar archivos como evidencia (Google Drive)  ⬜
- **Por qué:** hoy la evidencia es solo URL (`enlaces`); a veces el archivo vive en la máquina de quien reporta.
- **Alcance:** subir archivo → Google Drive (carpeta central, mismo service account que Gmail/Calendar) → guardar referencia junto al acuerdo/avance.
- **Tocar:** integración **Drive API** (hoy solo Gmail/Calendar), tabla/campo para refs de archivo, UI en Drawer/captura. Cuidar permisos y tamaño.
- **Aceptación:** archivo accesible por quienes ven el acuerdo; sin secretos en repo (ADR-003).

### 8. Vista de bitácora/actividad (auditoría) para Dirección  ⬜
- **Por qué:** la tabla `auditoria` ya registra todo (conclusiones, cambios de config, job, ediciones), pero no hay UI para consultarla.
- **Alcance:** `GET /auditoria` (solo Dirección, filtros por acción/entidad/fecha/usuario) + página.
- **Tocar:** nuevo controller/endpoint + página + nav (Administración).
- **Aceptación:** paginado, filtrable; solo Dirección; sin PII innecesaria.

### 9. Notificaciones in-app (campana)  ⬜
- **Por qué:** hoy los avisos son solo por correo; una bandeja in-app sube el engagement y no depende del correo.
- **Alcance:** campana en la topbar con próximos/vencidos/solicitudes; estado leído/no leído.
- **Tocar:** reusar `recordatorios/proximos`; estado de lectura (tabla nueva o local); componente en `App.tsx`.
- **Aceptación:** cuenta de no leídos; marcar leído; respeta ámbito del usuario.

### 10. Google Tasks por usuario  ⬜  *(backlog original, ADR-003)*
- OAuth incremental por usuario; crear una task por acuerdo asignado. Depende de flujo OAuth por usuario (hoy la integración Google es con cuenta central).

### 11. Integración tablero de metas estratégicas (H-10)  ⬜  *(backlog original)*
- Vincular acuerdos con metas/objetivos estratégicos. Requiere definir el modelo de metas y su fuente.

### 12. Archivado anual de concluidos + papelera  ⬜  *(backlog original)*
- Archivar concluidos por año (aligera el panel) y **papelera/soft-delete**: hoy `DELETE /acuerdos/{id}` es borrado **duro** (ADR-011); una papelera con restauración reduce el riesgo de pérdida.

## 4. Nota operativa (prerrequisito recomendado)  ⬜

Hoy se trabaja **directo en producción**, sin staging y sin poder correr PHPUnit en el
servidor (no hay MySQL de test). Antes de features grandes (5, 6, 7) conviene:
- **Entorno de staging** (o al menos BD de pruebas local) para validar migraciones y cambios de esquema sin tocar datos reales.
- **Pipeline mínimo** que corra PHPUnit + `verificar_espejo` + typecheck/lint/build.

No es una feature, pero protege a todas las demás. Ver [[entorno-produccion]] (memoria del agente).

## 5. Decisiones pendientes (para Dirección)

- Exportes (#1): ¿PDF, XLSX o ambos? ¿branding formal para actas?
- Reuniones (#5): ¿la captura debe **exigir** elegir una reunión existente o permitir crear al vuelo como hoy?
- Adjuntos (#7): ¿Google Drive de la organización o almacenamiento propio? ¿límite de tamaño/tipos?
- Archivado (#12): ¿regla de archivado (p. ej. concluidos > 1 año) automática o manual?

## 6. Orden sugerido

1. (Prerrequisito) Staging + pipeline mínimo (§4).
2. Quick wins: #2 → #3 → #1 → #4.
3. Media: #5 (reuniones) o #6 (dashboard) según prioridad de Dirección.
4. Resto según valor: #8, #9, #7, luego los grandes (#12, #11, #10).
