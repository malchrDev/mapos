<?php

require_once BASEPATH . 'core/Log.php';
require_once APPPATH . 'core/MY_Log.php';

/**
 * Linha de log com a empresa (MY_Log): no SaaS todas as empresas escrevem no
 * mesmo arquivo.
 */
final class LogPorEmpresaTest extends MaposTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['mapos_tenant_schema'], $GLOBALS['mapos_contexto']);
        parent::tearDown();
    }

    private function linha(string $mensagem): string
    {
        $log = (new ReflectionClass(MY_Log::class))->newInstanceWithoutConstructor();
        $formatar = new ReflectionMethod(MY_Log::class, '_format_line');

        return $formatar->invoke($log, 'ERROR', '2026-11-08 10:00:00', $mensagem);
    }

    public function testInstalacaoUnicaFicaComoSempre(): void
    {
        $this->assertSame('ERROR - 2026-11-08 10:00:00 --> falhou' . PHP_EOL, $this->linha('falhou'));
    }

    public function testEmpresaEPainelAparecemNaLinha(): void
    {
        $GLOBALS['mapos_tenant_schema'] = 'mapos_loja1_a1b2c3';
        $this->assertSame('ERROR - 2026-11-08 10:00:00 --> [mapos_loja1_a1b2c3] falhou' . PHP_EOL, $this->linha('falhou'));

        unset($GLOBALS['mapos_tenant_schema']);
        $GLOBALS['mapos_contexto'] = 'plataforma';
        $this->assertStringContainsString('--> [plataforma] falhou', $this->linha('falhou'));
    }

    public function testSchemaEstranhoNaoQuebraOLogNemEntraNaLinha(): void
    {
        $GLOBALS['mapos_tenant_schema'] = "x\n ERROR - forjado";

        $this->assertSame('ERROR - 2026-11-08 10:00:00 --> falhou' . PHP_EOL, $this->linha('falhou'));
    }
}
