<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Log do CodeIgniter com a empresa em cada linha (multi-tenancy): todas as
 * empresas escrevem em application/logs, e sem isso não dá para saber de qual
 * é um erro, nem separar o que é de uma empresa num pedido de dados (LGPD).
 *
 *     ERROR - 2026-11-07 19:13:11 --> [mapos_loja1_a1b2c3] Query error: ...
 *     ERROR - 2026-11-07 19:13:11 --> [plataforma] ...
 *
 * Na instalação única a linha fica como sempre foi.
 */
class MY_Log extends CI_Log
{
    protected function _format_line($level, $date, $message)
    {
        return parent::_format_line($level, $date, self::prefixo() . $message);
    }

    /** "[schema] ", "[plataforma] " ou "" (instalação única). Nunca lança: é o log. */
    public static function prefixo(): string
    {
        $schema = $GLOBALS['mapos_tenant_schema'] ?? null;
        if (is_string($schema) && preg_match('/^[a-z0-9_]{1,64}$/', $schema)) {
            return '[' . $schema . '] ';
        }

        return ($GLOBALS['mapos_contexto'] ?? '') === 'plataforma' ? '[plataforma] ' : '';
    }
}
