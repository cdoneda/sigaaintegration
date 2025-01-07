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
 *
 * @package   local_sigaaintegration
 * @copyright 2024, Igor Ferreira Cemim
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sigaaintegration;

use core\context;
use core_course_category;
use Exception;

class sigaa_enrollments_teachers_sync extends sigaa_base_sync
{

    private string $ano;

    private string $periodo;

    private array $courseNotFound = [];

    private int $studentroleid;

    private $course_discipline_mapper;

    public function __construct(string $year, string $period)
    {
        parent::__construct();
        $this->ano = $year;
        $this->periodo = $period;
        $this->studentroleid = configuration::getIdPapelAluno();
        $this->course_discipline_mapper = new course_discipline_mapper();
    }

    protected function get_records(campus $campus): array
    {
        $periodoletivo = sigaa_periodo_letivo::buildFromParameters($this->ano, $this->periodo);
        return $this->api_client->get_enrollments($campus, $periodoletivo);
    }

    protected function process_records(campus $campus, array $records): void
    {
        try {
            $this->enroll_student_into_courses($campus, $records);
        } catch (Exception $e) {
            mtrace(sprintf(
                'ERRO: Falha ao processar todas as inscrições do estudante. erro: %s',
                $e->getMessage()
            ));
        }
        mtrace('INFO: Fim importação.');
    }

