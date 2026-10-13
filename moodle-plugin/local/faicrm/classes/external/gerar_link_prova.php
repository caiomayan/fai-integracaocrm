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

namespace local_faicrm\external;

use context_module;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_faicrm\event\link_prova_gerado;
use local_faicrm\sso\elegibilidade;
use local_faicrm\sso\link;
use local_faicrm\util;
use moodle_exception;

/**
 * Generates a one-time login link that takes a candidate straight to a quiz (automatic access, spec 006).
 *
 * This class only validates the parameters and the permission, takes the lock, calls the rules
 * ({@see elegibilidade}) and the link generator ({@see link}) and records the audit event.
 *
 * @package    local_faicrm
 * @copyright  2026 FAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gerar_link_prova extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_RAW, 'Username (CPF) of the candidate', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
        ]);
    }

    /**
     * Generates the link for a candidate and a quiz.
     *
     * Rules, in order: permission, automatic access available and configured, quiz exists and is released,
     * candidate exists, candidate account is allowed and active, active enrolment in the course of the quiz.
     *
     * @param string $username Username (CPF) of the candidate.
     * @param int $cmid Course module id of the quiz.
     * @return array Login URL and when it expires.
     */
    public static function execute(string $username, int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['username' => $username, 'cmid' => $cmid]);

        $system = context_system::instance();
        self::validate_context($system);
        require_capability('local/faicrm:gerarlinkprova', $system);

        if (!link::available()) {
            throw new moodle_exception('ssoindisponivel', 'local_faicrm');
        }
        elegibilidade::require_released_courses();

        $cm = elegibilidade::find_quiz($params['cmid']);
        $course = get_course($cm->course);
        elegibilidade::require_released_quiz($course);

        $user = elegibilidade::find_candidate($params['username']);
        $quizcontext = context_module::instance($cm->id);
        elegibilidade::require_candidate_account($user, $quizcontext);
        elegibilidade::require_active_account($user);
        elegibilidade::require_enrolment($user, $course);
        elegibilidade::require_can_attempt($user, $quizcontext);

        // One key at a time per candidate, whatever the quiz: two simultaneous calls leave only the last key valid.
        $lock = util::lock_user_sso((int) $user->id);
        try {
            [$url, $validuntil] = link::generate((int) $user->id, (int) $cm->id);
        } finally {
            $lock->release();
        }

        link_prova_gerado::create([
            'context' => $quizcontext,
            'objectid' => $cm->id,
            'relateduserid' => $user->id,
        ])->trigger();

        return ['url' => $url, 'expiraem' => util::format_date($validuntil)];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_RAW, 'Login URL (single use) that leads to the quiz'),
            'expiraem' => new external_value(PARAM_RAW, 'When the link expires (ISO 8601)'),
        ]);
    }
}
