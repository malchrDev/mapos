<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../helpers/tenant_helper.php';
/**
 * CodeIgniter Email Queue
 *
 * A CodeIgniter library to queue e-mails.
 *
 * @category    Libraries
 *
 * @author      Thaynã Bruno Moretti
 *
 * @link    http://www.meau.com.br/
 *
 * @license http://www.opensource.org/licenses/mit-license.html
 *
 * Updated by @RamonSilva for Map-OS
 */
class MY_Email extends CI_Email
{
    /** @var object Instância do CodeIgniter, injetada por get_instance() no construtor */
    protected $CI;

    // DB table
    private $table_email_queue = 'email_queue';

    // Main controller
    private $main_controller = 'email/process';

    // PHP Nohup command line
    private $phpcli = 'nohup php';

    private $expiration = null;

    // Status (pending, sending, sent, failed)
    private $status;

    /**
     * Constructor
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        log_message('debug', 'Email Queue Class Initialized');

        $this->expiration = 60 * 5;
        $this->CI = &get_instance();

        $this->CI->load->database('default');
    }

    public function set_status($status)
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get
     *
     * Get queue emails.
     *
     * @return mixed
     */
    public function get($limit = null, $offset = null)
    {
        if ($this->status != false) {
            $this->CI->db->where('status', $this->status);
        }

        $query = $this->CI->db->get("{$this->table_email_queue}", $limit, $offset);

        return $query->result();
    }

    /**
     * Save
     *
     * Add queue email to database.
     *
     * @return mixed
     */
    public function send($skip_job = false)
    {
        if ($skip_job === true) {
            return parent::send();
        }

        $date = date('Y-m-d H:i:s');

        $to = is_array($this->_recipients) ? implode(', ', $this->_recipients) : $this->_recipients;
        $cc = implode(', ', $this->_cc_array);
        $bcc = implode(', ', $this->_bcc_array);

        $dbdata = [
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'message' => $this->_body,
            'headers' => json_encode($this->_headers),
            'status' => 'pending',
            'date' => $date,
        ];

        return $this->CI->db->insert($this->table_email_queue, $dbdata);
    }

    /**
     * Start process
     *
     * Start php process to send emails
     *
     * @return mixed
     */
    public function start_process()
    {
        $filename = FCPATH . 'index.php';
        // Numa empresa do SaaS o processo precisa saber de qual (tenantResolverCli).
        $schema = tenantSchemaAtual();
        $ambiente = $schema !== null ? 'MAPOS_TENANT_SCHEMA=' . escapeshellarg($schema) . ' ' : '';
        $exec = shell_exec("{$ambiente}{$this->phpcli} {$filename} {$this->main_controller} > /dev/null &");

        return $exec;
    }

    /**
     * Send queue
     *
     * Send queue emails.
     *
     * @return void
     */
    public function send_queue()
    {
        $this->set_status('pending');
        $emails = $this->get();

        $this->CI->db->where('status', 'pending');
        $this->CI->db->set('status', 'sending');
        $this->CI->db->set('date', date('Y-m-d H:i:s'));
        $this->CI->db->update($this->table_email_queue);

        foreach ($emails as $email) {
            $recipients = explode(', ', $email->to);

            $cc = ! empty($email->cc) ? explode(', ', $email->cc) : [];
            $bcc = ! empty($email->bcc) ? explode(', ', $email->bcc) : [];

            $this->_headers = json_decode_legacy($email->headers);
            $this->_headers = $this->cabecalhosDoEnvio(is_array($this->_headers) ? $this->_headers : []);
            // O CI_Email troca o Reply-To pelo From, a menos que venha por reply_to().
            if (isset($this->_headers['Reply-To'])) {
                $responder = (string) $this->_headers['Reply-To'];
                unset($this->_headers['Reply-To']);
                $this->reply_to($responder);
            } else {
                $this->_replyto_flag = false;
            }

            $this->to($recipients);
            $this->cc($cc);
            $this->bcc($bcc);

            $this->message($email->message);

            if ($this->send(true)) {
                $status = 'sent';
            } else {
                log_message('error', $this->print_debugger());
                $status = 'failed';
            }

            $this->CI->db->where('id', $email->id);

            $this->CI->db->set('status', $status);
            $this->CI->db->set('date', date('Y-m-d H:i:s'));
            $this->CI->db->update($this->table_email_queue);
        }
    }

