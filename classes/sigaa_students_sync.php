<?php
/**
 *
 * @package   local_sigaaintegration
 * @copyright 2024, Cassiano Doneda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sigaaintegration;

class sigaa_students_sync extends sigaa_base_sync
{
    private string $ano;
    private string $periodo;

    private user_moodle $user_moodle;

    public function __construct(string $ano, string $periodo)
    {
        parent::__construct();
        $this->ano = $ano;
        $this->periodo = $periodo;
        $this->user_moodle = new user_moodle();
    }

    protected function get_records($client_api, $campus): array
    {
        $periodoletivo = sigaa_periodo_letivo::buildFromParameters($this->ano, $this->periodo);
        return $client_api->get_enrollments($campus, $periodoletivo);
    }

    protected function process_records(array $records, $campus): void
    {
        mtrace("Processando dados: ". $campus->description);
        foreach ($records as $key => $record) {
            try {
                $this->user_moodle->insert($record);
            } catch (Exception $e) {
                mtrace(sprintf(
                    'ERRO: Falha ao processar o estudante. Matrícula: %s, erro: %s',
                    $key,
                    $e->getMessage()
                ));
            }
        }
    }
}
