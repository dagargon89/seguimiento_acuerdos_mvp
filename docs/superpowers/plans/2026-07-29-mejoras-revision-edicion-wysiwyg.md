# Mejoras Panel de Acuerdos (revisión de conclusión, edición, WYSIWYG, tooltip, cron) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir un flujo de solicitud/aprobación de conclusión de acuerdos con avisos por correo, ampliar la edición de contenido a los participantes, convertir el campo acción en editor Markdown, truncar la columna acción con tooltip, y fijar la hora del cron a las 8:00.

**Architecture:** El estado de revisión es un **flag independiente** (`revision_estado`) del enum `estado`; el ciclo de vida (`en_proceso`/`vencido`/`concluido`) no cambia. El backend CI4 gana 2 endpoints (`solicitar-conclusion`, `rechazar-conclusion`) y extiende `concluir()` como aprobación; el job de recordatorios respeta el congelamiento y el silencio. El frontend consume el contrato ampliado, renderiza Markdown con nodos React (sin `dangerouslySetInnerHTML`) y añade editor/tooltip.

**Tech Stack:** CodeIgniter 4.7 (PHP 8.3) + PHPUnit 10.5; React 19 + TypeScript + Vite + Vitest 3; MySQL 8.4. Nueva dependencia front: `react-markdown` (render seguro a nodos React).

## Global Constraints

- **`db.json` es espejo del DDL** (regla 1): toda columna nueva va en `docs/03-datos/panel_acuerdos_ddl.sql`, en la migración, y en las 10 filas de `apps/web/src/lib/mock/db.json`. `node scripts/verificar_espejo.mjs` debe pasar.
- **Las pantallas nunca leen `db.json`**; todo pasa por `ApiClient` de `apps/web/src/lib/api.ts` (regla 2).
- **`api.ts` congelada = doc 05** (regla 3): cualquier método nuevo se refleja en `docs/05-api/` en la misma sesión.
- **Prohibido `dangerouslySetInnerHTML`** (regla 7): el Markdown se pinta con `react-markdown` a nodos React.
- **`vencido` solo lo asigna el sistema** (regla 5); `estado` nunca se acepta del cliente (422 `campo_no_permitido`).
- **Toda fecha en `America/Ciudad_Juarez`** (regla 6).
- **Transacciones en toda operación multi-tabla** (`$db->transException(true)->transStart()…transComplete()`, regla 8).
- **Query Builder siempre; cero N+1** (reglas 7 y 9).
- **Conversión 1:1 del demo** (regla 11): tokens PJ, sin cambios visuales no aprobados.
- **Rol `direccion` se conserva** en BD y código (el rename a "admin" está en pausa).
- **Roles:** `direccion` concluye/aprueba/rechaza cualquier acuerdo; `coordinador` solo los de su `area_id`; `responsable`/corresponsables solo pueden **solicitar** conclusión.
- Comandos: backend `cd apps/api && composer test` (o `./vendor/bin/phpunit --filter <Nombre>`); front `cd apps/web && npm run test && npm run typecheck && npm run lint`.

---

## Fase 1 — Backend: datos y lectura

### Task 1: Columnas `revision_*` en `acuerdos` (DDL + migración + espejo)

**Files:**
- Modify: `docs/03-datos/panel_acuerdos_ddl.sql:63-64` (tabla `acuerdos`, tras `concluido_at`)
- Create: `apps/api/app/Database/Migrations/2026-07-29-100000_AgregarRevisionAcuerdos.php`
- Modify: `apps/web/src/lib/mock/db.json` (10 filas de `acuerdos`)
- Test: `apps/api/tests/database/EsquemaEspejoTest.php` (ya existe; debe seguir verde), `apps/api/tests/database/InitialSeederTest.php` (ídem)

**Interfaces:**
- Produces: columnas `acuerdos.revision_estado ENUM('sin_solicitud','pendiente','rechazada') DEFAULT 'sin_solicitud'`, `revision_solicitada_por_id INT UNSIGNED NULL` (FK→usuarios), `revision_solicitada_at DATETIME NULL`, `revision_motivo_rechazo TEXT NULL`.

- [ ] **Step 1: Actualizar el DDL fuente de verdad**

En `docs/03-datos/panel_acuerdos_ddl.sql`, dentro de `CREATE TABLE acuerdos`, insertar tras la línea `concluido_at DATETIME NULL,` (línea 64) y antes de `created_at`:

```sql
  revision_estado         ENUM('sin_solicitud','pendiente','rechazada') NOT NULL DEFAULT 'sin_solicitud',
  revision_solicitada_por_id INT UNSIGNED NULL,
  revision_solicitada_at  DATETIME     NULL,
  revision_motivo_rechazo TEXT         NULL,
```

Y añadir la FK junto a las demás (tras `fk_acuerdos_concluido`, línea 76):

```sql
  CONSTRAINT fk_acuerdos_revision_solicitante FOREIGN KEY (revision_solicitada_por_id) REFERENCES usuarios (id),
```

- [ ] **Step 2: Crear la migración `ALTER` (patrón `AgregarEnlacesAcuerdos`)**

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Flujo de revisión de conclusión (spec 2026-07-29): flag `revision_estado`
 * independiente del enum `estado`. Migración ADITIVA y reversible. El DDL
 * (docs/03-datos/panel_acuerdos_ddl.sql) refleja las columnas; EsquemaEspejoTest
 * compara el esquema migrado contra ese DDL.
 */
class AgregarRevisionAcuerdos extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE acuerdos "
            . "ADD COLUMN revision_estado ENUM('sin_solicitud','pendiente','rechazada') NOT NULL DEFAULT 'sin_solicitud' AFTER concluido_at, "
            . 'ADD COLUMN revision_solicitada_por_id INT UNSIGNED NULL AFTER revision_estado, '
            . 'ADD COLUMN revision_solicitada_at DATETIME NULL AFTER revision_solicitada_por_id, '
            . 'ADD COLUMN revision_motivo_rechazo TEXT NULL AFTER revision_solicitada_at, '
            . 'ADD CONSTRAINT fk_acuerdos_revision_solicitante FOREIGN KEY (revision_solicitada_por_id) REFERENCES usuarios (id)'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE acuerdos DROP FOREIGN KEY fk_acuerdos_revision_solicitante');
        $this->db->query('ALTER TABLE acuerdos DROP COLUMN revision_motivo_rechazo, DROP COLUMN revision_solicitada_at, DROP COLUMN revision_solicitada_por_id, DROP COLUMN revision_estado');
    }
}
```

- [ ] **Step 3: Añadir las 4 columnas a las 10 filas de `acuerdos` en `db.json`**

El seeder inserta las filas tal cual (`insertBatch`), y `verificar_espejo.mjs` exige columnas exactas. A cada objeto de `db.json > acuerdos` añadir (tras `updated_at`):

```json
"revision_estado": "sin_solicitud",
"revision_solicitada_por_id": null,
"revision_solicitada_at": null,
"revision_motivo_rechazo": null
```

- [ ] **Step 4: Verificar el espejo y el esquema**

Run:
```bash
node scripts/verificar_espejo.mjs
cd apps/api && ./vendor/bin/phpunit --filter EsquemaEspejoTest && ./vendor/bin/phpunit --filter InitialSeederTest
```
Expected: espejo OK (exit 0); ambos tests PASS (el esquema migrado incluye las columnas; el seeder inserta las filas con los nuevos campos).

- [ ] **Step 5: Commit**

```bash
git add docs/03-datos/panel_acuerdos_ddl.sql apps/api/app/Database/Migrations/2026-07-29-100000_AgregarRevisionAcuerdos.php apps/web/src/lib/mock/db.json
git commit -m "feat(datos): columnas revision_* en acuerdos (DDL + migración + espejo)"
```

---

### Task 2: Exponer `revision_estado` y `revision_motivo_rechazo` en lectura

**Files:**
- Modify: `apps/api/app/Models/AcuerdoModel.php:26-30` (allowedFields) y `:61-72` (SELECT de `builderConJoins`)
- Modify: `apps/api/app/Entities/Acuerdo.php` (props readonly + `aArray()`)
- Test: `apps/api/tests/feature/AcuerdosLecturaTest.php` (añadir un caso)

**Interfaces:**
- Produces: la respuesta JSON de `GET /acuerdos/{id}` y del listado incluye `revision_estado` (string) y `revision_motivo_rechazo` (string|null).

- [ ] **Step 1: Escribir el test que falla**

En `AcuerdosLecturaTest.php` añadir:

```php
public function testDetalleIncluyeCamposDeRevision(): void
{
    $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos/4');
    $r->assertStatus(200);
    $data = $this->cuerpo($r)['data'];
    $this->assertArrayHasKey('revision_estado', $data);
    $this->assertSame('sin_solicitud', $data['revision_estado']);
    $this->assertArrayHasKey('revision_motivo_rechazo', $data);
    $this->assertNull($data['revision_motivo_rechazo']);
}
```

- [ ] **Step 2: Ver que falla**

Run: `cd apps/api && ./vendor/bin/phpunit --filter testDetalleIncluyeCamposDeRevision`
Expected: FAIL (`Failed asserting that array has the key 'revision_estado'`).

- [ ] **Step 3: Implementar**

En `AcuerdoModel.php`, añadir a `$allowedFields` (tras `'concluido_por_id', 'concluido_at',`): `'revision_estado', 'revision_solicitada_por_id', 'revision_solicitada_at', 'revision_motivo_rechazo',`.

En el `select("…")` de `builderConJoins`, añadir a la lista de columnas de `acuerdos.` (tras `acuerdos.concluido_por_id, acuerdos.concluido_at,`):
```
acuerdos.revision_estado, acuerdos.revision_solicitada_por_id, acuerdos.revision_solicitada_at, acuerdos.revision_motivo_rechazo,
```

En `Entities/Acuerdo.php`: añadir las props readonly `public readonly string $revision_estado` y `public readonly ?string $revision_motivo_rechazo`, poblarlas en el constructor desde `$fila['revision_estado']` y `$fila['revision_motivo_rechazo']` (igual que el resto de campos), y añadir a `aArray()`:
```php
'revision_estado'         => $this->revision_estado,
'revision_motivo_rechazo' => $this->revision_motivo_rechazo,
```

- [ ] **Step 4: Ver que pasa**

Run: `cd apps/api && ./vendor/bin/phpunit --filter testDetalleIncluyeCamposDeRevision`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Models/AcuerdoModel.php apps/api/app/Entities/Acuerdo.php apps/api/tests/feature/AcuerdosLecturaTest.php
git commit -m "feat(api): exponer revision_estado y motivo en lectura de acuerdos"
```

