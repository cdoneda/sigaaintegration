<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * local_sigaaintegration configuration.php description here.
 *
 * @package    local_sigaaintegration
 * @copyright  2024  Igor Ferreira Cemim
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sigaaintegration;

use moodle_exception;
use local_sigaaintegration\campus;

class configuration {

    // Novo método para pegar todos os clientes e suas configurações
    public static function getClientListConfig(): array {
        // Recupera a lista de clientes cadastrados
        $clients = get_config('local_sigaaintegration', 'clientlist');

        $client_configs = [];

        if ($clients) {
            // Divide os nomes por linhas ou vírgulas
            $clientnames = preg_split("/[\r\n,]+/", $clients, -1, PREG_SPLIT_NO_EMPTY);

            foreach ($clientnames as $client) {
                $client = trim($client);

                $id_campus = get_config('local_sigaaintegration', "id_campus_{$client}");
                $scheduled_sync = get_config('local_sigaaintegration', "scheduled_sync_{$client}");
                $modalidade_educacao = get_config('local_sigaaintegration', "modalidade_educacao_{$client}");

                if ($id_campus !== null && $scheduled_sync !== null && $modalidade_educacao !== null) {
                    $new_client = new campus($id_campus, $client, $scheduled_sync, $modalidade_educacao);
                    $client_configs[] = $new_client;
                }
            }

        }

        return $client_configs;
    }

    public static function getCampoPeriodoLetivo(): object {
        global $DB;

        $nomecampoperiodoletivo = get_config('local_sigaaintegration', 'periodfieldname');
        if (!$nomecampoperiodoletivo) {
            throw new moodle_exception('ERRO: O campo de Período Letivo não foi configurado.');
        }

        $campoperiodoletivo = $DB->get_record('customfield_field', ['shortname' => $nomecampoperiodoletivo]);
        if (!$campoperiodoletivo) {
            throw new moodle_exception(
                'ERRO: O campo de Período Letivo configurado não foi encontrado. nomeCampo: ' . $nomecampoperiodoletivo
            );
        }

        return $campoperiodoletivo;
    }

    public static function getNomeCampoDisciplinasArquivadas() :string {
        return get_config('local_sigaaintegration', 'archivecategoryname');
    }

    public static function getCampoMetadata(): object {
        global $DB;

        $nomecampometadata = get_config('local_sigaaintegration', 'metadatafieldname');
        if (!$nomecampometadata) {
            throw new moodle_exception('ERRO: O campo de Metadados não foi configurado.');
        }

        $campometadata = $DB->get_record('customfield_field', ['shortname' => $nomecampometadata]);
        if (!$campometadata) {
            throw new moodle_exception(
                'ERRO: O campo de Metadados configurado não foi encontrado. nomeCampo: ' . $nomecampometadata
            );
        }

        return $campometadata;
    }

    public static function getCampoCPF(): object {
        global $DB;

        $nomecampocpf = get_config('local_sigaaintegration', 'cpffieldname');
        if (!$nomecampocpf) {
            throw new moodle_exception('ERRO: O campo de CPF não foi configurado.');
        }

        $campocpf = $DB->get_record('user_info_field', ['shortname' => $nomecampocpf]);
        if (!$campocpf) {
            throw new moodle_exception(
                'ERRO: O campo de CPF configurado não foi encontrado. nomeCampo: ' . $nomecampocpf
            );
        }

        return $campocpf;
    }

    public static function getIdPapelProfessor(): int {
        $idpapelprofessor = (int) get_config('local_sigaaintegration', 'teacherroleid');
        if (!$idpapelprofessor) {
            throw new moodle_exception('ERRO: O papel de professor não foi configurado.');
        }

        return $idpapelprofessor;
    }

    public static function getIdPapelAluno(): int {
        $studentroleid = (int) get_config('local_sigaaintegration', 'studentroleid');
        if (!$studentroleid) {
            throw new moodle_exception('ERRO: O papel de estudante não foi configurado.');
        }

        return $studentroleid;
    }

    public static function getIdCategoriaBase(): int {
        return get_config('local_sigaaintegration', 'basecategory');
    }

}
