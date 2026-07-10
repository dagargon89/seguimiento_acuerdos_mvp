<?php

namespace App\Libraries\Auth;

use CodeIgniter\Cache\CacheInterface;

/**
 * Cache de 60 s (RF-01: desactivación efectiva ≤60 s) del usuario local resuelto
 * por FirebaseAuthFilter. Respaldado por el handler de cache configurado (Redis
 * en dev/producción vía app/Config/Cache.php).
 *
 * Se indexa por `firebase_uid` (clave real usada por el Filter en cada request)
 * y se mantiene un índice inverso `usuario_id -> uid` para poder invalidar por
 * id de usuario (caso de uso: PATCH /usuarios/{id} en una tarea posterior,
 * antes de conocer el uid en memoria).
 *
 * Nota de nomenclatura: el plan de seguridad describe la clave conceptual como
 * `usr:{uid}`; el separador real es `.` (no `:`) porque `:` está en
 * Config\Cache::$reservedCharacters (exigido por CI4 para compatibilidad
 * PSR-6/PSR-16) y el handler lo rechaza con InvalidArgumentException.
 */
final class AuthCache
{
    public const TTL_SEGUNDOS = 60;

    private const PREFIJO_UID = 'usr.';

    private const PREFIJO_ID = 'usr_id.';

    /** @param array<string, mixed> $usuario Fila de la tabla `usuarios`. */
    public static function guardar(string $uid, array $usuario): void
    {
        $cache = static::cache();
        $cache->save(self::PREFIJO_UID . $uid, $usuario, self::TTL_SEGUNDOS);
        $cache->save(self::PREFIJO_ID . (int) $usuario['id'], $uid, self::TTL_SEGUNDOS);
    }

    /** @return array<string, mixed>|null */
    public static function obtenerPorUid(string $uid): ?array
    {
        $valor = static::cache()->get(self::PREFIJO_UID . $uid);

        return is_array($valor) ? $valor : null;
    }

    /**
     * Invalida el cache del usuario. Acepta el id local (int) o el firebase_uid
     * (string) — lo que se tenga a mano en el llamador.
     */
    public static function invalidar(int|string $usuarioIdOUid): void
    {
        $cache = static::cache();

        if (is_int($usuarioIdOUid)) {
            $uid = $cache->get(self::PREFIJO_ID . $usuarioIdOUid);
            $cache->delete(self::PREFIJO_ID . $usuarioIdOUid);
            if (is_string($uid) && $uid !== '') {
                $cache->delete(self::PREFIJO_UID . $uid);
            }

            return;
        }

        $cache->delete(self::PREFIJO_UID . $usuarioIdOUid);
    }

    private static function cache(): CacheInterface
    {
        return service('cache');
    }
}
