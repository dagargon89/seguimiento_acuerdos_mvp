<?php

namespace Tests\Unit;

use App\Libraries\Correo\PlantillaCorreo;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Plantillas de correo (S2.2), 1:1 con `EmailModal.tsx` del demo. Sin red, sin
 * BD: `PlantillaCorreo` es una función pura sobre arrays de acuerdo/usuario.
 *
 * Cubre: asunto exacto por tipo (previo/dia/vencido/resumen), presencia de
 * los campos del acuerdo en el HTML, link al panel, y — crítico — que
 * contenido de usuario con HTML/JS embebido llegue siempre escapado
 * (OWASP A03).
 *
 * @internal
 */
final class PlantillaCorreoTest extends CIUnitTestCase
{
    private PlantillaCorreo $plantilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plantilla = new PlantillaCorreo();
    }

    /** @return array<string, mixed> */
    private function acuerdoBase(array $overrides = []): array
    {
        return array_merge([
            'tema'                => 'Presupuesto 2026',
            'accion'              => 'Enviar propuesta de presupuesto',
            'fecha_compromiso'    => '2026-07-20',
            'estado'              => 'en_proceso',
            'responsable_nombre'  => 'Rosa Martínez',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function usuarioBase(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Rita Gómez',
            'email'  => 'rita@demo.test',
            'rol'    => 'responsable',
        ], $overrides);
    }

    public function testAsuntoPrevioCoincideConElDemo(): void
    {
        $acuerdo = $this->acuerdoBase();
        $correo  = $this->plantilla->recordatorio('previo', $acuerdo, $this->usuarioBase());

        $this->assertSame('Recordatorio: Enviar propuesta de presupuesto', $correo['asunto']);
        $this->assertStringContainsString('vence el', $correo['html']);
        $this->assertStringContainsString('20 de julio de 2026', $correo['html']);
    }

    public function testAsuntoDiaCoincideConElDemo(): void
    {
        $acuerdo = $this->acuerdoBase();
        $correo  = $this->plantilla->recordatorio('dia', $acuerdo, $this->usuarioBase());

        $this->assertSame('Vence hoy: Enviar propuesta de presupuesto', $correo['asunto']);
        $this->assertStringContainsString('Hoy es la fecha compromiso', $correo['html']);
    }

    public function testAsuntoVencidoUsaTemaCoincideConElDemo(): void
    {
        $acuerdo = $this->acuerdoBase();
        $correo  = $this->plantilla->recordatorio('vencido', $acuerdo, $this->usuarioBase());

        $this->assertSame('Seguimiento: acuerdo vencido — Presupuesto 2026', $correo['asunto']);
        $this->assertStringContainsString('venció y sigue abierto', $correo['html']);
    }

    public function testAsuntoVencidoSinTemaCaeEnAccion(): void
    {
        $acuerdo = $this->acuerdoBase(['tema' => '']);
        $correo  = $this->plantilla->recordatorio('vencido', $acuerdo, $this->usuarioBase());

        $this->assertSame('Seguimiento: acuerdo vencido — Enviar propuesta de presupuesto', $correo['asunto']);
    }

    public function testAsuntoResumenCoincideConElDemo(): void
    {
        $correo = $this->plantilla->resumen($this->usuarioBase(['rol' => 'direccion']), [
            $this->acuerdoBase(),
            $this->acuerdoBase(['accion' => 'Firmar convenio', 'fecha_compromiso' => '2026-07-10']),
        ]);

        $this->assertSame('Resumen periódico: acuerdos abiertos', $correo['asunto']);
        $this->assertStringContainsString('ordenados por fecha compromiso', $correo['html']);
        // Ordenado por fecha_compromiso: el de 07-10 debe aparecer ANTES que el de 07-20.
        $posFirmar     = strpos($correo['html'], 'Firmar convenio');
        $posPropuesta  = strpos($correo['html'], 'Enviar propuesta de presupuesto');
        $this->assertNotFalse($posFirmar);
        $this->assertNotFalse($posPropuesta);
        $this->assertLessThan($posPropuesta, $posFirmar, 'el acuerdo con fecha más próxima debe listarse primero');
    }

    public function testHtmlDeRecordatorioContieneLosCamposDelAcuerdo(): void
    {
        $acuerdo = $this->acuerdoBase();
        $correo  = $this->plantilla->recordatorio('previo', $acuerdo, $this->usuarioBase());

        $this->assertStringContainsString('Presupuesto 2026', $correo['html']); // tema
        $this->assertStringContainsString('Enviar propuesta de presupuesto', $correo['html']); // accion
        $this->assertStringContainsString('Rosa Martínez', $correo['html']); // responsable
        $this->assertStringContainsString('20 de julio de 2026', $correo['html']); // fecha
        $this->assertStringContainsString('En proceso', $correo['html']); // estado
    }

    public function testHtmlIncluyeEnlaceAlPanel(): void
    {
        $correo = $this->plantilla->recordatorio('dia', $this->acuerdoBase(), $this->usuarioBase());

        $this->assertMatchesRegularExpression('#href="https?://[^"]+"#', $correo['html']);
        $this->assertStringContainsString('Abrir panel de seguimiento', $correo['html']);
    }

    // ── Seguridad (OWASP A03): contenido de usuario SIEMPRE escapado ──────────

    public function testAccionConScriptEmbebidoSeEscapaEnRecordatorio(): void
    {
        $payload = '<script>alert(1)</script>';
        $acuerdo = $this->acuerdoBase(['accion' => $payload]);
        $correo  = $this->plantilla->recordatorio('previo', $acuerdo, $this->usuarioBase());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $correo['html']);
        $this->assertStringContainsString(esc($payload), $correo['html']);
        // El asunto (texto plano, no se inserta en HTML) conserva el string crudo;
        // lo que importa es que el HTML no contenga el tag vivo.
        $this->assertStringNotContainsString('<script>', $correo['html']);
    }

    public function testTemaConImgOnerrorSeEscapaEnVencido(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        $acuerdo = $this->acuerdoBase(['tema' => $payload]);
        $correo  = $this->plantilla->recordatorio('vencido', $acuerdo, $this->usuarioBase());

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $correo['html']);
        $this->assertStringContainsString(esc($payload), $correo['html']);
    }

    public function testNombreDeUsuarioConHtmlSeEscapaEnElSaludo(): void
    {
        $payload = '<script>alert(2)</script>';
        $usuario = $this->usuarioBase(['nombre' => $payload]);
        $correo  = $this->plantilla->recordatorio('dia', $this->acuerdoBase(), $usuario);

        $this->assertStringNotContainsString('<script>alert(2)</script>', $correo['html']);
    }

    public function testResponsableConHtmlSeEscapaEnLaFicha(): void
    {
        $payload = '<script>alert(3)</script>';
        $acuerdo = $this->acuerdoBase(['responsable_nombre' => $payload]);
        $correo  = $this->plantilla->recordatorio('previo', $acuerdo, $this->usuarioBase());

        $this->assertStringNotContainsString('<script>alert(3)</script>', $correo['html']);
    }

    public function testAccionConScriptEmbebidoSeEscapaEnListaDeResumen(): void
    {
        $payload = '<script>alert(4)</script>';
        $correo  = $this->plantilla->resumen(
            $this->usuarioBase(['rol' => 'direccion']),
            [$this->acuerdoBase(['accion' => $payload])],
        );

        $this->assertStringNotContainsString('<script>alert(4)</script>', $correo['html']);
        $this->assertStringContainsString(esc($payload), $correo['html']);
    }
}
