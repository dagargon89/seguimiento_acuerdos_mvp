<?php

namespace Tests\Database;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Verifica que InitialSeeder puebla la BD desde db.json sin transformación y que las
 * salvaguardas del DDL (CHECK de conclusión, UNIQUE de idempotencia) están vivas.
 *
 * @group database
 *
 * @internal
 */
final class InitialSeederTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    /** @var array<string, mixed> */
    private array $json;

    protected function setUp(): void
    {
        parent::setUp();
        $ruta       = realpath(ROOTPATH . '../web/src/lib/mock/db.json');
        $this->json = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @dataProvider tablas
     */
    public function testConteoPorTablaCoincideConDbJson(string $tabla): void
    {
        $esperado = count($this->json[$tabla] ?? []);
        $real     = $this->db->table($tabla)->countAllResults();
        $this->assertSame($esperado, $real, "El conteo de '{$tabla}' no coincide con db.json.");
    }

    public static function tablas(): array
    {
        return array_map(static fn (string $t): array => [$t], [
            'areas', 'usuarios', 'reuniones', 'acuerdos', 'acuerdo_corresponsables',
            'avances', 'configuracion', 'recordatorios_enviados', 'google_sync', 'auditoria',
        ]);
    }

    public function testAcuerdoConOverrideYCorresponsablesFielAlJson(): void
    {
        // Acuerdo id=4: tiene recordatorio_dias override y corresponsables en db.json.
        $acuerdo = $this->db->table('acuerdos')->getWhere(['id' => 4])->getRowArray();
        $jsonAc  = $this->fila('acuerdos', 4);

        $this->assertSame($jsonAc['recordatorio_dias'], json_decode((string) $acuerdo['recordatorio_dias'], true));

        $corresp = $this->db->table('acuerdo_corresponsables')
            ->select('usuario_id')->where('acuerdo_id', 4)->get()->getResultArray();
        $idsBd   = array_map(static fn ($r): int => (int) $r['usuario_id'], $corresp);
        $idsJson = array_values(array_map(
            static fn ($r): int => (int) $r['usuario_id'],
            array_filter($this->json['acuerdo_corresponsables'], static fn ($r): bool => $r['acuerdo_id'] === 4),
        ));
        sort($idsBd);
        sort($idsJson);
        $this->assertSame($idsJson, $idsBd);
    }

    public function testConfiguracionValorDeserializaAlObjetoDelJson(): void
    {
        $valor = $this->db->table('configuracion')->getWhere(['clave' => 'recordatorios_default'])->getRowArray();
        // MySQL reordena las claves de un objeto JSON al almacenarlo; assertEquals
        // compara arrays asociativos por clave (ignora el orden), que es justo lo que
        // queremos. No usar assertEqualsCanonicalizing: ordena también los VALORES, y
        // con tipos mezclados (array/bool/int/string) el sort de PHP no es un orden
        // total, provocando fallos en falso pese a tener el mismo contenido.
        $this->assertEquals(
            $this->fila('configuracion', null, 'recordatorios_default')['valor'],
            json_decode((string) $valor['valor'], true),
        );
    }

    public function testCheckDeConclusionRechazaConcluidoSinAutor(): void
    {
        $this->expectException(DatabaseException::class);
        // estado='concluido' sin concluido_por_id/at viola el CHECK del DDL.
        $this->db->table('acuerdos')->insert([
            'reunion_id' => 1, 'area_id' => 1, 'accion' => 'x', 'responsable_id' => 4,
            'capturado_por_id' => 1, 'fecha_compromiso' => '2026-08-01', 'estado' => 'concluido',
        ]);
    }

    public function testUniqueDeRecordatoriosImpideDuplicados(): void
    {
        $this->expectException(DatabaseException::class);
        $tupla = ['acuerdo_id' => 1, 'usuario_id' => 4, 'tipo' => 'previo', 'programado_para' => '2099-01-01'];
        $this->db->table('recordatorios_enviados')->insert($tupla + ['estado' => 'enviado']);
        $this->db->table('recordatorios_enviados')->insert($tupla + ['estado' => 'enviado']);
    }

    private function fila(string $tabla, ?int $id, ?string $clave = null): array
    {
        foreach ($this->json[$tabla] as $fila) {
            if ($id !== null && ($fila['id'] ?? null) === $id) {
                return $fila;
            }
            if ($clave !== null && ($fila['clave'] ?? null) === $clave) {
                return $fila;
            }
        }

        return [];
    }
}
