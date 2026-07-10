# Pruebas de carga (k6) — S3.1

Artefactos de esta carpeta: `k6-acuerdos.js` (script de carga) + este README.
**k6 no está instalado en el entorno donde se generaron estos artefactos** — no
se ejecutaron aquí. Lo que sigue es la guía para correrlos en una máquina con
k6 y una BD de desarrollo con volumen.

## 1. Instalar k6

- macOS: `brew install k6`
- Linux (deb): ver https://k6.io/docs/get-started/installation/
- Docker: `docker run --rm -i grafana/k6 run - <tests/perf/k6-acuerdos.js` (ajusta
  `BASE_URL` a algo alcanzable desde el contenedor, p. ej. `http://host.docker.internal:8080/api/v1`)

## 2. Preparar el dataset (~5,000 acuerdos)

El script asume que la API corre contra una BD de **desarrollo** (MySQL, no la
SQLite en memoria de PHPUnit) con volumen suficiente para que los umbrales
tengan sentido (doc 06 §4: 5,000 filas para el listado, 50 avances para el
detalle). Dos opciones:

### Opción A — `PerfSeeder` (recomendado, incluido en este repo)

Requiere que la BD de dev ya tenga el seed base (`InitialSeeder` o el seed de
dev que uses) aplicado — necesita al menos 1 área y 1 usuario activos. **Nunca
lo corras contra la BD de tests ni de producción** — el comando se niega a
correr si `ENVIRONMENT=testing`.

```bash
cd apps/api
php spark perf:seed          # pide confirmación interactiva
php spark perf:seed --force  # sin confirmación (CI/script)
```

Inserta ~5,000 acuerdos repartidos entre las áreas/usuarios existentes (mezcla
de `en_proceso`/`vencido`/`concluido`, fechas en una ventana de -30 a +90 días)
y **1 acuerdo "ancla" con 50 avances** — el comando imprime su id al final:

```
5000 acuerdos insertados. Acuerdo ancla (con 50 avances) para el escenario de
detalle de k6: id=11 (usa ACUERDO_ID=11).
```

Usa ese id como `ACUERDO_ID` en el paso 3. El seeder es aditivo (no borra
nada); si quieres repetir desde un estado limpio, restaura la BD de dev antes.

### Opción B — dataset propio

Cualquier BD de dev con ≥5,000 acuerdos visibles para el usuario del token y
al menos un acuerdo con ~50 avances sirve igual. Ajusta `ACUERDO_ID` al id de
ese acuerdo.

## 3. Obtener un token

`API_TOKEN` debe ser un `Authorization: Bearer <token>` válido para la API —
un `idToken` real de Firebase de un usuario Dirección (para que el listado no
esté acotado por visibilidad de rol) del entorno de pruebas de carga. Este
proyecto no emite tokens de prueba fuera de PHPUnit (`FakeTokenVerifier` es
solo para tests unitarios/feature, no para un servidor HTTP real).

## 4. Correr

```bash
cd apps/api
BASE_URL=http://localhost:8080/api/v1 \
API_TOKEN=eyJ... \
ACUERDO_ID=11 \
AREA_ID=1 \
RESPONSABLE_ID=1 \
k6 run tests/perf/k6-acuerdos.js
```

Corre 3 escenarios en paralelo, 10 VU × 2 min cada uno (doc 06 §4):

| Escenario | Endpoint | Umbral p95 |
|---|---|---|
| `listado_acuerdos` | `GET /acuerdos?per_page=200` | < 500 ms |
| `detalle_acuerdo` | `GET /acuerdos/{ACUERDO_ID}` | < 300 ms |
| `captura_lote` | `POST /acuerdos/lote` (20 acuerdos) | < 800 ms |

k6 evalúa los `thresholds` del script al final de la corrida — un exit code
≠ 0 significa que algún p95 superó el umbral (o hubo `http_req_failed` > 1%).

**Nota sobre `captura_lote`:** cada iteración crea una reunión + 20 acuerdos
reales — correrlo repetidamente sigue engordando la BD de carga (esperado,
igual que `PerfSeeder`). No lo apuntes a producción.

## 5. Job diario (< 5 min) — nota, no ejecutable de forma significativa aquí

El umbral del doc 06 §4 es "Job diario (500 abiertos, 100 envíos) < 5 min",
medido con ejecución cronometrada:

```bash
cd apps/api
time php spark recordatorios:procesar
```

Sin ~500 acuerdos abiertos y ~100 envíos pendientes en la BD contra la que
corre, el tiempo medido no es representativo — usa `PerfSeeder` (o un subconjunto)
para poblar ese volumen en una BD de dev antes de cronometrarlo. No se ejecutó
en este entorno porque requiere ese volumen + una BD real (no la SQLite en
memoria de PHPUnit).
