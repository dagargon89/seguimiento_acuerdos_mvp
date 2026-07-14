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
 * Filtro `mios=1` de GET /acuerdos (ADR-013, doc 05 v1.8): restringe el
 * listado a los acuerdos donde el actor es responsable directo O
 * corresponsable (vía la pivote `acuerdo_corresponsables`). Compone en AND
 * con la visibilidad ADR-007 y con el resto de los filtros (estado, q, …).
 * Solo el valor literal '1' lo activa; ausente/'0'/otro = listado normal.
 *
 * Participaciones del seed (fecha fija 2026-07-09):
 * - Rafael (id 5): responsable de 4 (en_proceso) y 10 (vencido); corresponsable de 3 (vencido).
 * - Carla (id 2, coordinadora): responsable de 8 (en_proceso) y 1 (concluido); sin corresponsabilidades.
 * - Dirección (id 1): sin acuerdos como responsable ni en la pivote.
 *
 * @group database
 *
 * @internal
 */
final class FiltroMiosTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use FechaFijaTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    /** firebase_uid sembrados en db.json — evita el flujo de "primer login" en los tests. */
    private const UID = [
        'direccion@demo.test'              => 'fb-demo-direccion-001',
        'coordinacion.operativa@demo.test' => 'fb-demo-coord-001',
        'responsable.dos@demo.test'        => 'fb-demo-resp-002',
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

    /** @return int[] ids del listado, ordenados ascendente */
    private function ids(array $cuerpo): array
    {
        $ids = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);
        sort($ids);

        return $ids;
    }

    // --- mios=1 restringe a responsabilidad + corresponsabilidad ------------

    public function testMiosResponsableVeSoloDondeParticipaIncluyendoCorresponsabilidad(): void
    {
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?mios=1&per_page=200');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        // Rafael: responsable de 4 y 10; corresponsable de 3 (vía pivote).
        $this->assertSame([3, 4, 10], $this->ids($cuerpo));
        $this->assertSame(3, $cuerpo['meta']['total']);

        // Acuerdos ajenos abiertos (5, 6, 7, 8, 9) fuera del listado.
        foreach ($cuerpo['data'] as $a) {
            $this->assertNotContains($a['id'], [5, 6, 7, 8, 9]);
        }
    }

    // --- mios compone con el filtro de estado -------------------------------

    public function testMiosComponeConEstado(): void
    {
        $vencidos = $this->cuerpo($this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?mios=1&estado=vencido&per_page=200'));
        $this->assertSame([3, 10], $this->ids($vencidos));

        $enProceso = $this->cuerpo($this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?mios=1&estado=en_proceso&per_page=200'));
        $this->assertSame([4], $this->ids($enProceso));
    }

    // --- coordinador: mios también filtra a los propios ----------------------

    public function testMiosCoordinadorTambienFiltraALosPropios(): void
    {
        // Carla es responsable de 8 (abierto) y 1 (concluido, oculto por default RF-03.3).
        $abiertos = $this->cuerpo($this->como('coordinacion.operativa@demo.test')->get('api/v1/acuerdos?mios=1&per_page=200'));
        $this->assertSame([8], $this->ids($abiertos));
        $this->assertSame(1, $abiertos['meta']['total']);

        $concluidos = $this->cuerpo($this->como('coordinacion.operativa@demo.test')->get('api/v1/acuerdos?mios=1&estado=concluido&per_page=200'));
        $this->assertSame([1], $this->ids($concluidos));
    }

    // --- dirección sin acuerdos propios: lista vacía -------------------------

    public function testMiosDireccionSinAcuerdosPropiosVeListaVacia(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos?mios=1&per_page=200');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame([], $cuerpo['data']);
        $this->assertSame(0, $cuerpo['meta']['total']);
    }

    // --- regresión AU-01: sin mios (o con mios!=1) el listado no cambia ------

    public function testSinMiosElListadoNoCambia(): void
    {
        // ADR-007: visibilidad abierta — Rafael sigue viendo los 8 abiertos del seed.
        $sinParam = $this->cuerpo($this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?per_page=200'));
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ids($sinParam));

        // Solo el literal '1' activa el filtro: mios=0 y mios=true se ignoran.
        $conCero = $this->cuerpo($this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?mios=0&per_page=200'));
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ids($conCero));

        $conTrue = $this->cuerpo($this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?mios=true&per_page=200'));
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ids($conTrue));
    }

    // --- mios compone con q (groupStart independientes, sin interferencia) ---

    public function testMiosComponeConBusquedaQ(): void
    {
        // "Tablero" matchea el tema del acuerdo 4 (Rafael responsable);
        // el grupo OR de q no debe abrir el listado a acuerdos ajenos.
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?mios=1&q=Tablero&per_page=200');

        $r->assertStatus(200);
        $this->assertSame([4], $this->ids($this->cuerpo($r)));
    }
}
