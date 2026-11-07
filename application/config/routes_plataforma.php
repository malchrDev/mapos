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
    'sair' => 'plataforma_acesso/sair',

    'empresas' => 'plataforma_empresas',
    'empresas/nova' => 'plataforma_empresas/nova',
    'empresas/(:num)' => 'plataforma_empresas/ver/$1',
    'empresas/(:num)/(status|dominio|verificar|senha)' => 'plataforma_empresas/$2/$1',

    'auditoria' => 'plataforma_auditoria',

    // Por último: qualquer outra URI no painel é 404.
    '(.+)' => 'plataforma_acesso/nao_encontrado',
];