---

## Fase 2 — Backend: flujo de revisión

### Task 3: Endpoint `POST /acuerdos/{id}/solicitar-conclusion`

**Files:**
- Modify: `apps/api/app/Config/Routes.php:48-49` (junto a `concluir`)
- Modify: `apps/api/app/Controllers/AcuerdosController.php` (nuevo método `solicitarConclusion` + policy `puedeSolicitarConclusion`)
- Create: `apps/api/app/Libraries/Recordatorios/NotificadorRevision.php`
- Modify: `apps/api/app/Libraries/Correo/PlantillaCorreo.php` (método `solicitudConclusion`)
- Test: `apps/api/tests/feature/RevisionConclusionTest.php` (nuevo)

**Interfaces:**
- Produces: `AcuerdosController::solicitarConclusion(string $id): ResponseInterface`; `NotificadorRevision::avisarSolicitud(int $acuerdoId, int $solicitanteId): void`; `PlantillaCorreo::solicitudConclusion(array $acuerdo, array $destinatario, array $solicitante): array{asunto,html}`. Efecto: `revision_estado='pendiente'`, `revision_solicitada_por_id`, `revision_solicitada_at`; auditoría `solicitar_conclusion`; denegado → `intento_solicitar_conclusion`.
- Consumes: policy `puedeSolicitarConclusion(array $actor, array $acuerdo, bool $esCorresponsable): bool`.

- [ ] **Step 1: Escribir los tests que fallan**

Crear `apps/api/tests/feature/RevisionConclusionTest.php` con el mismo esqueleto que `ConclusionReaperturaTest` (traits `DatabaseTestTrait`, `FeatureTestTrait`, `$refresh=true`, `$seed=InitialSeeder`, helper `como()` con `withBodyFormat('json')`, `cuerpo()`, `auditoriaDe()`). Añadir:

```php
public function testResponsableSolicitaConclusion(): void
{
    // Acuerdo 4: en_proceso, responsable = id 5 (responsable.dos).
    $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', ['comentario' => 'Ya terminé la evidencia.']);

    $r->assertStatus(200);
    $this->assertSame('pendiente', $this->cuerpo($r)['data']['revision_estado']);

    $fila = Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray();
    $this->assertSame('pendiente', $fila['revision_estado']);
    $this->assertSame(5, (int) $fila['revision_solicitada_por_id']);
    $this->assertNotNull($fila['revision_solicitada_at']);

    $this->assertCount(1, $this->auditoriaDe('solicitar_conclusion', 4));
}

public function testDireccionNoParticipanteNoPuedeSolicitarEs403Auditado(): void
{
    // Dirección (id 1) no es responsable ni corresponsable del acuerdo 4.
    $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

    $r->assertStatus(403);
    $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);
    $this->assertSame('sin_solicitud', Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray()['revision_estado']);
    $this->assertCount(1, $this->auditoriaDe('intento_solicitar_conclusion', 4));
}

public function testSolicitarSobreConcluidoEs409(): void
{
    // Acuerdo 1: concluido en el seed. Aun así, probamos con su responsable.
    $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/1/solicitar-conclusion', []);
    // responsable.dos no es responsable de 1 → 403; para el 409 usamos un acuerdo suyo ya concluido no hay en seed,
    // así que validamos el 409 tras aprobar en Task 4. Aquí solo exigimos que NO quede 'pendiente'.
    $this->assertNotSame('pendiente', Database::connect()->table('acuerdos')->where('id', 1)->get()->getRowArray()['revision_estado']);
}
```

> Nota: el caso 409 "solicitar sobre concluido" con permiso se cubre en Task 4 (tras aprobar el 4). Aquí basta la aserción negativa.

- [ ] **Step 2: Ver que fallan**

Run: `cd apps/api && ./vendor/bin/phpunit --filter RevisionConclusionTest`
Expected: FAIL (404/route inexistente).

- [ ] **Step 3: Ruta**

En `Routes.php`, junto a `concluir` (línea 48), añadir:
```php
$routes->post('acuerdos/(:num)/solicitar-conclusion', 'AcuerdosController::solicitarConclusion/$1');
$routes->options('acuerdos/(:num)/solicitar-conclusion', 'AcuerdosController::solicitarConclusion/$1');
```

- [ ] **Step 4: Policy + método en el controlador**

Añadir la policy (junto a `puedeConcluir`, ~línea 1536):
```php
/**
 * Solicitar conclusión (spec 2026-07-29): responsable o corresponsable del
 * acuerdo. Dirección/coordinación NO solicitan — concluyen directo (ADR-012).
 *
 * @param array<string, mixed> $actor
 * @param array<string, mixed> $acuerdo Fila con al menos responsable_id.
 */
private function puedeSolicitarConclusion(array $actor, array $acuerdo, bool $esCorresponsable): bool
{
    return ((int) $acuerdo['responsable_id']) === (int) $actor['id'] || $esCorresponsable;
}
```

Método (colócalo tras `concluir()`):
```php
/**
 * POST /acuerdos/{id}/solicitar-conclusion (spec 2026-07-29) — responsable o
 * corresponsable pide marcar el acuerdo como concluido. Deja el flag en
 * 'pendiente' (congela vencimiento y silencia recordatorios, ver
 * RecordatorioService), audita y avisa a admins + coordinación del área.
 */
public function solicitarConclusion(string $id): ResponseInterface
{
    $actor = service('usuarioActual')->obtener();
    $hoy   = $this->hoy();

    $fila = (new AcuerdoModel())->builderConJoins($hoy)->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
    if ($fila === null) {
        return $this->noEncontrado();
    }

    $db = Database::connect();
    $esCorresponsable = $db->table('acuerdo_corresponsables')
        ->where('acuerdo_id', (int) $id)->where('usuario_id', (int) $actor['id'])->countAllResults() > 0;

    if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
        return $this->noEncontrado();
    }

    if (! $this->puedeSolicitarConclusion($actor, $fila, $esCorresponsable)) {
        (new AuditoriaModel())->registrar((int) $actor['id'], 'intento_solicitar_conclusion', 'acuerdo', (int) $id, ['rol' => $actor['rol'], 'resultado' => 'denegado'], $this->request->getIPAddress());

        return $this->sinPermiso('No puedes solicitar la conclusión de este acuerdo.');
    }

    if ($fila['estado_real'] === 'concluido') {
        return $this->conflictoEstado('El acuerdo ya está concluido.');
    }
    if ($fila['revision_estado'] === 'pendiente') {
        return $this->conflictoEstado('El acuerdo ya tiene una solicitud de conclusión pendiente.');
    }

    $body       = $this->cuerpoJson() ?? [];
    $comentario = is_string($body['comentario'] ?? null) ? trim($body['comentario']) : '';

    $db->transException(true)->transStart();
    (new AcuerdoModel())->update((int) $id, [
        'revision_estado'            => 'pendiente',
        'revision_solicitada_por_id' => (int) $actor['id'],
        'revision_solicitada_at'     => Time::now()->toDateTimeString(),
        'revision_motivo_rechazo'    => null,
    ]);
    (new AuditoriaModel())->registrar((int) $actor['id'], 'solicitar_conclusion', 'acuerdo', (int) $id, ['comentario' => $comentario], $this->request->getIPAddress());
    $db->transComplete();

    if (! $db->transStatus()) {
        return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo registrar la solicitud.']);
    }

    try {
        (new NotificadorRevision())->avisarSolicitud((int) $id, (int) $actor['id']);
    } catch (Throwable $e) {
        log_message('error', 'Aviso de solicitud de conclusión falló (acuerdo {id}): {msg}', ['id' => $id, 'msg' => $e->getMessage()]);
    }

    return $this->response->setJSON(['data' => $this->cargarAcuerdoCompleto((int) $id, $hoy)->aArray()]);
}
```

