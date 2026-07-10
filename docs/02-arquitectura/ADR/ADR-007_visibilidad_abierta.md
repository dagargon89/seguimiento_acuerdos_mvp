# ADR-007 — Visibilidad de lectura abierta para roles aprobados (temporal, reversible)

| Campo | Valor |
|---|---|
| Documento | ADR-007 |
| Versión | 1.0 |
| Fecha | 2026-07-10 |
| Estado | Aceptada |
| Depende de | ADR-001, ADR-006, doc 01 (SRS §2.2), doc 05 (contrato) |

## 1. Contexto

Desde el diseño original (SRS §2.2, doc 04 §A01), la visibilidad de LECTURA de acuerdos (`GET /acuerdos`, `GET /acuerdos/{id}`, `GET /calendario`, `GET /recordatorios/proximos|historial`) estaba restringida por rol, centralizada en `App\Policies\VisibilidadAcuerdos`:

- `direccion`: ve todo.
- `coordinador`: acuerdos de su `area_id`, O donde es responsable, O donde es corresponsable.
- `responsable`: solo donde es responsable o corresponsable.

Esta matriz reflejaba un supuesto de "necesito ver solo lo mío" propio de organizaciones más grandes o compartimentadas. El stakeholder, operando un equipo pequeño que trabaja en conjunto sobre los mismos acuerdos entre áreas, pidió explícitamente (2026-07-10):

> "Por lo pronto, todos deben de ver los acuerdos de todos, porque trabajamos en conjunto."

## 2. Decisión

`VisibilidadAcuerdos` cambia a **visibilidad abierta**: los tres roles aprobados (`direccion`, `coordinador`, `responsable`) ven TODOS los acuerdos sin ningún filtro de área/participación. Es una decisión **temporal y explícitamente reversible** — vive en un único punto (la política), no repartida en cada controller.

### Alcance — qué SÍ cambia

Solo la LECTURA de acuerdos y lo que deriva directamente de `VisibilidadAcuerdos`:

- `GET /acuerdos` (listado): sin filtro de visibilidad para los 3 roles aprobados.
- `GET /acuerdos/{id}` (detalle): `puedeVer()` devuelve `true` para los 3 roles aprobados, sin importar área/participación — un acuerdo ajeno ya no responde 404, responde 200.
- `GET /calendario` (`CalendarioController`): usa `VisibilidadAcuerdos::aplicarAlListado` — se abre automáticamente, sin tocar su código.
- `GET /recordatorios/proximos` y `GET /recordatorios/historial` (`RecordatoriosController`): idem, usan `aplicarAlListado` internamente — se abren automáticamente.

### Alcance — qué NO cambia

- **Escritura/edición** (`AcuerdosController::puedeEditarEstructura`, usado por `update()` y `corresponsables()`): sigue exigiendo Dirección o coordinación **del área del acuerdo**. Un acuerdo ajeno ahora es *visible* (200 en GET) pero sigue siendo *no editable* (403 en PATCH/PUT) para quien no cumple esa regla.
- **Avances** (`puedeRegistrarAvance`): sigue exigiendo ser responsable, corresponsable, coordinación del área, o Dirección. Mismo efecto: visible pero no escribible para terceros.
- **Concluir/reabrir** (`concluir()`, `reabrir()`): **solo Dirección**, regla intocable — no usan `VisibilidadAcuerdos` en absoluto (solo verifican existencia + `actor.rol === 'direccion'`), así que ni siquiera derivan del cambio. El 403 de un intento de otro rol sigue auditándose (`intento_concluir`/`intento_reabrir`).
- **Resumen** (`ResumenController`): filtra por `actor.area_id` directamente, sin pasar por `VisibilidadAcuerdos` — Dirección ve el ámbito general, coordinación ve solo su área (por `area_id`, no por la política de visibilidad), responsable sigue recibiendo 403. Sin cambios.
- **Concluidos ocultos por default** (`PA-01`/`PA-02`): el filtro de `estado` es independiente de la visibilidad por rol; sigue aplicando igual para los 3 roles.
- **Guardia `cuenta_pendiente`** (ADR-006): un rol `pendiente` sigue sin ver absolutamente nada fuera de `GET/PATCH /me` — el filtro central en `FirebaseAuthFilter` ya bloquea la ruta antes de llegar a `VisibilidadAcuerdos`. Como defensa en profundidad, `aplicarAlListado`/`puedeVer` devuelven "nada visible" (`WHERE 1=0` / `false`) para cualquier rol fuera de la lista aprobada, por si algún día se invocaran sin esa guardia.
- El contrato TypeScript (`api.ts`/`types.ts`) **no cambia** — es un comportamiento server-side, no una forma de dato nueva.

