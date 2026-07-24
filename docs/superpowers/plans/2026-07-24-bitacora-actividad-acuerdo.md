# Bitácora unificada del acuerdo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir una bitácora unificada por acuerdo que combine los `avances` con los eventos de `auditoria` de ciclo de vida (crear/editar/corresponsables), expuesta vía un endpoint dedicado y consumida por el Drawer.

**Architecture:** Nuevo endpoint de lectura `GET /acuerdos/{id}/actividad` que reúne dos consultas (una a `avances`, una a `auditoria` filtrada por `accion`), normaliza cada fila a un `EventoActividad` con descripción legible generada en el servidor, y devuelve la lista ordenada desc por fecha. El front añade el tipo + método de cliente y el Drawer reemplaza su lectura de `sel.avances` por una query a este endpoint.

**Tech Stack:** Backend CodeIgniter 4.7 (PHP 8.3) + PHPUnit (grupo `database`, MySQL de test en Docker :3316). Frontend React 19 + TS 5 + TanStack Query 5.

## Global Constraints

- **Cero N+1** (regla #9): la actividad se arma con **un query por tabla** (`avances`, `auditoria`) + un `whereIn` para hidratar usuarios. Nunca un query por evento.
- **Query Builder / prepared statements siempre** (regla #7); nunca concatenar SQL.
- **Visibilidad de lectura ADR-007**: lectura abierta a `direccion`/`coordinador`/`responsable`; rol `pendiente` → 403 `cuenta_pendiente`. Reusar la Policy `VisibilidadAcuerdos::puedeVer` igual que `show()`.
- **Toda fecha en `America/Ciudad_Juarez`** (regla #6): `created_at` se devuelve tal cual lo guarda la BD (ya en esa TZ).
- **Contrato = doc 05** (regla #3): cualquier cambio de tipos/endpoint se refleja en `docs/05-api/05_especificacion_api.md` en la misma sesión.
- **Sin `dangerouslySetInnerHTML`** (regla #7); React escapa por defecto.
- **Marca**: "Participa Juárez", nunca "Plan Juárez" en copy visible.
- **No duplicar**: `concluir`/`reabrir`/`eliminar`/`intento_*` **se excluyen** de la auditoría en la bitácora (concluir/reabrir ya llegan como avance `validacion`/`reapertura`).

## File Structure

**Backend:**
- Modify `apps/api/app/Config/Routes.php` — registrar `GET` + `OPTIONS` de `acuerdos/(:num)/actividad`.
- Modify `apps/api/app/Controllers/AcuerdosController.php` — método `actividad(string $id)` + helpers privados `construirActividad()` y `descripcionAuditoria()`.
- Create `apps/api/tests/feature/ActividadAcuerdoTest.php` — pruebas del endpoint.

**Frontend:**
- Modify `apps/web/src/lib/types.ts` — interfaz `EventoActividad`.
- Modify `apps/web/src/lib/api.ts` — método `actividadAcuerdo` en `ApiClient`.
- Modify `apps/web/src/lib/api.real.ts` — implementación.
- Modify `apps/web/src/components/Drawer.tsx` — la sección Bitácora consume el endpoint.

**Docs:**
- Modify `docs/05-api/05_especificacion_api.md` — documentar el endpoint y el tipo.

---

## Task 1: Backend — endpoint `GET /acuerdos/{id}/actividad`

**Files:**
- Modify: `apps/api/app/Config/Routes.php` (grupo de rutas de acuerdos, junto a las líneas 42–43)
- Modify: `apps/api/app/Controllers/AcuerdosController.php` (nuevo método público + 2 helpers privados)
- Test: `apps/api/tests/feature/ActividadAcuerdoTest.php`

**Interfaces:**
- Consumes: `VisibilidadAcuerdos::puedeVer(array $actor, array $acuerdo, bool $esCorresponsable): bool`; `UsuarioRef::desdeFila(array): UsuarioRef`; `AvanceModel`, `AuditoriaModel`, `AcuerdoModel::builderConJoins(string $hoy)`.
- Produces: endpoint `GET /api/v1/acuerdos/{id}/actividad` → `{ data: EventoActividad[] }`, donde cada elemento tiene la forma:
  ```json
  { "id": "avance:12", "fuente": "avance", "tipo": "validacion",
    "usuario": {"id":1,"nombre":"...","email":"...","avatar_color":null},
    "descripcion": "…", "nueva_fecha": null, "created_at": "2026-07-20 10:00:00" }
  ```
  `usuario` es `null` cuando la fila de auditoría tiene `usuario_id` nulo (acción del sistema).

- [ ] **Step 1: Escribir el test que falla**

Crear `apps/api/tests/feature/ActividadAcuerdoTest.php`. Reusa el patrón de `AcuerdosLecturaTest` (traits `DatabaseTestTrait`/`FeatureTestTrait`/`FechaFijaTrait`, `FakeTokenVerifier`, seed `InitialSeeder`, helpers `como()`/`cuerpo()`).

```php
<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FakeTokenVerifier;
use Tests\Support\FechaFijaTrait;

/**
 * GET /acuerdos/{id}/actividad — bitácora unificada (quick win #3).
 * Une avances + auditoría de ciclo de vida (crear/editar/corresponsables)
 * sin duplicar concluir/reabrir (ya presentes como avance).
 *
 * @group database
 *
 * @internal
 */
final class ActividadAcuerdoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use FechaFijaTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private const UID = [
        'direccion@demo.test'              => 'fb-demo-direccion-001',
        'coordinacion.operativa@demo.test' => 'fb-demo-coord-001',
        'responsable.uno@demo.test'        => 'fb-demo-resp-001',
    ];

    private FakeTokenVerifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarFechaTest();
        $this->fake = new FakeTokenVerifier();
        Services::injectMock('tokenVerifier', $this->fake);
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        Services::reset();
        $this->resetFechaTest();
        parent::tearDown();
    }

    private function como(string $email): self
    {
        $this->fake->exito(self::UID[$email], $email, true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);

        return $this;
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    public function testIncluyeCreacionYAvancesOrdenadosDesc(): void
    {
        // Acuerdo 3 (abierto, del seed) — la creación viene del seed vía auditoría 'crear'.
        // Registramos un avance para tener al menos dos eventos.
        $this->como('direccion@demo.test')
            ->post('api/v1/acuerdos/3/avances', ['tipo' => 'avance', 'descripcion' => 'Avance de prueba']);

        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos/3/actividad');
        $r->assertStatus(200);
        $eventos = $this->cuerpo($r)['data'];

        // Orden desc por created_at (el avance recién creado es el más nuevo → primero).
        $this->assertSame('avance', $eventos[0]['tipo']);
        $this->assertSame('avance:', substr($eventos[0]['id'], 0, 7));

        // Cada evento trae fuente y descripción no vacía.
        foreach ($eventos as $ev) {
            $this->assertContains($ev['fuente'], ['avance', 'auditoria']);
            $this->assertNotSame('', $ev['descripcion']);
        }
    }

    public function testConclusionApareceUnaSolaVezSinDuplicar(): void
    {
        // Concluir genera avance 'validacion' + auditoría 'concluir'. La bitácora
        // debe mostrar SOLO el avance validacion (no el registro de auditoría concluir).
        $this->como('direccion@demo.test')
            ->patch('api/v1/acuerdos/3/concluir', ['nota' => 'Cerrado en reunión']);

        $r        = $this->como('direccion@demo.test')->get('api/v1/acuerdos/3/actividad');
        $eventos  = $this->cuerpo($r)['data'];
        $tipos    = array_column($eventos, 'tipo');

        $this->assertSame(1, count(array_filter($tipos, static fn ($t) => $t === 'validacion')));
        $this->assertNotContains('concluir', $tipos);
        $this->assertNotContains('reabrir', $tipos);
    }

    public function testEdicionDescribeCamposCambiados(): void
    {
        $this->como('direccion@demo.test')
            ->patch('api/v1/acuerdos/3', ['tema' => 'Tema actualizado']);

        $r       = $this->como('direccion@demo.test')->get('api/v1/acuerdos/3/actividad');
        $eventos = $this->cuerpo($r)['data'];
        $editar  = array_values(array_filter($eventos, static fn ($e) => $e['tipo'] === 'editar'));

        $this->assertNotEmpty($editar);
        $this->assertStringContainsString('tema', $editar[0]['descripcion']);
    }

    public function testPendienteRecibe403(): void
    {
        // Un UID sin usuario operativo se autorregistra como 'pendiente' (ADR-006).
        $this->fake->exito('fb-uid-nuevo-999', 'nuevo@planjuarez.org', true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->post('api/v1/registro', ['nombre' => 'Nuevo Usuario']);

        $r = $this->get('api/v1/acuerdos/3/actividad');
        $r->assertStatus(403);
        $this->assertSame('cuenta_pendiente', $this->cuerpo($r)['error']);
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `cd apps/api && vendor/bin/phpunit --filter ActividadAcuerdoTest`
Expected: FAIL — ruta inexistente (404) / método `actividad` no definido.

- [ ] **Step 3: Registrar la ruta**

En `apps/api/app/Config/Routes.php`, junto a las rutas de avances (líneas 42–43), añadir:

```php
    $routes->get('acuerdos/(:num)/actividad', 'AcuerdosController::actividad/$1');
    $routes->options('acuerdos/(:num)/actividad', 'AcuerdosController::actividad/$1');
```

- [ ] **Step 4: Implementar el método `actividad()` y sus helpers**

En `apps/api/app/Controllers/AcuerdosController.php`, añadir el método público (modela el gate de `show()`):

```php
public function actividad(string $id): ResponseInterface
{
    $actor = service('usuarioActual')->obtener();
    $hoy   = $this->hoy();
    $db    = Database::connect();

    $fila = (new AcuerdoModel())->builderConJoins($hoy)
        ->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
    if ($fila === null) {
        return $this->noEncontrado();
    }

    $esCorresponsable = $db->table('acuerdo_corresponsables')
        ->where('acuerdo_id', (int) $id)
        ->where('usuario_id', (int) $actor['id'])
        ->countAllResults() > 0;
    if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
        return $this->noEncontrado();
    }

    return $this->response->setJSON(['data' => $this->construirActividad((int) $id)]);
}
```

Helper privado que arma la lista unificada (dos queries + hidratación por `whereIn`):

```php
/**
 * Une avances + auditoría de ciclo de vida en eventos normalizados,
 * ordenados desc por created_at. Cero N+1: un query por tabla + un
 * whereIn para los usuarios.
 *
 * @return list<array<string, mixed>>
 */
private function construirActividad(int $id): array
{
    $db = Database::connect();

    $avances = $db->table('avances')
        ->where('acuerdo_id', $id)->get()->getResultArray();
    $auditoria = $db->table('auditoria')
        ->where('entidad', 'acuerdo')->where('entidad_id', $id)
        ->whereIn('accion', ['crear', 'editar', 'corresponsables'])
        ->get()->getResultArray();

    // Hidratar usuarios de ambas fuentes en un solo whereIn.
    $ids = [];
    foreach ($avances as $f) {
        $ids[] = (int) $f['usuario_id'];
    }
    foreach ($auditoria as $f) {
        if ($f['usuario_id'] !== null) {
            $ids[] = (int) $f['usuario_id'];
        }
    }
    $porId = [];
    if ($ids !== []) {
        foreach ($db->table('usuarios')->whereIn('id', array_values(array_unique($ids)))->get()->getResultArray() as $u) {
            $porId[(int) $u['id']] = UsuarioRef::desdeFila($u)->aArray();
        }
    }

    $eventos = [];
    foreach ($avances as $f) {
        $eventos[] = [
            'id'          => 'avance:' . (int) $f['id'],
            'fuente'      => 'avance',
            'tipo'        => (string) $f['tipo'],
            'usuario'     => $porId[(int) $f['usuario_id']] ?? null,
            'descripcion' => (string) $f['descripcion'],
            'nueva_fecha' => $f['nueva_fecha'],
            'created_at'  => (string) $f['created_at'],
        ];
    }
    foreach ($auditoria as $f) {
        $uid = $f['usuario_id'] !== null ? (int) $f['usuario_id'] : null;
        $eventos[] = [
            'id'          => 'auditoria:' . (int) $f['id'],
            'fuente'      => 'auditoria',
            'tipo'        => (string) $f['accion'],
            'usuario'     => $uid !== null ? ($porId[$uid] ?? null) : null,
            'descripcion' => $this->descripcionAuditoria((string) $f['accion'], $f['detalle']),
            'nueva_fecha' => null,
            'created_at'  => (string) $f['created_at'],
        ];
    }

    // Orden desc por fecha; desempate estable por id textual.
    usort($eventos, static function (array $a, array $b): int {
        return [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']];
    });

    return $eventos;
}
```

Helper de descripción legible para la auditoría:

```php
/** Texto legible para un evento de auditoría de ciclo de vida. */
private function descripcionAuditoria(string $accion, ?string $detalleJson): string
{
    if ($accion === 'crear') {
        return 'Creó el acuerdo';
    }
    if ($accion === 'corresponsables') {
        return 'Actualizó los corresponsables';
    }
    // 'editar' → "Actualizó: tema, responsable"
    $etiquetas = [
        'tema'              => 'tema',
        'accion'            => 'acción',
        'responsable_id'    => 'responsable',
        'area_id'           => 'área',
        'fecha_compromiso'  => 'fecha compromiso',
        'enlace'            => 'enlaces',
        'enlaces'           => 'enlaces',
        'observaciones'     => 'observaciones',
        'recordatorio_dias' => 'recordatorios',
    ];
    $detalle = $detalleJson !== null ? json_decode($detalleJson, true) : null;
    $campos  = is_array($detalle) && isset($detalle['cambios']) && is_array($detalle['cambios'])
        ? $detalle['cambios'] : [];
    $legibles = [];
    foreach ($campos as $c) {
        $legibles[$etiquetas[$c] ?? (string) $c] = true; // dedup (enlace/enlaces → "enlaces")
    }

    return $legibles === [] ? 'Editó el acuerdo' : 'Actualizó: ' . implode(', ', array_keys($legibles));
}
```

- [ ] **Step 5: Correr el test para verificar que pasa**

Run: `cd apps/api && vendor/bin/phpunit --filter ActividadAcuerdoTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Verificar no-N+1 y suite completa de acuerdos**

Run: `cd apps/api && vendor/bin/phpunit --filter AcuerdosLecturaTest && vendor/bin/phpunit --filter ActividadAcuerdoTest`
Expected: PASS. Revisar visualmente `construirActividad` — exactamente 3 queries (avances, auditoria, usuarios).

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Config/Routes.php apps/api/app/Controllers/AcuerdosController.php apps/api/tests/feature/ActividadAcuerdoTest.php
git commit -m "feat(api): endpoint GET /acuerdos/{id}/actividad (bitácora unificada)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Contrato front — tipo, método de cliente y doc 05

**Files:**
- Modify: `apps/web/src/lib/types.ts` (nuevo `EventoActividad`)
- Modify: `apps/web/src/lib/api.ts` (método en interfaz `ApiClient`)
- Modify: `apps/web/src/lib/api.real.ts` (implementación)
- Modify: `docs/05-api/05_especificacion_api.md` (documentar endpoint + tipo)

**Interfaces:**
- Consumes: el endpoint `GET /acuerdos/{id}/actividad` de la Task 1 (respuesta `{ data: EventoActividad[] }`), y `UsuarioRef` ya existente en `types.ts`.
- Produces: `api.actividadAcuerdo(id: number): Promise<EventoActividad[]>` y el tipo `EventoActividad` que consume la Task 3.

- [ ] **Step 1: Añadir el tipo `EventoActividad`**

En `apps/web/src/lib/types.ts`, junto a `interface Avance` (~línea 206), añadir:

```ts
export type TipoEventoActividad =
  | 'avance' | 'reprogramacion' | 'validacion' | 'reapertura'
  | 'crear' | 'editar' | 'corresponsables';

export interface EventoActividad {
  id: string;                    // "avance:12" | "auditoria:45" — key único cross-tabla
  fuente: 'avance' | 'auditoria';
  tipo: TipoEventoActividad;
  usuario: UsuarioRef | null;    // null = acción del sistema
  descripcion: string;
  nueva_fecha: string | null;    // solo reprogramación
  created_at: string;
}
```

- [ ] **Step 2: Declarar el método en la interfaz `ApiClient`**

En `apps/web/src/lib/api.ts`, en el bloque `// acuerdos`, después de `registrarAvance(...)`:

```ts
  actividadAcuerdo(id: number): Promise<EventoActividad[]>; // bitácora unificada (avances + auditoría de ciclo de vida)
```

Añadir `EventoActividad` a los imports de tipos al inicio de `api.ts`.

- [ ] **Step 3: Implementar en `api.real.ts`**

En `apps/web/src/lib/api.real.ts`, añadir `EventoActividad` al import de `./types` y, junto a `registrarAvance` (~línea 70), la implementación:

```ts
  actividadAcuerdo: async (id) =>
    (await req<{ data: EventoActividad[] }>('GET', `/acuerdos/${id}/actividad`)).data,
```

- [ ] **Step 4: Verificar typecheck y lint**

Run: `cd apps/web && npm run typecheck && npm run lint`
Expected: sin errores (el método nuevo satisface la interfaz; ningún consumidor roto todavía).

- [ ] **Step 5: Documentar en el doc 05 (regla #3)**

En `docs/05-api/05_especificacion_api.md`, en la sección de endpoints de acuerdos, documentar `GET /acuerdos/{id}/actividad`: propósito (bitácora unificada), autorización (lectura abierta ADR-007; `pendiente` → 403), respuesta `{ data: EventoActividad[] }` con la forma del tipo, la regla de no-duplicado (concluir/reabrir excluidos de auditoría porque llegan como avance) y orden desc por `created_at`. Incluir la definición TypeScript de `EventoActividad` idéntica a `types.ts`.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/lib/types.ts apps/web/src/lib/api.ts apps/web/src/lib/api.real.ts docs/05-api/05_especificacion_api.md
git commit -m "feat(web): contrato actividadAcuerdo + tipo EventoActividad (doc 05)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Front — el Drawer consume la actividad unificada

**Files:**
- Modify: `apps/web/src/components/Drawer.tsx` (sección Bitácora)

**Interfaces:**
- Consumes: `api.actividadAcuerdo(id)` y el tipo `EventoActividad`/`TipoEventoActividad` de la Task 2.
- Produces: UI final; no expone símbolos a otras tareas.

- [ ] **Step 1: Cargar la actividad con TanStack Query**

En `apps/web/src/components/Drawer.tsx`, añadir una query (junto a las demás `useQuery` del componente). Reemplaza el uso de `sel.avances` como fuente de la bitácora:

```ts
  const actividadQ = useQuery({
    queryKey: ['actividad', id],
    queryFn: () => api.actividadAcuerdo(id),
    enabled: id > 0,
  });
```

Importar `EventoActividad`/`TipoEventoActividad` desde `../lib` si hiciera falta el tipo explícito.

- [ ] **Step 2: Extender el mapa de metadatos a los 7 tipos**

Reemplazar `TIPO_AVANCE_META` (los 4 tipos actuales) por `TIPO_EVENTO_META` con los 3 tipos administrativos en color neutro:

```ts
// Etiqueta y acento de color por tipo de evento de la bitácora. Progreso con
// tokens de estado PJ (regla #11): teal = avance/validación, ámbar = reprogramación,
// rojo = reapertura. Eventos administrativos (crear/editar/corresponsables) en
// color neutro para distinguirlos del progreso.
const TIPO_EVENTO_META: Record<TipoEventoActividad, { label: string; color: string }> = {
  avance:          { label: 'Avance',           color: 'var(--teal)' },
  reprogramacion:  { label: 'Reprogramación',   color: 'var(--amber)' },
  validacion:      { label: 'Validación',       color: 'var(--teal)' },
  reapertura:      { label: 'Reapertura',       color: 'var(--red)' },
  crear:           { label: 'Creación',         color: 'var(--text-muted)' },
  editar:          { label: 'Edición',          color: 'var(--text-muted)' },
  corresponsables: { label: 'Corresponsables',  color: 'var(--text-muted)' },
};
```

- [ ] **Step 3: Renderizar desde la query (carga, vacío, lista)**

Reemplazar el bloque actual de la sección Bitácora para que use `actividadQ` en vez de `sel.avances`. La lista usa `bitacora` (el `useMemo` defensivo reordena desc sin mutar), la etiqueta/color salen de `TIPO_EVENTO_META[ev.tipo]`, y el nombre del actor cae a "Sistema" cuando `ev.usuario` es null:

```tsx
  const bitacora = useMemo(
    () => [...(actividadQ.data ?? [])].sort((a, b) => b.created_at.localeCompare(a.created_at)),
    [actividadQ.data],
  );
```

```tsx
            <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
              <div className="detail-label" style={{ marginBottom: 10 }}>Bitácora</div>
              {actividadQ.isLoading && (
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Cargando actividad…</div>
              )}
              {!actividadQ.isLoading && bitacora.length === 0 && (
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Aún no hay actividad registrada.</div>
              )}
              {bitacora.map((ev) => {
                const meta = TIPO_EVENTO_META[ev.tipo];
                return (
                  <div
                    key={ev.id}
                    style={{
                      padding: '10px 0 10px 14px',
                      borderTop: '1px solid var(--border-subtle)',
                      borderLeft: `3px solid ${meta.color}`,
                      marginLeft: 2,
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                      <span aria-hidden style={{ width: 7, height: 7, borderRadius: '50%', background: meta.color, flexShrink: 0 }} />
                      <span style={{ fontFamily: 'var(--font-display)', fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.08em', color: meta.color }}>
                        {meta.label}
                      </span>
                      <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                        {(ev.usuario?.nombre ?? 'Sistema')} · {fmtF(ev.created_at.slice(0, 10))}
                      </span>
                    </div>
                    <div style={{ fontSize: 13, lineHeight: 1.5, color: 'var(--text-secondary)' }}>{ev.descripcion}</div>
                    {ev.nueva_fecha && (
                      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-brand)', marginTop: 3 }}>
                        Nueva fecha compromiso: {fmtL(ev.nueva_fecha)}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
```

- [ ] **Step 4: Invalidar la query de actividad tras mutaciones**

Las mutaciones que ya invalidan el detalle (registrar avance, reprogramar, concluir, reabrir, corresponsables, editar) deben además invalidar `['actividad', id]` para que la bitácora se refresque. En cada `onSuccess` que hoy hace `queryClient.invalidateQueries` del detalle, añadir:

```ts
        queryClient.invalidateQueries({ queryKey: ['actividad', id] });
```

- [ ] **Step 5: Eliminar el símbolo muerto**

Quitar la constante anterior `TIPO_AVANCE_META` (reemplazada por `TIPO_EVENTO_META`) y cualquier import ahora sin uso. Confirmar que `sel.avances` ya no se usa para la bitácora (puede seguir usándose en otras partes del detalle si aplica; no tocar esos usos).

- [ ] **Step 6: Verificar typecheck, lint, build y tests**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build && npm test`
Expected: todo en verde.

- [ ] **Step 7: Verificación end-to-end (skill `verify`)**

Con los servicios corriendo (API :8089, Vite :5173), abrir un acuerdo en el Drawer y confirmar: la bitácora muestra la **creación** + **ediciones** + **avances** en orden cronológico desc; una conclusión aparece **una sola vez** (validación, no duplicada); los eventos administrativos salen en color neutro. Registrar un avance y ver que la bitácora se refresca sin recargar.

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/components/Drawer.tsx
git commit -m "feat(web): la Bitácora del Drawer muestra la actividad unificada

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Alcance (crear/editar/corresponsables; excluir concluir/reabrir/eliminar/intento_*) → Task 1 Step 4 (`whereIn`) + test `testConclusionApareceUnaSolaVez`. ✅
- Contrato/tipos (`EventoActividad`, `actividadAcuerdo`) → Task 2. ✅
- Endpoint + Policy de lectura + descripción legible + cero N+1 → Task 1. ✅
- Front Drawer (query, colores neutros, carga/vacío) → Task 3. ✅
- Doc 05 (regla #3) → Task 2 Step 5. ✅
- DoD/verificación (backend tests, front build, e2e) → Task 1 Steps 5–6, Task 3 Steps 6–7. ✅

**Placeholder scan:** sin TBD/TODO; todos los steps de código llevan el bloque real.

**Type consistency:** `EventoActividad` (Task 2) = forma del JSON de `construirActividad` (Task 1) = uso en Drawer (Task 3). `TipoEventoActividad` cubre los 7 tipos usados en `TIPO_EVENTO_META`. `actividadAcuerdo(id: number): Promise<EventoActividad[]>` idéntico en interfaz (Task 2 Step 2) e implementación (Task 2 Step 3) y consumo (Task 3 Step 1).
