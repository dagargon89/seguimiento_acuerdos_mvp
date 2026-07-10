<?php

namespace Tests\Support;

use App\Libraries\Correo\Mailer;
use RuntimeException;

/**
 * Doble de Mailer para tests del job de recordatorios: registra cada envío en
 * memoria y opcionalmente falla para direcciones concretas (RE-07).
 *
 * Uso:
 *   $mailer = new FakeMailer();
 *   $mailer->fallaPara('responsable.uno@demo.test');   // ese destinatario lanza
 *   Services::injectMock('mailer', $mailer);
 *   ...
 *   $mailer->enviados;   // list<array{para,asunto,html}>
 */
final class FakeMailer implements Mailer
{
    /** @var list<array{para: string, asunto: string, html: string}> */
    public array $enviados = [];

    /** @var array<string, bool> emails que deben lanzar excepción */
    private array $fallan = [];

    private int $contador = 0;

    public function fallaPara(string $email): self
    {
        $this->fallan[$email] = true;

        return $this;
    }

    public function enviar(string $para, string $asunto, string $html): string
    {
        if (isset($this->fallan[$para])) {
            throw new RuntimeException('FakeMailer: fallo simulado para ' . $para);
        }

        $this->enviados[] = ['para' => $para, 'asunto' => $asunto, 'html' => $html];

        return 'fake-msg-' . (++$this->contador);
    }

    /** Cuenta envíos exitosos a un destinatario dado. */
    public function contarPara(string $email): int
    {
        return count(array_filter($this->enviados, static fn (array $e) => $e['para'] === $email));
    }
}