    /**
     * Tenta inscrever o estudante nas disciplinas retornadas pela API do SIGAA.
     */
    private function enroll_student_into_courses($campus, array $enrollments): void
    {
        foreach ($enrollments as $enrollment) {
            $user = $this->search_student($enrollment['login']);
            mtrace($enrollment['login']);
            if (!$user) {
                mtrace(sprintf('ERRO: Usuário não encontrado. usuário: %s', $enrollment['login']));
                return;
            }

            foreach ($enrollment['disciplinas'] as $course_enrollment) {
                try {
                    if($this->validate($course_enrollment)) {
                        // generate_course_idnumber(campus $campus, $enrollment, $disciplina);
                        $course_discipline = $this->course_discipline_mapper->map_to_course_discipline($enrollment, $course_enrollment);
                        $courseidnumber = $course_discipline->generate_course_idnumber($campus);
                        $this->enroll_student_into_single_course($user, $courseidnumber);
                    }
                } catch (Exception $e) {
                    mtrace(sprintf(
                        'ERRO: Falha ao processar inscrição de estudante em uma disciplina. ' .
                        'matrícula: %s, usuário: %s, disciplina: %s, erro: %s',
                        $enrollment['matricula'],
                        $user->username,
                        $courseidnumber,
                        $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * Busca estudante pelo login/CPF.
     */
    private function search_student(string $login): object|false
    {
        global $DB;
        return $DB->get_record('user', ['username' => $login]);
    }

    private function validate(array $discipline): bool {
        // Valida os campos necessários da disciplina
        return isset($discipline['periodo']) &&
            isset($discipline['semestre_oferta_disciplina']) &&
            $discipline['semestre_oferta_disciplina'] !== null &&
            isset($discipline['turma']) &&
            $discipline['turma'] !== null;
    }

    /**
     * Busca disciplina pelo código de integração.
     */
    private function search_course(string $courseidnumber): ?object
    {
        /**
         * Evita busca repetida por disciplinas não encontradas.
         */
        if (array_search($courseidnumber, $this->courseNotFound)) {
            return null;
        }

        $results = core_course_category::search_courses(['search' => $courseidnumber]);
        if (count($results) > 0) {
            return current($results);
        }

        $this->courseNotFound[] = $courseidnumber;
        return null;
    }

    /**
     * Inscreve o estudante em uma disciplina.
     */
    private function enroll_student(object $course, object $user): void
    {
        global $CFG;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        if (is_enrolled(context\course::instance($course->id), $user)) {

            /*
            mtrace(sprintf(
                "INFO: O estudante já está inscrito na disciplina. usuário: %s, disciplina: %s",
                $user->username,
                $course->idnumber
            ));
            */
            return;
        }

        $enrolinstances = enrol_get_instances($course->id, true);
        $manualenrolinstance = current(array_filter($enrolinstances, function ($instance) {
            return $instance->enrol == 'manual';
        }));
        if (empty($manualenrolinstance)) {
            mtrace(
                'ERRO: o plugin Inscrição Manual ativado é um pré-requisito para o funcionamento da ' .
                'integração com o SIGAA. Ative o plugin Inscrição Manual e execute o processo de integração novamente.'
            );
            return;
        }

        $manualenrol = enrol_get_plugin('manual');
        $manualenrol->enrol_user($manualenrolinstance, $user->id, $this->studentroleid);
        mtrace(sprintf(
            "INFO: O estudante foi inscrito na disciplina com sucesso. usuário: %s, disciplina: %s",
            $user->username,
            $course->idnumber
        ));
    }

    /**
     * Tenta increver o estudante em uma determinada disciplina retornada pela API do SIGAA.
     */
    private function enroll_student_into_single_course(object $user, string $courseidnumber) :void
    {
        $course = $this->search_course($courseidnumber);
        if (!$course) {
            mtrace(sprintf(
                'ERRO: Disciplina não encontrada. Inscrição não realizada. usuário: %s, disciplina: %s',
                $user->username,
                $courseidnumber
            ));
            return;
        }

        $this->enroll_student($course, $user);
    }




    // Copia das funções do código original

    private function buscar_professor_por_cpf(string $login): object|false {
        global $DB;
        return $DB->get_record('user', ['username' => $login]);
    }

    private function vincular_professores_disciplina(array $docentes, object $disciplina): void {
        $professorescadastrados = [];

        // Vincula o(s) professor(es)
        foreach ($docentes as $docente) {
            // Verifica se o CPF está vazio ou inválido
            if (empty($docente['cpf_docente'])) {
                mtrace(sprintf(
                    'ERRO: Professor sem CPF cadastrado no SIGAA. Não é possível inscrever na disciplina. Nome: %s',
                    $docente['docente']
                ));
                continue;
            }

            // Corrige o CPF para ter 11 dígitos, se necessário
            $cpf = $this->validar_e_corrigir_cpf($docente['cpf_docente']);

            if (!$cpf) {
                mtrace(sprintf(
                    'ERRO: CPF inválido para o professor: %s. Não foi possível inscrever na disciplina. Disciplina: %s',
                    $docente['docente'],
                    $disciplina->idnumber
                ));
                continue;
            }

            // Atualiza o CPF corrigido no docente
            $docente['cpf_docente'] = $cpf;

            // Busca o usuário pelo CPF
            $usuariodocente = $this->buscar_professor_por_cpf($cpf);
            if (!$usuariodocente) {
                mtrace(sprintf(
                    'ERRO: Professor não encontrado. Professor: %s, Disciplina: %s',
                    $cpf,
                    $disciplina->idnumber
                ));
                continue;
            }

            // Realiza inscrição
            $this->vincular_professor($disciplina, $usuariodocente);

            $professorescadastrados[] = $cpf;
        }
    }



    private function validar_e_corrigir_cpf(string $cpf): ?string {
        // Remove qualquer caractere não numérico
        $cpf = preg_replace('/\D/', '', $cpf);

        // Verifica se o CPF tem 11 dígitos
        if (strlen($cpf) !== 11) {
            // Se o CPF não tiver 11 dígitos, corrige adicionando zeros à esquerda
            $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);
        }

        // Verifica se o CPF é válido
        if ($this->validar_cpf($cpf)) {
            return $cpf;
        }
        // Se o CPF não for válido, retorna null
        return null;
    }

    private function validar_cpf(string $cpf): bool {
        // Exemplo de validação simples (aceita qualquer número de 11 dígitos)
        return preg_match('/^\d{11}$/', $cpf);
    }


    /**
     * Inscreve o professor ao curso e vincula as roles necessárias no contexto do curso.
     *
     * @throws moodle_exception
     * @throws dml_exception
     */
    private function vincular_professor(object $course, object $user): void {
    global $CFG;
    require_once($CFG->dirroot . '/lib/enrollib.php');

    if (is_enrolled(context\course::instance($course->id), $user)) {
        mtrace(sprintf(
            'INFO: Professor já está inscrito na disciplina. usuário: %s, disciplina: %s',
            $user->username,
            $course->idnumber
            ));
        return;
    }

    $enrolinstances = enrol_get_instances($course->id, true);
    $manualenrolinstance = current(array_filter($enrolinstances, function($instance) {
            return $instance->enrol == 'manual';
        }));
    if (empty($manualenrolinstance)) {
        mtrace(
        'ERRO: o plugin Inscrição Manual ativado é um pré-requisito para o funcionamento da ' .
        'integração com o SIGAA. Ative o plugin Inscrição Manual e execute o processo de integração novamente.'
        );
        return;
    }

    $manualenrol = enrol_get_plugin('manual');
    $manualenrol->enrol_user($manualenrolinstance, $user->id, $this->editingteacherroleid);

    mtrace(sprintf(
        "INFO: Professor inscrito na disciplina com sucesso. professor: %s, disciplina: %s",
            $user->username,
            $course->idnumber
        ));
    }


}
