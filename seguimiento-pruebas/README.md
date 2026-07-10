# Seguimiento de pruebas por sprint · Panel de Acuerdos

Bitácora viva de las pruebas ejecutadas en cada sprint de la Fase 2. Complementa al
[plan de pruebas (doc 06)](../docs/06-pruebas/06_plan_de_pruebas.md): el doc 06 define **qué**
casos existen (ME-, AU-, LT-, RE-, GC-, PA-, AD-, AR-, OW-…); esta carpeta registra **cuándo**
se ejecutaron, con qué comando y con qué resultado.

## Regla de gate

No se avanza al siguiente sprint hasta que el actual cumple su *Definition of Done* al 100%:
suite verde + criterios de aceptación cumplidos + verificación ejecutable. El estado del gate
de cada sprint se marca al final de su archivo.

## Convenciones

- **Comando**: el comando exacto ejecutado (reproducible).
- **Resultado**: ✅ verde / ❌ falla / ⏳ pendiente, con el conteo (p. ej. `8/8`).
- **Evidencia**: línea de salida relevante o resumen; para fallas, el error.
- Los IDs de caso (p. ej. `AU-09`) referencian el doc 06.

## Índice

| Sprint | Archivo | Estado |
|---|---|---|
| 0 — Cimientos | [sprint-0.md](sprint-0.md) | 🟡 En curso |
| 1 — API núcleo + auth | [sprint-1.md](sprint-1.md) | ⏳ Pendiente |
| 2 — Recordatorios + Google | [sprint-2.md](sprint-2.md) | ⏳ Pendiente |
| 3 — Piloto + cierre | [sprint-3.md](sprint-3.md) | ⏳ Pendiente |
