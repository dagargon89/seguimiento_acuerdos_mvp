<?php

namespace Tests\Unit;

use App\Libraries\Correo\GmailService;
use CodeIgniter\Test\CIUnitTestCase;
use Google\Client as GoogleClient;
use RuntimeException;

/**
 * `GmailService::construirRaw()` (S2.2): seam de testeo puro, sin red ni
 * credenciales. Verifica la forma base64url exigida por `Message.raw` de la
 * API de Gmail y que, al decodificar, el MIME resultante trae los headers y
 * el cuerpo esperados.
 *
 * Se instancia `GmailService` con un `Google\Client` inyectado (vacío, sin
 * `setAuthConfig`) para no disparar validación de credenciales reales — el
 * constructor solo lo usa para armar `Google\Service\Gmail`, que tampoco hace
 * red hasta que se invoca un endpoint. `construirRaw()` no toca ese servicio.
 *
 * @internal
 */
final class GmailServiceTest extends CIUnitTestCase
{
    private GmailService $gmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gmail = new GmailService(new GoogleClient());
    }

    private function decodificarBase64Url(string $raw): string
    {
        $b64 = strtr($raw, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($b64, true);
    }

    public function testRawEsBase64UrlValidoSinCaracteresProhibidos(): void
    {
        $raw = $this->gmail->construirRaw(
            'acuerdos@planjuarez.org',
            'destinatario@demo.test',
            'Recordatorio: Enviar propuesta',
            '<p>Hola</p>',
        );

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $raw);
        $this->assertStringNotContainsString('+', $raw);
        $this->assertStringNotContainsString('/', $raw);
        $this->assertStringNotContainsString('=', $raw);
    }

    public function testMimeDecodificadoContieneHeadersYCuerpoEsperados(): void
    {
        $html = '<p>Hola, Rita: tu acuerdo vence hoy.</p>';
        $raw  = $this->gmail->construirRaw(
            'acuerdos@planjuarez.org',
            'destinatario@demo.test',
            'Vence hoy: Enviar propuesta',
            $html,
        );

        $mime = $this->decodificarBase64Url($raw);

        $this->assertStringContainsString('To: destinatario@demo.test', $mime);
        $this->assertStringContainsString('From: acuerdos@planjuarez.org', $mime);
        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $mime);
        $this->assertStringContainsString($html, $mime);
        // El asunto va codificado en base64 UTF-8 (RFC 2047), no en texto plano.
        $this->assertStringContainsString('Subject: =?UTF-8?B?', $mime);
        $this->assertStringNotContainsString('Subject: Vence hoy: Enviar propuesta', $mime);
    }

    public function testAsuntoConAcentosSeDecodificaCorrectamenteDelHeaderRfc2047(): void
    {
        $asunto = 'Seguimiento: acuerdo vencido — Presupuesto 2026';
        $raw    = $this->gmail->construirRaw(
            'acuerdos@planjuarez.org',
            'destinatario@demo.test',
            $asunto,
            '<p>cuerpo</p>',
        );

        $mime = $this->decodificarBase64Url($raw);
        $this->assertMatchesRegularExpression('/Subject: =\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $mime);
        preg_match('/Subject: =\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $mime, $m);
        $this->assertSame($asunto, base64_decode($m[1]));
    }

    // ── Defensa en profundidad: rechazo de CRLF header injection (Hallazgo 4) ──

    public function testConstruirRawRechazaParaConCrlf(): void
    {
        $this->expectException(RuntimeException::class);

        $this->gmail->construirRaw(
            'acuerdos@planjuarez.org',
            "destinatario@demo.test\r\nBcc: atacante@evil.test",
            'Asunto normal',
            '<p>cuerpo</p>',
        );
    }

    public function testConstruirRawRechazaDeConCrlf(): void
    {
        $this->expectException(RuntimeException::class);

        $this->gmail->construirRaw(
            "acuerdos@planjuarez.org\nBcc: atacante@evil.test",
            'destinatario@demo.test',
            'Asunto normal',
            '<p>cuerpo</p>',
        );
    }

    public function testConstruirRawRechazaParaConSoloLf(): void
    {
        $this->expectException(RuntimeException::class);

        $this->gmail->construirRaw(
            'acuerdos@planjuarez.org',
            "destinatario@demo.test\nBcc: atacante@evil.test",
            'Asunto normal',
            '<p>cuerpo</p>',
        );
    }
}
