<?php

/**
 * Um tenant novo nasce com os mesmos dados iniciais de uma instalação nova
 * (banco.sql, gravado pelo instalador). Se alguém mudar as configurações padrão
 * ou as permissões do Administrador em banco.sql, este teste lembra de levar a
 * mudança para application/config/tenant_dados_iniciais.php.
 */
final class TenantDadosIniciaisTest extends MaposTestCase
{
    private function dados(): array
    {
        $config = [];
        require APPPATH . 'config/tenant_dados_iniciais.php';

        return $config['tenant_dados_iniciais'];
    }

    private function bancoSql(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(MAPOS_ROOT . '/banco.sql'));
    }

    public function testConfiguracoesSaoAsDoBancoSql(): void
    {
        preg_match('/INSERT IGNORE INTO `configuracoes`.*?VALUES\s*(.*?);\s*\n/s', $this->bancoSql(), $bloco);
        preg_match_all("/\\(\\d+, '([a-z0-9_]+)', '((?:[^'\\\\]|\\\\.)*)'\\)/", $bloco[1], $linhas, PREG_SET_ORDER);

        $esperado = [];
        foreach ($linhas as $linha) {
            $esperado[$linha[1]] = stripcslashes($linha[2]);
        }

        $this->assertNotEmpty($esperado);
        $this->assertSame($esperado, $this->dados()['configuracoes']);
    }

    public function testPermissoesDoAdministradorSaoAsDoBancoSql(): void
    {
        preg_match("/INSERT IGNORE INTO `permissoes`.*?VALUES\\s*\\(1, 'Administrador', '(.*?)', 1/s", $this->bancoSql(), $bloco);

        $this->assertSame(unserialize($bloco[1]), $this->dados()['permissoes_admin']);
    }

    public function testAdministradorTemTodasAsPermissoesLigadas(): void
    {
        $permissoes = $this->dados()['permissoes_admin'];

        // As de Pagamento são null em banco.sql: o módulo saiu em
        // 20210114151943_drop_table_pagamento. Todas as outras ficam ligadas.
        $this->assertNotEmpty($permissoes);
        $this->assertSame([], array_filter($permissoes, fn ($valor) => $valor !== '1' && $valor !== null));
        $this->assertSame(['aPagamento', 'ePagamento', 'dPagamento', 'vPagamento'], array_keys(array_filter($permissoes, 'is_null')));
    }
}