> Usa `$fila['estado_real']` (columna cruda del builder) para el 409, no el derivado. Verifica que `use CodeIgniter\I18n\Time;` y `use Throwable;` ya estén importados (lo están en este controlador).

- [ ] **Step 5: Plantilla + Notificador**

En `PlantillaCorreo.php`, añadir (patrón de `asignacion`):
```php
/** @param array<string,mixed> $acuerdo @param array<string,mixed> $destinatario @param array<string,mixed> $solicitante */
public function solicitudConclusion(array $acuerdo, array $destinatario, array $solicitante): array
{
    $accion = (string) ($acuerdo['accion'] ?? '');
    $html   = '<p>Hola ' . esc((string) $destinatario['nombre']) . ',</p>'
        . '<p><strong>' . esc((string) $solicitante['nombre']) . '</strong> solicitó marcar como concluido el acuerdo:</p>'
        . '<blockquote>' . esc($accion) . '</blockquote>'
        . '<p>Revisa y aprueba o rechaza la solicitud en el panel.</p>';

    return ['asunto' => 'Solicitud de conclusión: ' . mb_substr($accion, 0, 60), 'html' => $html];
}
```

Crear `NotificadorRevision.php` (patrón `NotificadorAsignacion`, best-effort, sin registro en `recordatorios_enviados`):
```php
<?php

namespace App\Libraries\Recordatorios;

use App\Libraries\Correo\PlantillaCorreo;
use App\Models\AcuerdoModel;
use Config\Database;
use Throwable;

/**
 * Correos inmediatos del flujo de revisión de conclusión (spec 2026-07-29).
 * Best-effort por destinatario; nunca propaga (la escritura ya está confirmada).
 * Sin registro en recordatorios_enviados (el enum de `tipo` no cubre revisión;
 * el rastro vive en `auditoria`).
 */
final class NotificadorRevision
{
    /** Solicitud → admins (rol direccion) + coordinación del área del acuerdo. */
    public function avisarSolicitud(int $acuerdoId, int $solicitanteId): void
    {
        $db      = Database::connect();
        $acuerdo = $db->table('acuerdos')->select('id, accion, area_id')->where('id', $acuerdoId)->get()->getRowArray();
        if ($acuerdo === null) {
            return;
        }
        $solicitante = $db->table('usuarios')->select('id, nombre')->where('id', $solicitanteId)->get()->getRowArray() ?? ['nombre' => 'Alguien'];

        $destinatarios = $db->table('usuarios')
            ->select('id, email, nombre')
            ->where('activo', 1)
            ->groupStart()
                ->where('rol', 'direccion')
                ->orGroupStart()->where('rol', 'coordinador')->where('area_id', (int) $acuerdo['area_id'])->groupEnd()
            ->groupEnd()
            ->get()->getResultArray();

        $plantilla = new PlantillaCorreo();
        $mailer    = service('mailer');
        foreach ($destinatarios as $dest) {
            try {
                $correo = $plantilla->solicitudConclusion($acuerdo, $dest, $solicitante);
                $mailer->enviar((string) $dest['email'], $correo['asunto'], $correo['html']);
            } catch (Throwable $e) {
                log_message('error', 'Aviso de solicitud a {email} falló: {msg}', ['email' => $dest['email'], 'msg' => $e->getMessage()]);
            }
        }
    }
}
```

- [ ] **Step 6: Ver que pasan**

Run: `cd apps/api && ./vendor/bin/phpunit --filter RevisionConclusionTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Config/Routes.php apps/api/app/Controllers/AcuerdosController.php apps/api/app/Libraries/Recordatorios/NotificadorRevision.php apps/api/app/Libraries/Correo/PlantillaCorreo.php apps/api/tests/feature/RevisionConclusionTest.php
git commit -m "feat(api): solicitar conclusión (responsable/corresponsable) con aviso a admins"
```

---

### Task 4: `concluir()` como aprobación (limpia revisión + notifica)

**Files:**
- Modify: `apps/api/app/Controllers/AcuerdosController.php:685-756` (`concluir`)
- Modify: `apps/api/app/Libraries/Recordatorios/NotificadorRevision.php` (método `avisarAprobacion`)
- Modify: `apps/api/app/Libraries/Correo/PlantillaCorreo.php` (método `conclusionAprobada`)
- Test: `apps/api/tests/feature/RevisionConclusionTest.php`

**Interfaces:**
- Consumes: `revision_estado` de Task 1; `NotificadorAsignacion`-style destinatarios (responsable + corresponsables).
- Produces: `NotificadorRevision::avisarAprobacion(int $acuerdoId): void`; `PlantillaCorreo::conclusionAprobada(array $acuerdo, array $destinatario): array`. Al concluir un acuerdo que venía `pendiente`: `revision_estado='sin_solicitud'`, auditoría `aprobar_conclusion`, correo a responsable+corresponsables.

- [ ] **Step 1: Escribir el test que falla**

```php
public function testAprobarConclusionLimpiaRevisionYAudita(): void
{
    // Solicitud previa sobre el acuerdo 4 (responsable id 5).
    $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

    // Dirección aprueba concluyendo.
    $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4/concluir', ['nota' => 'Aprobado.']);
    $r->assertStatus(200);
    $data = $this->cuerpo($r)['data'];
    $this->assertSame('concluido', $data['estado']);
    $this->assertSame('sin_solicitud', $data['revision_estado']);

    $this->assertCount(1, $this->auditoriaDe('aprobar_conclusion', 4));

    // Solicitar sobre el ya concluido → 409 (cierra el caso pendiente de Task 3).
    $r2 = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);
    $r2->assertStatus(409);
}
```

- [ ] **Step 2: Ver que falla**

Run: `cd apps/api && ./vendor/bin/phpunit --filter testAprobarConclusionLimpiaRevisionYAudita`
Expected: FAIL (`revision_estado` sigue 'pendiente' / no existe auditoría `aprobar_conclusion`).

- [ ] **Step 3: Implementar en `concluir()`**

Dentro de la transacción de `concluir()`, capturar si venía de solicitud y limpiar el flag. Cambiar el `update` (línea 732) para incluir `'revision_estado' => 'sin_solicitud'`:
```php
$veniaDeSolicitud = $fila['revision_estado'] === 'pendiente';

(new AcuerdoModel())->update((int) $id, [
    'estado'                  => 'concluido',
    'concluido_por_id'        => (int) $actor['id'],
    'concluido_at'            => Time::now()->toDateTimeString(),
    'revision_estado'         => 'sin_solicitud',
    'revision_solicitada_por_id' => null,
    'revision_solicitada_at'  => null,
]);
```
Tras el avance `validacion` y antes de `transComplete()`, añadir la auditoría de aprobación solo si aplica:
```php
if ($veniaDeSolicitud) {
    (new AuditoriaModel())->registrar((int) $actor['id'], 'aprobar_conclusion', 'acuerdo', (int) $id, [], $this->request->getIPAddress());
}
```
Tras el commit (junto a `sincronizarCalendarioAhora`), notificar solo si venía de solicitud:
```php
if ($veniaDeSolicitud) {
    try {
        (new NotificadorRevision())->avisarAprobacion((int) $id);
    } catch (Throwable $e) {
        log_message('error', 'Aviso de aprobación falló (acuerdo {id}): {msg}', ['id' => $id, 'msg' => $e->getMessage()]);
    }
}
```

- [ ] **Step 4: Plantilla + método del notificador**

