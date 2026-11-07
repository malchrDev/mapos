<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../core/Plataforma_Controller.php';

/**
 * Trilha de auditoria do painel da plataforma: as últimas ações (logins,
 * empresas criadas, suspensas, senhas redefinidas, domínios).
 */
class Plataforma_auditoria extends Plataforma_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->exigirLogin();
    }

    public function index()
    {
        $this->tela('plataforma/auditoria', [
            'linhas' => $this->plataforma_contas->auditoria(200),
        ], 'Auditoria', 'auditoria');
    }
}
