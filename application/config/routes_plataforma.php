<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Rotas do painel administrativo da plataforma (hostname SAAS_PAINEL_HOST).
 *
 * Carregado por config/routes.php quando o hook de tenant marca o contexto
 * `plataforma`, e **substitui** a tabela inteira: no hostname do painel nenhuma
 * tela de tenant (clientes, OS, API...) é alcançável — tudo que não está aqui
 * cai no 404 da última regra. Os controllers Plataforma_* também se recusam a
 * rodar fora deste contexto (Plataforma_Controller).
 */
$route = [
    'default_controller' => 'plataforma_empresas',
    '404_override' => '',
    'translate_uri_dashes' => false,

    'login' => 'plataforma_acesso',
    'login/entrar' => 'plataforma_acesso/entrar',
    'login/codigo' => 'plataforma_acesso/codigo',
    'login/verificar' => 'plataforma_acesso/verificar',
    'sair' => 'plataforma_acesso/sair',

    'conta' => 'plataforma_conta',
    'conta/(ativar|codigos|senha)' => 'plataforma_conta/$1',

    'empresas' => 'plataforma_empresas',
    'empresas/nova' => 'plataforma_empresas/nova',
    'empresas/(:num)' => 'plataforma_empresas/ver/$1',
    'empresas/(:num)/(status|dominio|verificar|senha|plano|backup|excluir)' => 'plataforma_empresas/$2/$1',

    'contas' => 'plataforma_equipe',
    'contas/nova' => 'plataforma_equipe/nova',
    'contas/(status|senha|redefinir2fa)' => 'plataforma_equipe/$1',

    'planos' => 'plataforma_planos',
    'planos/novo' => 'plataforma_planos/formulario',
    'planos/(:num)' => 'plataforma_planos/formulario/$1',
    'planos/(:num)/status' => 'plataforma_planos/status/$1',

    'backups' => 'plataforma_backups',
    'backups/(mapos_[a-z0-9_]+-[0-9]{8}-[0-9]{6}\.zip)' => 'plataforma_backups/baixar/$1',

    'auditoria' => 'plataforma_auditoria',

    // Por último: qualquer outra URI no painel é 404.
    '(.+)' => 'plataforma_acesso/nao_encontrado',
];