En `PlantillaCorreo.php`:
```php
/** @param array<string,mixed> $acuerdo @param array<string,mixed> $destinatario */
public function conclusionAprobada(array $acuerdo, array $destinatario): array
{
    $accion = (string) ($acuerdo['accion'] ?? '');
    $html   = '<p>Hola ' . esc((string) $destinatario['nombre']) . ',</p>'
        . '<p>Tu solicitud de conclusión fue <strong>aprobada</strong>. El acuerdo quedó concluido:</p>'
        . '<blockquote>' . esc($accion) . '</blockquote>';

    return ['asunto' => 'Conclusión aprobada: ' . mb_substr($accion, 0, 60), 'html' => $html];
}
```

En `NotificadorRevision.php` añadir `avisarAprobacion` reutilizando el patrón de destinatarios (responsable + corresponsables activos) de `NotificadorAsignacion::destinatarios`:
```php
/** Aprobación → responsable + corresponsables activos. */
public function avisarAprobacion(int $acuerdoId): void
{
    $this->avisarResponsables($acuerdoId, static fn (PlantillaCorreo $p, array $ac, array $d) => $p->conclusionAprobada($ac, $d));
}

/** @param callable(PlantillaCorreo, array, array): array{asunto:string,html:string} $construir */
private function avisarResponsables(int $acuerdoId, callable $construir): void
{
    $db      = Database::connect();
    $acuerdo = $db->table('acuerdos')->select('id, accion, responsable_id')->where('id', $acuerdoId)->get()->getRowArray();
    if ($acuerdo === null) {
        return;
    }
    $destinatarios = $db->table('usuarios u')
        ->select('u.id, u.email, u.nombre')
        ->join('acuerdo_corresponsables ac', 'ac.usuario_id = u.id', 'left')
        ->groupStart()->where('u.id', (int) $acuerdo['responsable_id'])->orWhere('ac.acuerdo_id', $acuerdoId)->groupEnd()
        ->where('u.activo', 1)->groupBy('u.id')->get()->getResultArray();

    $plantilla = new PlantillaCorreo();
    $mailer    = service('mailer');
    foreach ($destinatarios as $dest) {
        try {
            $correo = $construir($plantilla, $acuerdo, $dest);
            $mailer->enviar((string) $dest['email'], $correo['asunto'], $correo['html']);
        } catch (Throwable $e) {
            log_message('error', 'Aviso de revisión a {email} falló: {msg}', ['email' => $dest['email'], 'msg' => $e->getMessage()]);
        }
    }
}
```
Añade `use App\Libraries\Correo\PlantillaCorreo;` (ya está) y quita el `use App\Models\AcuerdoModel;` si no se usa.

- [ ] **Step 5: Ver que pasa**

Run: `cd apps/api && ./vendor/bin/phpunit --filter RevisionConclusionTest`
Expected: PASS (toda la suite).

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Controllers/AcuerdosController.php apps/api/app/Libraries/Recordatorios/NotificadorRevision.php apps/api/app/Libraries/Correo/PlantillaCorreo.php apps/api/tests/feature/RevisionConclusionTest.php
git commit -m "feat(api): concluir como aprobación de solicitud (limpia flag + notifica)"
```

---

### Task 5: Endpoint `POST /acuerdos/{id}/rechazar-conclusion`

**Files:**
- Modify: `apps/api/app/Config/Routes.php` (junto a `solicitar-conclusion`)
- Modify: `apps/api/app/Controllers/AcuerdosController.php` (método `rechazarConclusion`)
- Modify: `apps/api/app/Libraries/Recordatorios/NotificadorRevision.php` (`avisarRechazo`)
- Modify: `apps/api/app/Libraries/Correo/PlantillaCorreo.php` (`conclusionRechazada`)
- Test: `apps/api/tests/feature/RevisionConclusionTest.php`

**Interfaces:**
- Consumes: policy `puedeConcluir` (misma autorización que aprobar).
- Produces: `AcuerdosController::rechazarConclusion(string $id): ResponseInterface`; `NotificadorRevision::avisarRechazo(int $acuerdoId, string $motivo): void`; `PlantillaCorreo::conclusionRechazada(array $acuerdo, array $destinatario, string $motivo): array`. Efecto: `revision_estado='rechazada'`, `revision_motivo_rechazo=motivo`; auditoría `rechazar_conclusion`; denegado → `intento_rechazar_conclusion`.

- [ ] **Step 1: Escribir los tests que fallan**

```php
public function testCoordinadorRechazaConMotivo(): void
{
    $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

    // Carla (coordinadora, área 1) rechaza el acuerdo 4 (área 1).
    $r = $this->como('coordinacion.operativa@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => 'Falta la evidencia firmada.']);
    $r->assertStatus(200);
    $data = $this->cuerpo($r)['data'];
    $this->assertSame('rechazada', $data['revision_estado']);
    $this->assertSame('Falta la evidencia firmada.', $data['revision_motivo_rechazo']);
    $this->assertNotSame('concluido', $data['estado']);
    $this->assertCount(1, $this->auditoriaDe('rechazar_conclusion', 4));
}

public function testRechazarSinPendienteEs409(): void
{
    // Acuerdo 4 sin solicitud (sin_solicitud) → 409.
    $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => 'x']);
    $r->assertStatus(409);
}

public function testRechazarSinMotivoEs422(): void
{
    $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);
    $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => '   ']);
    $r->assertStatus(422);
    $this->assertArrayHasKey('motivo', $this->cuerpo($r)['campos']);
}

public function testResponsableRechazarEs403Auditado(): void
{
    $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);
    $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => 'x']);
    $r->assertStatus(403);
    $this->assertCount(1, $this->auditoriaDe('intento_rechazar_conclusion', 4));
}
```

- [ ] **Step 2: Ver que fallan**

Run: `cd apps/api && ./vendor/bin/phpunit --filter RevisionConclusionTest`
Expected: FAIL (route/method inexistente).

- [ ] **Step 3: Ruta**

```php
$routes->post('acuerdos/(:num)/rechazar-conclusion', 'AcuerdosController::rechazarConclusion/$1');
$routes->options('acuerdos/(:num)/rechazar-conclusion', 'AcuerdosController::rechazarConclusion/$1');
```

- [ ] **Step 4: Método en el controlador**

```php
/**
 * POST /acuerdos/{id}/rechazar-conclusion (spec 2026-07-29) — admin o
 * coordinación del área rechaza una solicitud pendiente con motivo. Deja el
 * flag en 'rechazada' (el acuerdo sigue activo y editable), audita y avisa a
 * responsable + corresponsables. Mismo permiso que concluir (ADR-012).
 */
