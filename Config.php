<?php

namespace ConectaEnte;

use MapasCulturais\App;

class Config
{
    const MODE_DEV  = 'dev';
    const MODE_LIVE = 'live';

    public static function mode()
    {
        $mode = strtolower(trim((string) self::get('mode', self::MODE_DEV)));

        return $mode === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_DEV;
    }

    public static function isLive()
    {
        return self::mode() === self::MODE_LIVE;
    }

    public static function host()
    {
        return rtrim((string) self::get('host', ''), '/');
    }

    // inclui a primeira tentativa: 3 significa uma tentativa e até duas retentativas
    public static function maxAttempts()
    {
        return max(1, (int) self::get('max_attempts', 3));
    }

    public static function retryDelay()
    {
        return (string) self::get('retry_delay', '+15 seconds');
    }

    // validade, em segundos, da árvore do PAR no cache por ente (CONECTAENTE_PAR_TTL); mínimo de um minuto
    public static function parTtl()
    {
        return max(60, (int) self::get('par_ttl', 3600));
    }

    public static function get($key, $default = null)
    {
        $app = App::i();

        $config_key = 'conectaente.' . $key;
        if (isset($app->config[$config_key])) {
            return $app->config[$config_key];
        }

        $env = getenv('CONECTAENTE_' . strtoupper($key));
        if ($env !== false && $env !== '') {
            return $env;
        }

        return $default;
    }
}