    /** @var string|null|false Nome do emitente da empresa (false = ainda não lido) */
    private $nomeDoEmitente = false;

    /**
     * Cabeçalhos com que o e-mail da fila sai. Numa empresa do SaaS com
     * SAAS_EMAIL_REMETENTE preenchido, o remetente passa a ser o da plataforma
     * (dono do SMTP), com o nome da empresa e "Responder para" o e-mail dela:
     * mandar "De: contato@empresa.com" pelo SMTP da plataforma falha no
     * SPF/DMARC e cai em spam. Fora disso, nada muda.
     */
    private function cabecalhosDoEnvio(array $cabecalhos): array
    {
        $remetente = trim((string) ($_ENV['SAAS_EMAIL_REMETENTE'] ?? ''));
        if ($remetente === '' || ! function_exists('tenantSchemaAtual') || tenantSchemaAtual() === null) {
            return $cabecalhos;
        }

        if ($this->nomeDoEmitente === false) {
            $linha = $this->CI->db->select('nome')->limit(1)->get('emitente')->row();
            $this->nomeDoEmitente = $linha->nome ?? null;
        }

        return self::cabecalhosDaPlataforma($cabecalhos, $remetente, $this->nomeDoEmitente);
    }

    /**
     * Troca o From pelo remetente da plataforma. O From original vira
     * Reply-To (se não houver um) e o nome dele (ou o do emitente) fica como
     * nome de exibição. Quebras de linha, aspas e <> saem do nome: ele entra
     * num cabeçalho.
     */
    public static function cabecalhosDaPlataforma(array $cabecalhos, string $remetente, ?string $nomeDoEmitente = null): array
    {
        if (filter_var($remetente, FILTER_VALIDATE_EMAIL) === false) {
            return $cabecalhos;
        }

        $original = trim((string) ($cabecalhos['From'] ?? ''));
        $nome = preg_match('/^"?([^"<]*)"?\s*</', $original, $m) ? trim($m[1]) : '';
        $nome = $nome !== '' ? $nome : trim((string) $nomeDoEmitente);
        $nome = trim((string) preg_replace('/[\r\n"<>\\\\]+/', ' ', $nome));

        $enderecoOriginal = preg_match('/<([^>]+)>/', $original, $m) ? trim($m[1]) : $original;
        if (! isset($cabecalhos['Reply-To']) && filter_var($enderecoOriginal, FILTER_VALIDATE_EMAIL) !== false && strcasecmp($enderecoOriginal, $remetente) !== 0) {
            $cabecalhos['Reply-To'] = $enderecoOriginal;
        }

        $cabecalhos['From'] = $nome !== '' ? '"' . $nome . '" <' . $remetente . '>' : $remetente;
        $cabecalhos['Return-Path'] = '<' . $remetente . '>';

        return $cabecalhos;
    }

    /**
     * Retry failed emails
     *
     * Resend failed or expired emails
     *
     * @return void
     */
    public function retry_queue()
    {
        $expire = (time() - $this->expiration);
        $date_expire = date('Y-m-d H:i:s', $expire);

        $this->CI->db->set('status', 'pending');
        $this->CI->db->where("(date < '{$date_expire}' AND status = 'sending')");
        $this->CI->db->or_where("status = 'failed'");

        $this->CI->db->update($this->table_email_queue);

        log_message('debug', 'Email queue retrying...');
    }
}