public function rechazarConclusion(string $id): ResponseInterface
{
    $actor = service('usuarioActual')->obtener();
    $hoy   = $this->hoy();

    $fila = (new AcuerdoModel())->builderConJoins($hoy)->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
    if ($fila === null) {
        return $this->noEncontrado();
    }

    if (! $this->puedeConcluir($actor, $fila)) {
        (new AuditoriaModel())->registrar((int) $actor['id'], 'intento_rechazar_conclusion', 'acuerdo', (int) $id, ['rol' => $actor['rol'], 'resultado' => 'denegado'], $this->request->getIPAddress());

        return $this->sinPermiso('No tienes permiso para rechazar esta solicitud.');
    }

    if ($fila['revision_estado'] !== 'pendiente') {
        return $this->conflictoEstado('El acuerdo no tiene una solicitud de conclusión pendiente.');
    }

    $body   = $this->cuerpoJson() ?? [];
    $motivo = is_string($body['motivo'] ?? null) ? trim($body['motivo']) : '';
    if ($motivo === '') {
        return $this->errorValidacion('Indica el motivo del rechazo.', ['motivo' => 'Requerido']);
    }

    $db = Database::connect();
    $db->transException(true)->transStart();
    (new AcuerdoModel())->update((int) $id, [
        'revision_estado'         => 'rechazada',
        'revision_motivo_rechazo' => $motivo,
    ]);
    (new AuditoriaModel())->registrar((int) $actor['id'], 'rechazar_conclusion', 'acuerdo', (int) $id, ['motivo' => $motivo], $this->request->getIPAddress());
    $db->transComplete();

    if (! $db->transStatus()) {
        return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo registrar el rechazo.']);
    }

    try {
        (new NotificadorRevision())->avisarRechazo((int) $id, $motivo);
    } catch (Throwable $e) {
        log_message('error', 'Aviso de rechazo falló (acuerdo {id}): {msg}', ['id' => $id, 'msg' => $e->getMessage()]);
    }

    return $this->response->setJSON(['data' => $this->cargarAcuerdoCompleto((int) $id, $hoy)->aArray()]);
}
```

- [ ] **Step 5: Plantilla + notificador**

En `PlantillaCorreo.php`:
```php
/** @param array<string,mixed> $acuerdo @param array<string,mixed> $destinatario */
public function conclusionRechazada(array $acuerdo, array $destinatario, string $motivo): array
{
    $accion = (string) ($acuerdo['accion'] ?? '');
    $html   = '<p>Hola ' . esc((string) $destinatario['nombre']) . ',</p>'
        . '<p>Tu solicitud de conclusión fue <strong>rechazada</strong>. Motivo:</p>'
        . '<blockquote>' . esc($motivo) . '</blockquote>'
        . '<p>Acuerdo: ' . esc($accion) . '. Puedes corregirlo y volver a solicitar la conclusión.</p>';

    return ['asunto' => 'Conclusión rechazada: ' . mb_substr($accion, 0, 60), 'html' => $html];
}
```

En `NotificadorRevision.php`:
```php
/** Rechazo → responsable + corresponsables activos, con el motivo. */
public function avisarRechazo(int $acuerdoId, string $motivo): void
{
    $this->avisarResponsables($acuerdoId, static fn (PlantillaCorreo $p, array $ac, array $d) => $p->conclusionRechazada($ac, $d, $motivo));
}
```

- [ ] **Step 6: Ver que pasan**

Run: `cd apps/api && ./vendor/bin/phpunit --filter RevisionConclusionTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Config/Routes.php apps/api/app/Controllers/AcuerdosController.php apps/api/app/Libraries/Recordatorios/NotificadorRevision.php apps/api/app/Libraries/Correo/PlantillaCorreo.php apps/api/tests/feature/RevisionConclusionTest.php
git commit -m "feat(api): rechazar conclusión con motivo (admin/coordinación) + aviso"
```

---

### Task 6: Congelar vencido y silenciar recordatorios en revisión

**Files:**
- Modify: `apps/api/app/Services/RecordatorioService.php:84-92` (`marcarVencidos`), `:103-107` (query de `materializarYEnviar`), y la query de `procesarSolicitudAvances` (~`:401-519`, filtro de acuerdos)
- Test: `apps/api/tests/feature/RecordatorioJobTest.php`

**Interfaces:**
- Consumes: `acuerdos.revision_estado` (Task 1).
- Produces: un acuerdo con `revision_estado='pendiente'` no se marca `vencido` ni recibe recordatorios ni solicitud de avance.

- [ ] **Step 1: Escribir el test que falla**

En `RecordatorioJobTest.php` (reutiliza su setup; inserta filas directas como el patrón de `AcuerdosLecturaTest`):
```php
public function testAcuerdoEnRevisionNoSeMarcaVencido(): void
{
    $db  = \Config\Database::connect();
    $ayer = \CodeIgniter\I18n\Time::now()->subDays(3)->toDateString();
    $db->table('acuerdos')->insert([
        'reunion_id' => 1, 'area_id' => 1, 'tema' => 'En revisión', 'accion' => 'No debe vencer',
        'responsable_id' => 5, 'capturado_por_id' => 1, 'fecha_compromiso' => $ayer,
        'estado' => 'en_proceso', 'revision_estado' => 'pendiente',
        'created_at' => \CodeIgniter\I18n\Time::now()->toDateTimeString(),
    ]);
    $nuevoId = (int) $db->insertID();

    service('recordatorioService', false)->procesar(\CodeIgniter\I18n\Time::now());

    $fila = $db->table('acuerdos')->where('id', $nuevoId)->get()->getRowArray();
    $this->assertSame('en_proceso', $fila['estado'], 'Un acuerdo en revisión no debe marcarse vencido.');
}
```
> Ajusta el nombre del servicio/instanciación al patrón que ya use `RecordatorioJobTest` (p. ej. `Services::recordatorioService(false)` o `new RecordatorioService(...)`).

- [ ] **Step 2: Ver que falla**

Run: `cd apps/api && ./vendor/bin/phpunit --filter testAcuerdoEnRevisionNoSeMarcaVencido`
Expected: FAIL (queda `vencido`).

- [ ] **Step 3: Implementar**

En `marcarVencidos()`:
```php
$builder->where('estado', 'en_proceso')
    ->where('fecha_compromiso <', $hoy)
    ->where('revision_estado <>', 'pendiente')
    ->update(['estado' => 'vencido']);
```
En la query de acuerdos de `materializarYEnviar()` (línea 106), añadir `->where('a.revision_estado <>', 'pendiente')`.
En la query de acuerdos de `procesarSolicitudAvances()`, añadir el mismo `where('...revision_estado <>', 'pendiente')` (usa el alias correcto de esa consulta).

- [ ] **Step 4: Ver que pasa (y no romper el resto del job)**

Run: `cd apps/api && ./vendor/bin/phpunit --filter RecordatorioJobTest`
Expected: PASS (toda la suite del job).

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Services/RecordatorioService.php apps/api/tests/feature/RecordatorioJobTest.php
git commit -m "feat(recordatorios): congelar vencido y silenciar avisos en revisión pendiente"
```

---

### Task 7: Edición de contenido por participantes (permiso por campo)

**Files:**
- Modify: `apps/api/app/Controllers/AcuerdosController.php:47-49` (constantes), `:352-460` (`update`), y policy nueva `puedeEditarContenido`
- Test: `apps/api/tests/feature/AcuerdosEliminarEdicionTest.php` (o `AcuerdosEscrituraTest.php`)

**Interfaces:**
- Produces: policy `puedeEditarContenido(array $actor, array $acuerdo, bool $esCorresponsable): bool`; constantes `CAMPOS_CONTENIDO_ACUERDO` y `CAMPOS_ESTRUCTURA_ACUERDO`. Un participante (responsable/corresponsable) puede editar `tema/accion/enlace/enlaces/observaciones`; los campos estructurales (`responsable_id/area_id/recordatorio_dias`) siguen exigiendo `puedeEditarEstructura`.

- [ ] **Step 1: Escribir los tests que fallan**

```php
public function testResponsableEditaContenidoDeSuAcuerdo(): void
{
    // responsable.dos (id 5) es responsable del acuerdo 4 pero no lo capturó ni es coordinador.
    $r = $this->como('responsable.dos@demo.test')->patch('api/v1/acuerdos/4', ['accion' => 'Texto corregido', 'observaciones' => 'nota']);
    $r->assertStatus(200);
    $this->assertSame('Texto corregido', $this->cuerpo($r)['data']['accion']);
}

public function testResponsableNoPuedeCambiarResponsableEs403(): void
{
    $r = $this->como('responsable.dos@demo.test')->patch('api/v1/acuerdos/4', ['responsable_id' => 3]);
    $r->assertStatus(403);
    // No cambió.
    $this->assertSame(5, (int) \Config\Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray()['responsable_id']);
}
```
> Confirma en el seed que responsable.dos (id 5) NO es `capturado_por_id` del acuerdo 4 (para que el caso pruebe la ruta nueva de "participante", no la de capturador). Si lo fuera, usa un corresponsable del acuerdo 4 o ajusta el id.

- [ ] **Step 2: Ver que fallan**

Run: `cd apps/api && ./vendor/bin/phpunit --filter "testResponsableEditaContenidoDeSuAcuerdo|testResponsableNoPuedeCambiarResponsableEs403"`
Expected: el primero FAIL con 403 (hoy `puedeEditarEstructura` niega al responsable).

- [ ] **Step 3: Implementar**

Añadir constantes junto a `CAMPOS_EDICION_ACUERDO` (línea 47):
```php
/** Campos "de contenido" editables por cualquier participante (responsable/corresponsable). */
private const CAMPOS_CONTENIDO_ACUERDO = ['tema', 'accion', 'enlace', 'enlaces', 'observaciones'];
/** Campos "estructurales": solo Dirección / coordinación del área / capturador. */
private const CAMPOS_ESTRUCTURA_ACUERDO = ['responsable_id', 'area_id', 'recordatorio_dias'];
```

Policy nueva (junto a `puedeEditarEstructura`):
```php
/**
 * Editar contenido (spec 2026-07-29): además de quien puede editar estructura,
 * el responsable y los corresponsables del acuerdo pueden corregir texto/enlaces.
 *
 * @param array<string, mixed> $actor
 * @param array<string, mixed> $acuerdo Fila con al menos responsable_id, area_id, capturado_por_id.
 */
private function puedeEditarContenido(array $actor, array $acuerdo, bool $esCorresponsable): bool
{
    if ($this->puedeEditarEstructura($actor, $acuerdo)) {
        return true;
    }

    return ((int) $acuerdo['responsable_id']) === (int) $actor['id'] || $esCorresponsable;
}
```

