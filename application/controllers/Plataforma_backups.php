<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../core/Plataforma_Controller.php';

/**
 * Backups das empresas (Tenant_manager::backup()): lista e download. Os
 * arquivos ficam fora do web (SAAS_BACKUP_PATH ou STORAGE_PATH/_backups) e só
 * saem por aqui, com login e registro na auditoria. O backup final de uma
 * empresa excluída também fica aqui.
 */
class Plataforma_backups extends Plataforma_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->exigirLogin();
    }

    public function index()
    {
        $erro = null;
        try {
            $backups = $this->tenant_manager->listarBackups();
        } catch (RuntimeException $e) {
            $backups = [];
            $erro = $e->getMessage();
        }

        $empresas = [];
        foreach ($this->tenant_manager->listarParaPainel() as $empresa) {
            $empresas[$empresa['schema']] = ['id' => (int) $empresa['id'], 'nome' => (string) $empresa['nome']];
        }

        $this->tela('plataforma/backups', [
            'backups' => $backups,
            'empresas' => $empresas,
            'erro' => $erro,
        ], 'Backups', 'backups');
    }

    public function baixar($arquivo = null)
    {
        $caminho = is_string($arquivo) ? $this->tenant_manager->caminhoBackup($arquivo) : null;
        if ($caminho === null) {
            show_404();
        }

        $this->auditar('backup_baixado', $arquivo);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($caminho));
        header('Content-Disposition: attachment; filename="' . $arquivo . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        readfile($caminho);
        exit;
    }
}