### Implementación

`App\Policies\VisibilidadAcuerdos::aplicarAlListado()` y `::puedeVer()` colapsan a: si `actor.rol` está en `['direccion', 'coordinador', 'responsable']` → sin filtro / `true`; en cualquier otro caso → filtro vacío / `false`. La firma de `puedeVer()` conserva el parámetro `$esCorresponsable` (ya no participa en la decisión de visibilidad) porque los controllers lo calculan y lo reutilizan para los guards de escritura, que no cambiaron.

## 3. Consecuencias

**Positivas:** refleja cómo trabaja realmente el equipo (colaboración cruzada entre áreas) sin fricción de "no veo el acuerdo del que me están hablando en la reunión"; el cambio es mínimo y centralizado (una clase), fácil de auditar y de revertir; la separación lectura/escritura ya existente en el diseño (visibilidad vs. permiso) hace que abrir solo la lectura no comprometa ningún control de integridad de datos — nadie gana la capacidad de editar, concluir o reabrir algo que antes no podía.

**Negativas / mitigación:** un responsable ahora ve detalles de acuerdos de otras áreas en los que no participa, lo cual reduce el aislamiento de información entre equipos — aceptado explícitamente por el stakeholder como el comportamiento deseado ("trabajamos en conjunto"), y es reversible si cambian las condiciones (equipo crece, se necesita compartimentar). El código de error de un intento de escritura sobre un acuerdo ajeno-pero-visible cambia de 404 a 403 (antes la visibilidad ocultaba el recurso antes de llegar al guard de permiso; ahora el recurso es visible y el guard de escritura responde con su código normal) — esto es un efecto esperado, no un bug, y se documenta explícitamente en los tests de contraste (`AcuerdosEscrituraTest`).

## 4. Cómo revertir

Esta decisión es temporal ("por lo pronto"). Para restaurar la visibilidad por rol previa:

1. Restaurar `App\Policies\VisibilidadAcuerdos` a la versión anterior a este ADR (disponible en el historial de git): `aplicarAlListado()` con las ramas `direccion` → todo, `coordinador` → `area_id` propio O responsable O corresponsable (`EXISTS` en `acuerdo_corresponsables`), `responsable` → responsable O corresponsable; `puedeVer()` con la misma lógica evaluada en PHP sobre la fila cargada.
2. Restaurar los tests originales `testAU01ResponsableListaSoloAcuerdosDondeEsResponsableOCorresponsable`, `testAU02ResponsablePideAcuerdoAjenoPorIdDevuelve404`, `testAU03CoordinadorListaSuAreaMasParticipacionesSinVerOtrasAreas` y `testAU03CoordinadorVeParticipacionFueraDeSuAreaAunqueNoSeaDeElla` en `AcuerdosLecturaTest`, y los equivalentes en `CalendarioResumenRecordatoriosTest` (`testCalendarioRespetaLaVisibilidadPorRolIgualQueAcuerdos`, `testRecordatoriosProximosDeUnResponsableSoloIncluyenSusAcuerdosVisibles`, `testRecordatoriosProximosNoIncluyeAcuerdosNoVisibles`) y `AcuerdosEscrituraTest` (`testAU04CoordinadorEditaAcuerdoDeOtraAreaSinParticiparEs404NoEncontrado`, `testEscrituraSobreAcuerdoNoVisibleEs404`) — todos viven en el historial de git anteriores a este commit.
3. Revertir la nota de comportamiento añadida en doc 05 §1 y las columnas "Ve" del SRS §2.2 a su redacción previa (referencian este ADR; basta quitar la referencia y restaurar el texto anterior, también en el historial de git).
4. No hay migración de esquema ni cambio de contrato que revertir — el cambio es puramente de política server-side.

## 5. Alternativa descartada

Añadir un flag de configuración en runtime (tabla `configuracion`, ej. `visibilidad_modo: 'abierta'|'restringida'`) en vez de un cambio de código. Descartada por ahora: el stakeholder no pidió una opción configurable, pidió un comportamiento nuevo; introducir una rama condicional en runtime añade superficie de prueba (2 modos en producción) para un caso de uso "por lo pronto" que se espera resolver por decisión de producto, no por configuración operativa. Si en el futuro se necesita alternar entre ambos modos con frecuencia, esta alternativa puede reconsiderarse como una evolución de este ADR.