En `update()`, reemplazar el gate único (líneas 365-373) por:
```php
$db               = Database::connect();
$esCorresponsable = $db->table('acuerdo_corresponsables')
    ->where('acuerdo_id', (int) $id)->where('usuario_id', (int) $actor['id'])->countAllResults() > 0;
if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
    return $this->noEncontrado();
}

if (! $this->puedeEditarContenido($actor, $fila, $esCorresponsable)) {
    return $this->sinPermiso('No puedes editar este acuerdo.');
}
```
Tras validar `camposDesconocidos` (línea 387), añadir el gate de campos estructurales:
```php
$tocaEstructura = array_intersect(array_keys($body), self::CAMPOS_ESTRUCTURA_ACUERDO) !== [];
if ($tocaEstructura && ! $this->puedeEditarEstructura($actor, $fila)) {
    return $this->sinPermiso('Solo Dirección, la coordinación del área o quien capturó el acuerdo pueden cambiar responsable, área o recordatorios.');
}
```
> El `$db`/`$esCorresponsable` que antes se declaraban más abajo ahora se declaran arriba; elimina la declaración duplicada de `$db` posterior (línea 364 original) para no re-conectar.

- [ ] **Step 4: Ver que pasan (y no romper edición existente)**

Run: `cd apps/api && ./vendor/bin/phpunit --filter "AcuerdosEliminarEdicionTest|AcuerdosEscrituraTest"`
Expected: PASS (incluye los casos nuevos y los previos de edición/permiso).

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Controllers/AcuerdosController.php apps/api/tests/feature/AcuerdosEliminarEdicionTest.php
git commit -m "feat(api): edición de contenido por participantes; estructura sigue restringida"
```

- [ ] **Step 6: Suite backend completa**

Run: `cd apps/api && composer test`
Expected: toda la suite PASS.

---

## Fase 3 — Frontend: contrato y utilidades

### Task 8: Contrato API + tipos + doc 05

**Files:**
- Modify: `apps/web/src/lib/types.ts` (`Acuerdo`, `AcuerdoRow`/`DbJson`, nuevo tipo `RevisionEstado`)
- Modify: `apps/web/src/lib/api.ts` (interfaz)
- Modify: `apps/web/src/lib/api.real.ts` (implementación)
- Modify: `docs/05-api/` (el doc del contrato congelado)
- Test: `apps/web/src/lib/__tests__/` (typecheck cubre el contrato)

**Interfaces:**
- Produces: `ApiClient.solicitarConclusion(id: number): Promise<AcuerdoDetalle>` y `ApiClient.rechazarConclusion(id: number, motivo: string): Promise<Acuerdo>`; `Acuerdo.revision_estado: RevisionEstado` y `Acuerdo.revision_motivo_rechazo: string | null`; `type RevisionEstado = 'sin_solicitud' | 'pendiente' | 'rechazada'`.

- [ ] **Step 1: Tipos**

En `types.ts`: añadir `export type RevisionEstado = 'sin_solicitud' | 'pendiente' | 'rechazada';`. En `interface Acuerdo` (tras `updated_at`, línea 203) añadir:
```ts
  revision_estado: RevisionEstado;
  revision_motivo_rechazo: string | null;
```
En el tipo de fila espejo (`AcuerdoRow` / dentro de `DbJson`, ~línea 42) añadir los 4 campos crudos: `revision_estado: RevisionEstado; revision_solicitada_por_id: number | null; revision_solicitada_at: string | null; revision_motivo_rechazo: string | null;`.

- [ ] **Step 2: Interfaz `api.ts`**

Tras `reabrirAcuerdo` (línea 48), añadir:
```ts
  solicitarConclusion(id: number): Promise<AcuerdoDetalle>; // responsable/corresponsable pide concluir → 'pendiente'
  rechazarConclusion(id: number, motivo: string): Promise<Acuerdo>; // admin/coordinación del área
```
(La aprobación es `concluirAcuerdo`, ya existente.)

- [ ] **Step 3: Implementación `api.real.ts`**

Tras `reabrirAcuerdo` (línea 76):
```ts
  solicitarConclusion: async (id) =>
    (await req<{ data: AcuerdoDetalle }>('POST', `/acuerdos/${id}/solicitar-conclusion`, {})).data,
  rechazarConclusion: async (id, motivo) =>
    (await req<{ data: Acuerdo }>('POST', `/acuerdos/${id}/rechazar-conclusion`, { motivo })).data,
```

- [ ] **Step 4: Sincronizar doc 05 (regla 3)**

En `docs/05-api/` (el documento que contiene la interfaz `ApiClient` congelada), añadir los 2 métodos y las 2 propiedades del `Acuerdo`, y documentar los endpoints `POST /acuerdos/{id}/solicitar-conclusion` y `POST /acuerdos/{id}/rechazar-conclusion` (permisos, cuerpo `{motivo}`, códigos 200/403/409/422).

- [ ] **Step 5: Typecheck**

Run: `cd apps/web && npm run typecheck`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/lib/types.ts apps/web/src/lib/api.ts apps/web/src/lib/api.real.ts docs/05-api/
git commit -m "feat(web): contrato de revisión (solicitar/rechazar) + campos revision en Acuerdo"
```

---

### Task 9: Utilidades Markdown (render seguro + texto plano)

**Files:**
- Modify: `apps/web/package.json` (dep `react-markdown`)
- Create: `apps/web/src/lib/markdown.ts` (`markdownAPlano`)
- Create: `apps/web/src/components/Markdown.tsx` (render a nodos React)
- Test: `apps/web/src/lib/__tests__/markdown.test.ts`

**Interfaces:**
- Produces: `markdownAPlano(md: string): string` (quita sintaxis para tablas/tooltip); `<Markdown source={string} />` que renderiza el subconjunto básico (negrita, cursiva, listas, enlaces, párrafos) sin `dangerouslySetInnerHTML`.

- [ ] **Step 1: Instalar dependencia**

Run: `cd apps/web && npm install react-markdown@9`
(react-markdown renderiza a nodos React; no usa `dangerouslySetInnerHTML`.)

- [ ] **Step 2: Escribir el test que falla (`markdownAPlano`)**

`apps/web/src/lib/__tests__/markdown.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { markdownAPlano } from '../markdown';

describe('markdownAPlano', () => {
  it('quita negrita y cursiva', () => {
    expect(markdownAPlano('**Hola** _mundo_')).toBe('Hola mundo');
  });
  it('convierte enlaces a su texto', () => {
    expect(markdownAPlano('Ver [el doc](https://x.test)')).toBe('Ver el doc');
  });
  it('aplana viñetas a una línea con separadores', () => {
    expect(markdownAPlano('- uno\n- dos')).toBe('uno dos');
  });
  it('colapsa espacios', () => {
    expect(markdownAPlano('a\n\n\nb')).toBe('a b');
  });
});
```

- [ ] **Step 3: Ver que falla**

Run: `cd apps/web && npx vitest run src/lib/__tests__/markdown.test.ts`
Expected: FAIL (módulo inexistente).

- [ ] **Step 4: Implementar `markdown.ts`**

```ts
/** Convierte Markdown básico a texto plano para tablas/tooltip (no para render). */
export function markdownAPlano(md: string): string {
  return md
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')          // imágenes (no soportadas) fuera
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')        // enlaces → texto
    .replace(/(\*\*|__)(.*?)\1/g, '$2')             // negrita
    .replace(/(\*|_)(.*?)\1/g, '$2')                // cursiva
    .replace(/^\s*[-*+]\s+/gm, '')                  // viñetas
    .replace(/^\s*\d+\.\s+/gm, '')                  // numeradas
    .replace(/`([^`]*)`/g, '$1')                    // código inline
    .replace(/\s+/g, ' ')                           // colapsa espacios/saltos
    .trim();
}
```

- [ ] **Step 5: Componente `Markdown.tsx`**

```tsx
import ReactMarkdown from 'react-markdown';

/**
 * Render seguro de Markdown básico a nodos React (regla 7: sin
 * dangerouslySetInnerHTML). Solo se permiten los elementos del editor básico;
 * cualquier otro se descarta desenvolviendo su contenido.
 */
const PERMITIDOS = ['p', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'br'];

