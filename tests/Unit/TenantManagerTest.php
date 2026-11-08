<?php

use PHPUnit\Framework\Attributes\DataProvider;

require_once APPPATH . 'libraries/Tenant_manager.php';

/**
 * Isolamento de tenant (Fase 1 — multi-tenancy, schema per tenant).
 *
 * O que separa um tenant de outro é o nome do schema. Ele entra em SQL cru
 * (`CREATE DATABASE` / `DROP DATABASE` não aceita binding), então estes testes
 * conferem as duas pontas que sustentam o isolamento:
 *
 * 1. `nomeSchema()` nunca devolve nome derivado de entrada hostil — é o que
 *    impediria um domínio crafted de apontar para o banco de outro cliente.
 * 2. `validarNomeSchema()` só aceita nome no formato gerado pelo sistema; todo o
 *    resto (backtick, ponto, espaço, caminho, schema alheio) é recusado.
 */
final class TenantManagerTest extends MaposTestCase
{
    private function manager(): Tenant_manager
    {
        $manager = $this->makeInstance(Tenant_manager::class);
        $this->setPrivateProperty($manager, 'CI', $this->fakeCiInstance($this->db));

        return $manager;
    }

    public function testNomeSchemaNasceDoDominioComPrefixoProprio(): void
    {
        // O ponto sai: só alfanumérico entra no nome do schema.
        $this->assertSame('mapos_acmecombr', Tenant_manager::nomeSchema('ACME.com.br'));
        $this->assertSame('mapos_lojateste', Tenant_manager::nomeSchema('loja.teste'));
    }

    public function testNomeSchemaDescartaTudoQueNaoSejaAlfanumerico(): void
    {
        // O hífen, o ponto e o underscore saem: só alfanumérico entra.
        $this->assertSame('mapos_ab', Tenant_manager::nomeSchema('a-b'));
        $this->assertSame('mapos_ab', Tenant_manager::nomeSchema('a.b'));
        $this->assertSame('mapos_ab', Tenant_manager::nomeSchema('a b'));
        $this->assertSame('mapos_ab', Tenant_manager::nomeSchema('a_b'));
    }

    public function testNomeSchemaNaoPassaDeCinquentaCaracteres(): void
    {
        // MySQL tem 64 bytes por schema; o prefixo já gasta 6. Um domínio longo
        // não pode estourar o limite e quebrar o CREATE DATABASE.
        $nome = Tenant_manager::nomeSchema(str_repeat('a', 200) . '.com.br');

        $this->assertLessThanOrEqual(56, strlen($nome));
        $this->assertSame('mapos_' . str_repeat('a', 50), $nome);
    }

    public function testNomeSchemaNaoHerdaBacktickNemComandoDoDominio(): void
    {
        // É a imagem do ataque: domínio com backtick tentando fechar o
        // identificador e emendar outro statement no CREATE DATABASE.
        $nome = Tenant_manager::nomeSchema('x`; DROP DATABASE mapos_padrao; --.com');

        // Do payload só sobra o que é alfanumérico: sem pontuação, o statement
        // não tem por onde nascer — é uma string inofensiva dentro de backticks.
        $this->assertSame('mapos_xdropdatabasemapospadraocom', $nome);
        $this->assertStringNotContainsString('`', $nome);
        $this->assertStringNotContainsString(';', $nome);
        $this->assertStringNotContainsString(' ', $nome);
    }

    public function testSchemaGeradoPeloSistemaPassaNaValidacao(): void
    {
        $manager = $this->manager();

        foreach (['acme.com.br', 'loja.teste', 'cliente-1.dominio.com'] as $dominio) {
            $this->invokeMethod($manager, 'validarNomeSchema', [Tenant_manager::nomeSchema($dominio)]);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider nomesDeSchemaProibidos
     */
    #[DataProvider('nomesDeSchemaProibidos')]
    public function testValidacaoRecusaNomeDeSchemaHostil(string $proibido): void
    {
        $manager = $this->manager();

        $this->expectException(InvalidArgumentException::class);
        $this->invokeMethod($manager, 'validarNomeSchema', [$proibido]);
    }

    public static function nomesDeSchemaProibidos(): array
    {
        return [
            'sem prefixo do tenant' => ['mapos_padrao'],
            'banco mestre' => ['mapos_master'],
            'information_schema' => ['information_schema'],
            'vazio' => [''],
            'só o prefixo' => ['mapos_'],
            'com backtick' => ['mapos_x`'],
            'com ponto' => ['mapos_x.y'],
            'com espaco' => ['mapos_x y'],
            'com hifen' => ['mapos_x-y'],
            'com ponto e virgula' => ['mapos_x; DROP DATABASE mapos_padrao; --'],
            'com barra de caminho' => ['mapos_../outro'],
            'com caracteres acentuados' => ['mapos_é'],
            'com porcentagem' => ['mapos_x%'],
            'com aspas' => ["mapos_x'"],
            'prefixo parecido' => ['maposx_y'],
            'maiusculas' => ['MAPOS_ACME'],
        ];
    }
}
