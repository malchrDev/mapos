<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * Funções das telas do painel administrativo da plataforma
 * (views/plataforma/), sem acesso a banco.
 */

if (! function_exists('plataformaStatusPill')) {
    /**
     * Props do pill-status para o status de um tenant (DESIGN.md: estado usa a
     * paleta semântica, nunca o laranja de ação).
     *
     * @return array{label: string, variant: string}
     */
    function plataformaStatusPill(string $status): array
    {
        return match ($status) {
            'ativo' => ['label' => 'Ativa', 'variant' => 'success'],
            'suspenso' => ['label' => 'Suspensa', 'variant' => 'warning'],
            'cancelado' => ['label' => 'Cancelada', 'variant' => 'neutral'],
            default => ['label' => $status, 'variant' => 'neutral'],
        };
    }
}

if (! function_exists('plataformaAcaoLegivel')) {
    /**
     * Texto da ação gravada em plataforma_auditoria.acao.
     */
    function plataformaAcaoLegivel(string $acao): string
    {
        return [
            'login' => 'Entrou no painel',
            'logout' => 'Saiu do painel',
            'login_bloqueado' => 'Login bloqueado por excesso de tentativas',
            'empresa_criada' => 'Criou a empresa',
            'empresa_suspensa' => 'Suspendeu a empresa',
            'empresa_reativada' => 'Reativou a empresa',
            'dominio_adicionado' => 'Cadastrou domínio próprio',
            'dominio_verificado' => 'Verificou domínio próprio',
            'senha_redefinida' => 'Gerou nova senha para usuário',
            'conta_criada' => 'Conta da plataforma criada',
        ][$acao] ?? $acao;
    }
}