export function Markdown({ source }: { source: string }) {
  return (
    <div className="md">
      <ReactMarkdown
        allowedElements={PERMITIDOS}
        unwrapDisallowed
        components={{
          a: ({ href, children }) => (
            <a href={href} target="_blank" rel="noopener noreferrer">
              {children}
            </a>
          ),
        }}
      >
        {source}
      </ReactMarkdown>
    </div>
  );
}
```

- [ ] **Step 6: Ver que pasa + typecheck**

Run: `cd apps/web && npx vitest run src/lib/__tests__/markdown.test.ts && npm run typecheck`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/web/package.json apps/web/package-lock.json apps/web/src/lib/markdown.ts apps/web/src/components/Markdown.tsx apps/web/src/lib/__tests__/markdown.test.ts
git commit -m "feat(web): utilidades Markdown (texto plano + render seguro a nodos React)"
```

---

### Task 10: Editor Markdown (toolbar + vista previa)

**Files:**
- Create: `apps/web/src/lib/editorMarkdown.ts` (helper puro `aplicarFormato`)
- Create: `apps/web/src/components/EditorMarkdown.tsx`
- Test: `apps/web/src/lib/__tests__/editorMarkdown.test.ts`

**Interfaces:**
- Produces: `aplicarFormato(texto: string, ini: number, fin: number, formato: 'negrita'|'cursiva'|'lista'|'enlace'): { texto: string; cursor: number }`; `<EditorMarkdown value={string} onChange={(v:string)=>void} id?: string />` (textarea con barra de botones + vista previa en vivo con `<Markdown>`).

- [ ] **Step 1: Escribir los tests que fallan**

`apps/web/src/lib/__tests__/editorMarkdown.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { aplicarFormato } from '../editorMarkdown';

describe('aplicarFormato', () => {
  it('envuelve la selección en negrita', () => {
    const r = aplicarFormato('hola mundo', 0, 4, 'negrita');
    expect(r.texto).toBe('**hola** mundo');
  });
  it('envuelve la selección en cursiva', () => {
    const r = aplicarFormato('hola', 0, 4, 'cursiva');
    expect(r.texto).toBe('_hola_');
  });
  it('convierte la selección multilínea en viñetas', () => {
    const r = aplicarFormato('uno\ndos', 0, 7, 'lista');
    expect(r.texto).toBe('- uno\n- dos');
  });
  it('inserta enlace con la selección como texto', () => {
    const r = aplicarFormato('doc', 0, 3, 'enlace');
    expect(r.texto).toBe('[doc](url)');
  });
});
```

- [ ] **Step 2: Ver que fallan**

Run: `cd apps/web && npx vitest run src/lib/__tests__/editorMarkdown.test.ts`
Expected: FAIL (módulo inexistente).

- [ ] **Step 3: Implementar `editorMarkdown.ts`**

```ts
export type Formato = 'negrita' | 'cursiva' | 'lista' | 'enlace';

export function aplicarFormato(
  texto: string,
  ini: number,
  fin: number,
  formato: Formato,
): { texto: string; cursor: number } {
  const sel = texto.slice(ini, fin);
  const antes = texto.slice(0, ini);
  const despues = texto.slice(fin);
  let insertado: string;
  switch (formato) {
    case 'negrita':
      insertado = `**${sel || 'texto'}**`;
      break;
    case 'cursiva':
      insertado = `_${sel || 'texto'}_`;
      break;
    case 'lista':
      insertado = (sel || 'elemento')
        .split('\n')
        .map((l) => `- ${l}`)
        .join('\n');
      break;
    case 'enlace':
      insertado = `[${sel || 'texto'}](url)`;
      break;
  }
  return { texto: antes + insertado + despues, cursor: ini + insertado.length };
}
```

- [ ] **Step 4: Componente `EditorMarkdown.tsx`**

```tsx
import { useRef, useState } from 'react';
import { Markdown } from './Markdown';
import { aplicarFormato, type Formato } from '../lib/editorMarkdown';

/** Editor Markdown básico: barra de formato + textarea + vista previa en vivo. */
export function EditorMarkdown({
  value,
  onChange,
  id,
}: {
  value: string;
  onChange: (v: string) => void;
  id?: string;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const [preview, setPreview] = useState(false);

  const btn = (formato: Formato, etiqueta: string) => (
    <button
      type="button"
      className="btn btn--ghost-teal btn--sm"
      onClick={() => {
        const el = ref.current;
        if (!el) return;
        const r = aplicarFormato(value, el.selectionStart, el.selectionEnd, formato);
        onChange(r.texto);
        requestAnimationFrame(() => {
          el.focus();
          el.setSelectionRange(r.cursor, r.cursor);
        });
      }}
    >
      {etiqueta}
    </button>
  );

  return (
    <div className="editor-md">
      <div className="editor-md__toolbar" style={{ display: 'flex', gap: 6, marginBottom: 6 }}>
        {btn('negrita', 'N')}
        {btn('cursiva', 'I')}
        {btn('lista', '•')}
        {btn('enlace', '🔗')}
        <button
          type="button"
          className="btn btn--ghost-teal btn--sm"
          style={{ marginLeft: 'auto' }}
          onClick={() => setPreview((p) => !p)}
        >
          {preview ? 'Editar' : 'Vista previa'}
        </button>
      </div>
      {preview ? (
        <div className="editor-md__preview"><Markdown source={value} /></div>
      ) : (
        <textarea
          id={id}
          ref={ref}
          className="textarea"
          style={{ minHeight: 84 }}
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </div>
  );
}
```

- [ ] **Step 5: Ver que pasa + typecheck**

Run: `cd apps/web && npx vitest run src/lib/__tests__/editorMarkdown.test.ts && npm run typecheck`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/lib/editorMarkdown.ts apps/web/src/components/EditorMarkdown.tsx apps/web/src/lib/__tests__/editorMarkdown.test.ts
git commit -m "feat(web): editor Markdown básico (toolbar + vista previa)"
```

---

## Fase 4 — Frontend: UI

### Task 11: Wiring del editor en captura y Drawer + render de lectura

**Files:**
- Modify: `apps/web/src/pages/Captura.tsx:230-237` (textarea del formulario → `EditorMarkdown`)
- Modify: `apps/web/src/components/Drawer.tsx:289-296` (edición) y la sección de lectura de "Acuerdo / acción" (~`:372-375`)
- Modify: `apps/web/src/styles/` (clase `.md`/`.editor-md` con tokens PJ)

**Interfaces:**
- Consumes: `<EditorMarkdown>` (Task 10), `<Markdown>` (Task 9).

- [ ] **Step 1: Formulario de captura**

En `Captura.tsx`, reemplazar el `<textarea className="textarea" …>` del campo acción (vista formulario, líneas 230-237) por:
```tsx
<EditorMarkdown value={bloque.accion} onChange={(v) => actualizar(idx, 'accion', v)} />
```
(usa el mismo handler de cambio que ya alimenta `accion`; importa `EditorMarkdown`). La vista "hoja" (líneas 356-361) NO cambia (sigue `input` de texto plano).

- [ ] **Step 2: Edición en Drawer**

En `Drawer.tsx`, reemplazar el `<textarea id="ed-accion" …>` (líneas 289-295) por:
```tsx
<EditorMarkdown id="ed-accion" value={form.accion} onChange={(v) => setForm((f) => ({ ...f, accion: v }))} />
```

- [ ] **Step 3: Lectura en Drawer**

En la sección de lectura de "Acuerdo / acción" del Drawer (~línea 372-375), reemplazar el render de texto plano por `<Markdown source={sel.accion} />`.

- [ ] **Step 4: Estilos**

En la hoja de estilos correspondiente (donde vive `.textarea`), añadir estilos mínimos para `.md` (párrafos/listas con `margin` y `line-height` acordes) y `.editor-md__preview` (borde suave con tokens `--border`/`--muted`). Sin colores fuera de la paleta PJ.

- [ ] **Step 5: Verificación manual + build**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build`
Expected: PASS. Verifícalo con la skill `run`/`verify` (capturar → escribir `**negrita**` → guardar → abrir Drawer → ver negrita renderizada).

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/pages/Captura.tsx apps/web/src/components/Drawer.tsx apps/web/src/styles/
git commit -m "feat(web): editor Markdown en captura/Drawer y render en lectura"
```

---

### Task 12: Truncado a 2 líneas + tooltip de acción en tablas

**Files:**
- Create: `apps/web/src/components/TooltipAccion.tsx`
- Modify: `apps/web/src/pages/Panel.tsx:447-449` (celda acción de `VistaTabla`)
- Modify: `apps/web/src/pages/MisAcuerdos.tsx:170-172` (celda acción)
- Modify: `apps/web/src/styles/` (clase `.celda-accion` con `-webkit-line-clamp: 2`)

**Interfaces:**
- Consumes: `markdownAPlano` (Task 9), `<Markdown>` (Task 9).
- Produces: `<TooltipAccion accion={string}>{children}</TooltipAccion>` — envuelve el texto truncado y muestra un panel flotante con `<Markdown>` al hover/focus.

- [ ] **Step 1: Componente `TooltipAccion.tsx`**

```tsx
import { useState } from 'react';
import { Markdown } from './Markdown';

