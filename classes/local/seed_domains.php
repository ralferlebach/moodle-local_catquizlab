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
 * Derivation of the separate random sources a run draws from.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Derives one seed per random source from the master seed.
 *
 * The paired design only works if a person stays the same person across the
 * cells being compared. With a single run seed derived from the cell key, that
 * fails by construction: change the strategy and the cell key changes, the run
 * seed changes, and the "same" twin is a freshly drawn person. Differences
 * between two cells then mix the experimental factor with sampling noise.
 *
 * So each source gets its own seed, and each seed depends only on the factors
 * that are genuinely part of that source:
 *
 *     master seed
 *     ├── person base       (replication, twin index)
 *     ├── person deviation  (replication, twin index, stratum, severity)
 *     ├── pool              (replication, pool condition)
 *     ├── mutation          (replication, pool condition, variant)
 *     ├── response          (run, person, question, attempt)            RNG schema 1, runs before 0.7.61
 *     └── response-crn      (replication, twin, canonical item, presentation)  RNG schema 2 (#155)
 *
 * Strategy and budget appear in none of them, which is exactly what makes them
 * nuisance factors for the person: two strategy cells share their twins.
 *
 * Since 0.7.61 (#155) the same holds for the answers. Schema 1 drew each answer from the run, the person row and
 * the question id — all three local to a run, so the same twin answering the same item in two strategy cells drew
 * two different random numbers. Schema 2 draws from replication, twin and canonical item key (item_identity): one
 * common random number per twin and item, whatever the strategy, the ids or the order of play. Where the twin's
 * ability or the item's true parameters differ (stratum, severity, a pool variant), the same number goes through a
 * different probability, so the answers differ only as much as the condition makes them. Runs keep the schema they
 * were created with: it is recorded in their manifest, and a run without it is schema 1.
 *
 * Derivation is a stable hash of the domain and its parts, so it does not
 * depend on ordering or on the platform's random generator. Only the low 31
 * bits are kept, because mt_srand takes an int and negative seeds are not
 * portable.
 */
class seed_domains {
    /** @var string The base ability of a digital twin. */
    public const DOMAIN_PERSON_BASE = 'person-base';

    /** @var string The stratum-specific local deviations layered on a twin. */
    public const DOMAIN_PERSON_DEVIATION = 'person-deviation';

    /** @var string The ideal item pool blueprint. */
    public const DOMAIN_POOL = 'pool';

    /** @var string The pool mutation applied on top of the ideal blueprint. */
    public const DOMAIN_MUTATION = 'mutation';

    /** @var string The simulated response process, schema 1: local to a run. */
    public const DOMAIN_RESPONSE = 'response';

    /** @var string The simulated response process, schema 2: common random numbers per twin and item (#155). */
    public const DOMAIN_RESPONSE_CRN = 'response-crn';

    /** @var int The RNG schema new runs are created with. */
    public const RNG_SCHEMA = 2;

    /**
     * Derive a seed for one domain from the master seed and the domain's parts.
     *
     * @param int $master The experiment's master seed.
     * @param string $domain One of the DOMAIN_* constants.
     * @param array $parts Scalars identifying the draw inside the domain.
     * @return int A non-negative seed.
     */
    public static function derive(int $master, string $domain, array $parts = []): int {
        $flat = [];
        foreach ($parts as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $flat[] = (is_int($key) ? '' : $key . '=') . (string) $value;
        }
        $material = $master . '|' . $domain . '|' . implode('|', $flat);

        // A truncated sha256 rather than crc32: the parts differ in one short
        // token often (mild/medium/strong), and crc32 keeps such inputs close
        // together, which correlates the resulting streams.
        return (int) (hexdec(substr(hash('sha256', $material), 0, 8)) & 0x7fffffff);
    }

    /**
     * The seed fixing a digital twin's global ability.
     *
     * Depends on replication and twin index only, so the same twin recurs
     * across every cell of the design.
     *
     * @param int $master The master seed.
     * @param int $replication The replication number.
     * @param int $twinindex The twin's index within the replication, or 0 for the whole cohort.
     * @return int
     */
    public static function person_base(int $master, int $replication, int $twinindex = 0): int {
        return self::derive($master, self::DOMAIN_PERSON_BASE, [
            'rep'  => $replication,
            'twin' => $twinindex,
        ]);
    }

    /**
     * The seed for the stratum-specific deviations layered on a twin.
     *
     * @param int $master The master seed.
     * @param int $replication The replication number.
     * @param string $stratum The person stratum.
     * @param string $severity The deviation severity.
     * @param int $twinindex The twin's index, or 0 for the whole cohort.
     * @return int
     */
    public static function person_deviation(
        int $master,
        int $replication,
        string $stratum,
        string $severity,
        int $twinindex = 0
    ): int {
        return self::derive($master, self::DOMAIN_PERSON_DEVIATION, [
            'rep'      => $replication,
            'twin'     => $twinindex,
            'stratum'  => $stratum,
            'severity' => $severity,
        ]);
    }

    /**
     * The seed for the ideal pool blueprint.
     *
     * @param int $master The master seed.
     * @param int $replication The replication number.
     * @param string $poolcondition An identifier of the pool condition, usually the model key.
     * @return int
     */
    public static function pool(int $master, int $replication, string $poolcondition = 'default'): int {
        return self::derive($master, self::DOMAIN_POOL, [
            'rep'  => $replication,
            'pool' => $poolcondition,
        ]);
    }

    /**
     * The seed for the pool mutation.
     *
     * @param int $master The master seed.
     * @param int $replication The replication number.
     * @param string $variant The pool variant.
     * @param string $poolcondition An identifier of the pool condition.
     * @return int
     */
    public static function mutation(
        int $master,
        int $replication,
        string $variant,
        string $poolcondition = 'default'
    ): int {
        return self::derive($master, self::DOMAIN_MUTATION, [
            'rep'     => $replication,
            'pool'    => $poolcondition,
            'variant' => $variant,
        ]);
    }

    /**
     * The seed for one simulated response.
     *
     * @param int $master The master seed.
     * @param int $runid The run.
     * @param int $personid The person.
     * @param int $questionid The question.
     * @param int $attempt The attempt number.
     * @return int
     */
    public static function response(
        int $master,
        int $runid,
        int $personid,
        int $questionid,
        int $attempt = 1
    ): int {
        return self::derive($master, self::DOMAIN_RESPONSE, [
            'run'      => $runid,
            'person'   => $personid,
            'question' => $questionid,
            'attempt'  => $attempt,
        ]);
    }

    /**
     * The seed for one simulated response under RNG schema 2: one per twin and canonical item (#155).
     *
     * A presentation number other than 1 is for an item shown to the same person again; a repeated presentation
     * with the same number gets the same answer, which is the documented rule: a twin who meets an item twice answers
     * it the same way, however often the server is asked.
     *
     * @param int $master The master seed.
     * @param int $replication The replication.
     * @param string $twinid The twin (person_generator::twin_id()).
     * @param string $itemkey The canonical item key (item_identity::key()).
     * @param int $presentation Which presentation of the item to this person, from 1.
     * @return int
     */
    public static function response_crn(
        int $master,
        int $replication,
        string $twinid,
        string $itemkey,
        int $presentation = 1
    ): int {
        return self::derive($master, self::DOMAIN_RESPONSE_CRN, [
            'rep'          => $replication,
            'twin'         => $twinid,
            'item'         => $itemkey,
            'presentation' => $presentation,
        ]);
    }

    /**
     * The RNG schema a run was created with, from its manifest: 2 where it names the common-random-number response
     * domain, else 1.
     *
     * @param array $manifest The run's manifest.
     * @return int
     */
    public static function rng_schema(array $manifest): int {
        $crn = $manifest['config']['seeds'][self::DOMAIN_RESPONSE_CRN] ?? null;

        return is_array($crn) ? (int) ($crn['schema'] ?? self::RNG_SCHEMA) : 1;
    }

    /**
     * All seeds of a run, for the manifest.
     *
     * Records which factors each source depends on, so a reader can tell from
     * the manifest alone why two cells share their twins.
     *
     * @param int $master The master seed.
     * @param int $replication The replication number.
     * @param string $stratum The person stratum.
     * @param string $severity The deviation severity.
     * @param string $variant The pool variant.
     * @param string $poolcondition An identifier of the pool condition.
     * @return array<string, array{seed: int, dependson: string[]}>
     */
    public static function manifest_block(
        int $master,
        int $replication,
        string $stratum,
        string $severity,
        string $variant,
        string $poolcondition = 'default'
    ): array {
        return [
            self::DOMAIN_PERSON_BASE      => [
                'seed'      => self::person_base($master, $replication),
                'dependson' => ['replication', 'twinindex'],
            ],
            self::DOMAIN_PERSON_DEVIATION => [
                'seed'      => self::person_deviation($master, $replication, $stratum, $severity),
                'dependson' => ['replication', 'twinindex', 'stratum', 'severity'],
            ],
            self::DOMAIN_POOL             => [
                'seed'      => self::pool($master, $replication, $poolcondition),
                'dependson' => ['replication', 'poolcondition'],
            ],
            self::DOMAIN_MUTATION         => [
                'seed'      => self::mutation($master, $replication, $variant, $poolcondition),
                'dependson' => ['replication', 'poolcondition', 'variant'],
            ],
            // Not one seed but one per twin and item; this one fingerprints the domain for the replication.
            self::DOMAIN_RESPONSE_CRN     => [
                'seed'      => self::derive($master, self::DOMAIN_RESPONSE_CRN, ['rep' => $replication]),
                'dependson' => ['replication', 'twinid', 'itemkey', 'presentation'],
                'schema'    => self::RNG_SCHEMA,
            ],
        ];
    }
}
