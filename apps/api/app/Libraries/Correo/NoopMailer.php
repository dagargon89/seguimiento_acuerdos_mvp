<?php

namespace App\Libraries\Correo;

use CodeIgniter\I18n\Time;

/**
 * Doble sin efectos de red: NO envía nada. Es el binding por defecto de
 * `Config\Services::mailer()` mientras la integración real (`GmailMailer`,
 * S2.2) no exista o no haya credenciales configuradas.
 *
 * Devuelve un id sintético para que el job registre el envío como `enviado`;
 * así `spark recordatorios:procesar` corre de punta a punta en desarrollo sin
 * tocar Gmail. Se documenta explícitamente que en este modo el correo real NO
 * se entrega.
 */
final class NoopMailer implements Mailer
{
    public function enviar(string $para, string $asunto, string $html): string
    {
        log_message('info', 'NoopMailer: correo NO enviado (sin credenciales Gmail) para {para} asunto "{asunto}"', [
            'para'   => $para,
            'asunto' => $asunto,
        ]);

        return 'noop-' . Time::now()->getTimestamp() . '-' . substr(md5($para . '|' . $asunto), 0, 12);
    }
}
