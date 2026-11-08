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
            'conta_criada' => 'Criou conta do painel',
            'conta_desativada' => 'Desativou conta do painel',
            'conta_reativada' => 'Reativou conta do painel',
            'conta_senha' => 'Gerou nova senha para conta do painel',
            'conta_2fa_redefinido' => 'Redefiniu o 2FA de conta do painel',
            '2fa_ativado' => 'Ativou o 2FA da própria conta',
            '2fa_codigos' => 'Gerou novos códigos de recuperação',
            'senha_propria' => 'Trocou a própria senha',
            'empresa_cancelada' => 'Cancelou a empresa',
            'empresa_excluida' => 'Excluiu a empresa definitivamente',
            'empresa_plano' => 'Alterou plano e vencimento',
            'backup_gerado' => 'Gerou backup',
            'backup_baixado' => 'Baixou backup',
            'plano_criado' => 'Criou plano',
            'plano_alterado' => 'Alterou plano',
            'plano_desativado' => 'Desativou plano',
            'plano_reativado' => 'Reativou plano',
        ][$acao] ?? $acao;
    }
}

if (! function_exists('plataformaTamanho')) {
    /** Tamanho de arquivo legível: "820 KB", "12,4 MB". */
    function plataformaTamanho(int $bytes): string
    {
        if ($bytes < 1048576) {
            return number_format(max(1, (int) ceil($bytes / 1024)), 0, ',', '.') . ' KB';
        }

        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}

if (! function_exists('plataformaVencimentoPill')) {
    /**
     * Props do pill-status do vencimento do plano, ou null sem vencimento.
     * Vencida: danger; vence em até 7 dias: warning; depois: neutral.
     *
     * @return array{label: string, variant: string}|null
     */
    function plataformaVencimentoPill(?string $vencimento, ?int $hoje = null): ?array
    {
        if ($vencimento === null || $vencimento === '') {
            return null;
        }

        $hoje ??= strtotime(date('Y-m-d'));
        $dia = strtotime($vencimento);
        $data = date('d/m/Y', (int) $dia);

        if ($dia < $hoje) {
            return ['label' => 'Venceu em ' . $data, 'variant' => 'danger'];
        }
        if ($dia <= $hoje + 7 * 86400) {
            return ['label' => 'Vence em ' . $data, 'variant' => 'warning'];
        }

        return ['label' => 'Vence em ' . $data, 'variant' => 'neutral'];
    }
}
