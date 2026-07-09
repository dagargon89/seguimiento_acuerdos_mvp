<?php

namespace App\Libraries\Correo;

use Google\Client as GoogleClient;
use Google\Service\Gmail as GoogleGmail;
use Google\Service\Gmail\Message as GmailMessage;
use RuntimeException;

/**
 * Implementación real de `Mailer` con Gmail API (S2.2, ADR-003). Envía desde
 * la cuenta central (`GOOGLE_IMPERSONATED_USER`) vía service account con
 * domain-wide delegation, scope `gmail.send` únicamente.
 *
 * `Config\Services::mailer()` solo instancia esta clase cuando
 * `GOOGLE_APPLICATION_CREDENTIALS` está configurado; de lo contrario usa
 * `NoopMailer`. El constructor asume que la ruta de credenciales es válida —
 * si `setAuthConfig()` falla (archivo inexistente/JSON inválido), la
 * excepción de la librería de Google se propaga tal cual.
 *
 * Un fallo de red/API en `enviar()` (excepción de Google) se propaga sin
 * capturar: es responsabilidad de quien llama (`RecordatorioService`)
 * capturarla y marcar el envío como `fallido` sin abortar el job (RE-07).
 */
final class GmailService implements Mailer
{
    private GoogleGmail $gmailService;
    private readonly string $deImpersonado;

    /**
     * @param GoogleClient|null $client Inyectable para pruebas; en producción
     *                                  `Config\Services::mailer()` no lo pasa y este
     *                                  constructor arma el cliente real con las
     *                                  credenciales de `.env` (ADR-003).
     */
    public function __construct(?GoogleClient $client = null)
    {
        $this->deImpersonado = (string) env('GOOGLE_IMPERSONATED_USER');

        if ($client === null) {
            $client = new GoogleClient();
            $client->setAuthConfig((string) env('GOOGLE_APPLICATION_CREDENTIALS'));
            $client->setSubject($this->deImpersonado);
            $client->addScope(GoogleGmail::GMAIL_SEND);
        }

        $this->gmailService = new GoogleGmail($client);
    }

    public function enviar(string $para, string $asunto, string $html): string
    {
        $raw = $this->construirRaw($this->deImpersonado, $para, $asunto, $html);

        $mensaje = new GmailMessage();
        $mensaje->setRaw($raw);

        $enviado = $this->gmailService->users_messages->send('me', $mensaje);

        $id = $enviado->getId();
        if ($id === null || $id === '') {
            // No debería ocurrir con una respuesta exitosa de la API, pero si
            // pasa no queremos devolver un id vacío que se registre como éxito.
            throw new RuntimeException('GmailService: la API no devolvió un id de mensaje.');
        }

        return $id;
    }

    /**
     * Construye el mensaje MIME RFC 2822 completo (headers + cuerpo HTML) y lo
     * codifica en base64url (lo que exige `Message.raw` de la API de Gmail:
     * base64 estándar con `+`→`-`, `/`→`_`, sin padding `=`).
     *
     * Método PURO: sin red, sin estado, sin llamar a la API — es el seam de
     * testeo. Los tests lo invocan directamente para verificar la forma del
     * MIME sin credenciales ni conexión.
     *
     * Defensa en profundidad: `$de`/`$para` van crudos a los headers `From:`/
     * `To:`. Hoy no es explotable (el email se valida al alta del usuario /
     * viene de `.env`), pero si alguna vez llegara un valor con `\r` o `\n`
     * (CRLF header injection) se podrían inyectar headers/cuerpo arbitrarios.
     * Se rechaza explícitamente en vez de sanear en silencio.
     */
    public function construirRaw(string $de, string $para, string $asunto, string $html): string
    {
        $this->rechazarCrlf($de, 'de');
        $this->rechazarCrlf($para, 'para');

        $asuntoCodificado = '=?UTF-8?B?' . base64_encode($asunto) . '?=';

        $headers = [
            'From: ' . $de,
            'To: ' . $para,
            'Subject: ' . $asuntoCodificado,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $mime = implode("\r\n", $headers) . "\r\n\r\n" . $html;

        return $this->base64UrlEncode($mime);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Lanza si `$valor` contiene CR o LF (CRLF header injection). */
    private function rechazarCrlf(string $valor, string $campo): void
    {
        if (str_contains($valor, "\r") || str_contains($valor, "\n")) {
            throw new RuntimeException("GmailService: el valor de '{$campo}' contiene caracteres de control (\\r/\\n) no permitidos en un header de correo.");
        }
    }
}