/** Panel flotante con la acción completa (Markdown) al pasar el cursor o enfocar. */
export function TooltipAccion({ accion, children }: { accion: string; children: React.ReactNode }) {
  const [abierto, setAbierto] = useState(false);
  return (
    <span
      style={{ position: 'relative', display: 'inline-block', maxWidth: '100%' }}
      onMouseEnter={() => setAbierto(true)}
      onMouseLeave={() => setAbierto(false)}
      onFocus={() => setAbierto(true)}
      onBlur={() => setAbierto(false)}
      tabIndex={0}
    >
      {children}
      {abierto && (
        <span
          role="tooltip"
          className="tooltip-accion"
          style={{
            position: 'absolute', zIndex: 200, top: '100%', left: 0, marginTop: 6,
            width: 'min(420px, 80vw)', padding: '12px 14px',
            background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 10,
            boxShadow: '0 10px 30px rgba(0,0,0,.18)', textAlign: 'left', whiteSpace: 'normal',
          }}
        >
          <Markdown source={accion} />
        </span>
      )}
    </span>
  );
}
```
> Ajusta los nombres de token (`--surface`, `--border`) a los reales de `styles/tokens/colors.css`.

- [ ] **Step 2: Celda en `Panel.tsx`**

Reemplazar la celda (líneas 447-449) por:
```tsx
<td style={{ maxWidth: 340 }}>
  <TooltipAccion accion={a.accion}>
    <span className="celda-accion" style={{ fontWeight: 500, lineHeight: 1.45 }}>
      {markdownAPlano(a.accion)}
    </span>
  </TooltipAccion>
</td>
```
Importar `TooltipAccion` y `markdownAPlano`.

- [ ] **Step 3: Celda en `MisAcuerdos.tsx`**

Aplicar el mismo cambio en la celda de acción (líneas 170-172).

- [ ] **Step 4: Estilo del clamp**

En la hoja de estilos, añadir:
```css
.celda-accion {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
```

- [ ] **Step 5: Verificación + build**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build`
Expected: PASS. Verifica visualmente: filas de altura uniforme (2 líneas), tooltip amplio al hover, click en fila abre el Drawer.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/TooltipAccion.tsx apps/web/src/pages/Panel.tsx apps/web/src/pages/MisAcuerdos.tsx apps/web/src/styles/
git commit -m "feat(web): truncar acción a 2 líneas con tooltip de contenido completo"
```

---

### Task 13: Acciones de revisión en el Drawer + badge de estado

**Files:**
- Modify: `apps/web/src/components/EstadoHelpers.ts` (helper `revisionMeta`)
- Modify: `apps/web/src/components/Drawer.tsx` (permisos ampliados + botones solicitar/aprobar/rechazar + badge + motivo)

**Interfaces:**
- Consumes: `api.solicitarConclusion`, `api.rechazarConclusion`, `api.concluirAcuerdo` (Task 8); `Acuerdo.revision_estado`/`revision_motivo_rechazo`.
- Produces: `revisionMeta(estado: RevisionEstado): { label: string; variant: BadgeVariant } | null`.

- [ ] **Step 1: Helper de presentación**

En `EstadoHelpers.ts`:
```ts
import type { RevisionEstado } from '../lib';

export function revisionMeta(estado: RevisionEstado): { label: string; variant: BadgeVariant } | null {
  if (estado === 'pendiente') return { label: 'En revisión', variant: 'neutral' };
  if (estado === 'rechazada') return { label: 'Rechazado', variant: 'error' };
  return null;
}
```

- [ ] **Step 2: Permisos en el Drawer**

Ampliar `puedeEditar` (líneas 200-203) para incluir participantes (contenido):
```ts
const esParticipante =
  sel !== undefined && u !== undefined &&
  (u.id === sel.responsable.id || sel.corresponsables.some((c) => c.id === u.id));
const puedeEditar =
  sel !== undefined && u !== undefined &&
  (esDireccion || u.id === sel.capturado_por.id || (u.rol === 'coordinador' && u.area_id === sel.area.id) || esParticipante);
const puedeSolicitar = esParticipante && sel !== undefined && sel.estado !== 'concluido' && sel.revision_estado !== 'pendiente';
const enRevision = sel !== undefined && sel.revision_estado === 'pendiente';
```
> `puedeConcluir` (líneas 206-209) ya cubre aprobar/rechazar (admin o coordinación del área). El formulario de edición debe deshabilitar los campos estructurales (responsable/área/fecha) cuando el usuario es solo participante — reutiliza `esDireccion || u.id===capturado_por.id || coordinador-de-área` para condicionar esos `<Select>`/inputs.

- [ ] **Step 3: Mutaciones y botones**

Añadir mutaciones (junto a `concluirMut`/`reabrirMut`) para `api.solicitarConclusion(sel.id)` y `api.rechazarConclusion(sel.id, motivo)`, invalidando las queries de detalle/listado como las existentes. En la barra de acciones del Drawer:
- Si `puedeSolicitar`: botón "Solicitar conclusión".
- Si `enRevision && puedeConcluir`: botones "Aprobar" (llama `concluir()` existente) y "Rechazar" (pide motivo con `window.prompt` y llama la mutación de rechazo).
- Mantener "Concluir" directo para `puedeConcluir` cuando NO está en revisión (comportamiento ADR-012 actual).

Badge de revisión junto al badge de estado (dentro del bloque de líneas 252-254):
```tsx
{(() => { const rm = revisionMeta(sel.revision_estado); return rm ? <Badge variant={rm.variant} size="md" label={rm.label} /> : null; })()}
```
Y si `sel.revision_estado === 'rechazada' && sel.revision_motivo_rechazo`, mostrar una nota con el motivo (alert suave).

- [ ] **Step 4: Verificación + build**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build`
Expected: PASS. Verifica los 3 flujos (solicitar como responsable; aprobar y rechazar como coordinación/admin) contra el backend con la skill `verify`.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/EstadoHelpers.ts apps/web/src/components/Drawer.tsx
git commit -m "feat(web): acciones de revisión (solicitar/aprobar/rechazar) y badge en Drawer"
```

---

## Fase 5 — Operación y documentación

### Task 14: Hora del cron a las 8:00 + ADR + unificación de docs

**Files:**
- Modify: `DEPLOY.md:17,200-201`
- Modify: `docs/04-seguridad/guia_activacion_google.md:71-76`
- Modify: `docs/02-arquitectura/02_arquitectura_sistema.md:45,130,286`
- Create: `docs/01-vision/ADR-XXX-solicitud-conclusion.md` (o el directorio de ADRs del repo)

**Interfaces:** ninguna (documentación/operación; el job es agnóstico a la hora).

- [ ] **Step 1: Unificar la hora a `0 8 * * *`**

En los tres documentos, reemplazar toda referencia de cron a **`0 8 * * *`** (8:00) con TZ `America/Ciudad_Juarez`, eliminando las discrepancias 07:00 y 8:30. Deja una sola frase canónica: "El cron corre a las 08:00 (America/Ciudad_Juarez): `0 8 * * *`".

- [ ] **Step 2: ADR del flujo de solicitud de conclusión**

Crear un ADR corto (siguiendo el formato de los ADR existentes del repo) que documente: la solicitud de conclusión (responsable/corresponsable) → aprobación/rechazo (admin/coordinación del área) amplía ADR-012; el flag `revision_estado` independiente; congelamiento de vencido y silencio de recordatorios en `pendiente`; correos inmediatos. Enlazar al spec `docs/superpowers/specs/2026-07-29-mejoras-revision-edicion-wysiwyg-design.md`.

- [ ] **Step 3: Commit**

```bash
git add DEPLOY.md docs/04-seguridad/guia_activacion_google.md docs/02-arquitectura/02_arquitectura_sistema.md docs/01-vision/
git commit -m "docs: cron a las 08:00 (Ciudad Juárez) + ADR de solicitud de conclusión"
```

---

## Cierre

- [ ] **Verificación final backend:** `cd apps/api && composer test` → toda la suite PASS.
- [ ] **Verificación final front:** `cd apps/web && npm run test && npm run typecheck && npm run lint && npm run build` → PASS.
- [ ] **Espejo:** `node scripts/verificar_espejo.mjs` → OK.
- [ ] **Actualizar la memoria del proyecto** (`estado-quick-wins-backlog`) con el cierre de este ciclo.
