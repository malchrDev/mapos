<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../vendor/autoload.php';

if (! class_exists('MpdfClienteSemRede')) {
    /**
     * Cliente HTTP do mPDF que nunca sai para a rede (#2879): um relatório com
     * <img src="https://..."> ou CSS remoto faria o servidor requisitar a URL
     * (SSRF cego). A resposta é 403, o que o mPDF trata como recurso ausente.
     * Logos e imagens locais não passam por aqui.
     */
    class MpdfClienteSemRede implements \Mpdf\Http\ClientInterface
    {
        public function sendRequest(\Psr\Http\Message\RequestInterface $request)
        {
            return new \Mpdf\PsrHttpMessageShim\Response(403);
        }
    }
}

if (! function_exists('mpdfCriar')) {
    /**
     * mPDF A4 sem acesso à rede. A pasta temporária fica fora do site
     * (sys_get_temp_dir()): assets/uploads/temp é pública e, no SaaS, comum a
     * todas as empresas.
     */
    function mpdfCriar(bool $landscape = false): \Mpdf\Mpdf
    {
        $temporaria = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mapos-mpdf';
        if (! is_dir($temporaria) && ! @mkdir($temporaria, 0700, true) && ! is_dir($temporaria)) {
            $temporaria = FCPATH . 'assets/uploads/temp/';
        }

        return new \Mpdf\Mpdf(
            ['c', $landscape ? 'A4-L' : 'A4', 'tempDir' => $temporaria],
            new \Mpdf\Container\SimpleContainer(['httpClient' => new MpdfClienteSemRede()])
        );
    }
}

function pdf_create($html, $filename, $stream = true, $landscape = false)
{
    $mpdf = mpdfCriar($landscape);

    $mpdf->showImageErrors = true;
    $mpdf->WriteHTML($html);

    if ($stream) {
        $mpdf->Output($filename . '.pdf', 'I');
    } else {
        $mpdf->Output(FCPATH . 'assets/uploads/temp/' . $filename . '.pdf', 'F');

        return FCPATH . 'assets/uploads/temp/' . $filename . '.pdf';
    }
}
