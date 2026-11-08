<?php

require_once BASEPATH . 'libraries/Email.php';
require_once APPPATH . 'libraries/MY_Email.php';

/**
 * Remetente dos e-mails das empresas no SaaS (SAAS_EMAIL_REMETENTE): sai pelo
 * endereço da plataforma, com o nome da empresa e Reply-To para ela.
 */
final class EmailRemetenteTest extends MaposTestCase
{
    public function testFromVaiParaAPlataformaEOOriginalViraReplyTo(): void
    {
        $saida = MY_Email::cabecalhosDaPlataforma(['From' => '"Loja Um" <contato@lojaum.com>', 'Subject' => 'OS 5'], 'nao-responda@saas.com');

        $this->assertSame('"Loja Um" <nao-responda@saas.com>', $saida['From']);
        $this->assertSame('contato@lojaum.com', $saida['Reply-To']);
        $this->assertSame('<nao-responda@saas.com>', $saida['Return-Path'], 'Envelope no domínio da plataforma (SPF).');
        $this->assertSame('OS 5', $saida['Subject']);
    }

    public function testSemNomeNoFromUsaONomeDoEmitente(): void
    {
        $saida = MY_Email::cabecalhosDaPlataforma(['From' => 'contato@lojaum.com'], 'nao-responda@saas.com', 'Oficina do Zé');

        $this->assertSame('"Oficina do Zé" <nao-responda@saas.com>', $saida['From']);
        $this->assertSame('contato@lojaum.com', $saida['Reply-To']);

        $semNome = MY_Email::cabecalhosDaPlataforma(['From' => 'contato@lojaum.com'], 'nao-responda@saas.com');
        $this->assertSame('nao-responda@saas.com', $semNome['From']);
    }

    public function testNomeNaoInjetaCabecalho(): void
    {
        $saida = MY_Email::cabecalhosDaPlataforma(['From' => 'x@y.com'], 'nao-responda@saas.com', "Loja\r\nBcc: alvo@vitima.com \"<a>\"");

        $this->assertStringNotContainsString("\n", $saida['From']);
        $this->assertStringNotContainsString("\r", $saida['From']);
        $this->assertSame(1, substr_count($saida['From'], '<'), 'Só o < do endereço.');
        $this->assertStringEndsWith('<nao-responda@saas.com>', $saida['From']);
    }

    public function testReplyToExistenteFicaERemetenteInvalidoNaoMudaNada(): void
    {
        $original = ['From' => 'contato@lojaum.com', 'Reply-To' => 'suporte@lojaum.com'];

        $this->assertSame('suporte@lojaum.com', MY_Email::cabecalhosDaPlataforma($original, 'nao-responda@saas.com')['Reply-To']);
        $this->assertSame($original, MY_Email::cabecalhosDaPlataforma($original, 'nao é e-mail'));
    }

    public function testFromIgualAoRemetenteNaoGeraReplyTo(): void
    {
        $saida = MY_Email::cabecalhosDaPlataforma(['From' => 'Nao-Responda@saas.com'], 'nao-responda@saas.com');

        $this->assertArrayNotHasKey('Reply-To', $saida);
    }
}
