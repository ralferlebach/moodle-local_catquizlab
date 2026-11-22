<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab\local;

/**
 * The data dictionary of the research data package (#156 AK2, AK7): every table, its key and joins, every column
 * with its type, unit and meaning, in English and German.
 *
 * Types: integer, number (decimal point, NaN/Inf written as such), string, boolean (0/1), timestamp (Unix seconds,
 * UTC), json (a JSON value in one cell), list (values separated by ";"). An empty cell is a missing value (NA): never
 * a zero. Kept in code, not in language strings, because it is part of the package's schema and versioned with it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class research_codebook {
    /**
     * The tables, in the order the package writes them.
     *
     * @return array<string, array{key: string[], joins: array<string, string>, en: string, de: string}>
     */
    public static function tables(): array {
        return [
            'experiments' => ['key' => ['experimentid'], 'joins' => [],
                'en' => 'The experiment: its current name, the hash of its design, its definition as stored.',
                'de' => 'Das Experiment: aktueller Name, Hash seines Designs, Definition wie gespeichert.'],
            'runs' => ['key' => ['runid'], 'joins' => ['experimentid' => 'experiments.experimentid'],
                'en' => 'One row per run of the selection (a sweep cell × replication) with its design coordinates, seeds, '
                    . 'RNG schema and pool fingerprint.',
                'de' => 'Eine Zeile je Lauf der Auswahl (Sweep-Zelle × Replikation) mit Designkoordinaten, Seeds, '
                    . 'RNG-Schema und Pool-Fingerprint.'],
            'items' => ['key' => ['runid', 'questionid'], 'joins' => ['runid' => 'runs.runid'],
                'en' => 'Every generated item of every run: true parameters and placement beside what the engine was told, '
                    . 'and the canonical key that is the same in every run of a replication.',
                'de' => 'Jedes erzeugte Item jedes Laufs: wahre Parameter und Platzierung neben dem, was der Engine gesagt '
                    . 'wurde, und der kanonische Schlüssel, der in jedem Lauf einer Replikation gleich ist.'],
            'twins' => ['key' => ['runid', 'personid'], 'joins' => ['runid' => 'runs.runid'],
                'en' => 'Every simulated person (digital twin) of every run, with or without a sitting.',
                'de' => 'Jede simulierte Person (digitaler Twin) jedes Laufs, mit oder ohne Testbearbeitung.'],
            'truth_profiles' => ['key' => ['runid', 'personid', 'level', 'categoryindex', 'subscaleindex'],
                'joins' => ['runid, personid' => 'twins.runid, twins.personid'],
                'en' => 'The true ability profile of every twin in long form: one global row, one per category, one per '
                    . 'subscale.',
                'de' => 'Das wahre Fähigkeitsprofil jedes Twins im Langformat: eine globale Zeile, eine je Kategorie, eine je '
                    . 'Subskala.'],
            'twin_item_responses' => ['key' => ['runid', 'personid', 'itemkey'],
                'joins' => ['runid, personid' => 'twins.runid, twins.personid', 'runid, questionid' =>
                    'items.runid, items.questionid'],
                'en' => 'POTENTIAL responses: the answer every twin would give to every item of its run\'s pool, computed '
                    . 'by the function that answers in a sitting. Not interactions: most were never administered. Only '
                    . 'runs under RNG schema 2; for others the table has no rows. Never mix with steps without saying so.',
                'de' => 'POTENZIELLE Antworten: die Antwort, die jeder Twin auf jedes Item des Pools seines Laufs gäbe, '
                    . 'berechnet mit der Funktion, die in einer Testbearbeitung antwortet. Keine Interaktionen: die '
                    . 'meisten wurden nie gestellt. Nur Läufe unter RNG-Schema 2; für andere hat die Tabelle keine Zeilen. '
                    . 'Nie ungekennzeichnet mit steps mischen.'],
            'attempts' => ['key' => ['attemptid'], 'joins' => ['runid' => 'runs.runid', 'runid, personid' =>
                'twins.runid, twins.personid'],
                'en' => 'Every sitting with a trace, valid, invalid or unknown: final estimate, error, length, how it '
                    . 'ended and whether it may go into the figures. Retries of a sitting are one row.',
                'de' => 'Jede Testbearbeitung mit Trace, gültig, ungültig oder unbekannt: finale Schätzung, Fehler, Länge, '
                    . 'wie sie endete und ob sie in die Kennzahlen darf. Wiederholungen einer Testbearbeitung sind eine '
                    . 'Zeile.'],
            'validity' => ['key' => ['attemptid'], 'joins' => ['attemptid' => 'attempts.attemptid'],
                'en' => 'The validity verdicts of every sitting, apart: the rule in force, the statistical rules, the '
                    . 'engine\'s own verdict, how it ended.',
                'de' => 'Die Gültigkeitsurteile jeder Testbearbeitung, getrennt: geltende Regel, statistische Regeln, '
                    . 'Urteil der Engine, wie sie endete.'],
            'analysis_population' => ['key' => ['attemptid'], 'joins' => ['attemptid' => 'attempts.attemptid'],
                'en' => 'Which sittings the results page counted under the filter the package was taken with, and why '
                    . 'the others not.',
                'de' => 'Welche Testbearbeitungen die Ergebnisseite unter dem Filter zählte, mit dem das Paket erstellt '
                    . 'wurde, und warum die übrigen nicht.'],
            'steps' => ['key' => ['attemptid', 'step'], 'joins' => ['attemptid' => 'attempts.attemptid',
                'runid, questionid' => 'items.runid, items.questionid'],
                'en' => 'The ADMINISTERED responses: every question a sitting was really given, in order, with the score '
                    . 'the engine recorded and the estimate after it.',
                'de' => 'Die GESTELLTEN Antworten: jede Frage, die eine Testbearbeitung wirklich bekam, in Reihenfolge, '
                    . 'mit der von der Engine aufgezeichneten Bewertung und der Schätzung danach.'],
            'estimated_profiles' => ['key' => ['attemptid', 'category', 'subscale'],
                'joins' => ['attemptid' => 'attempts.attemptid'],
                'en' => 'The estimated ability per subscale of every sitting, with its local standard error and whether '
                    . 'that scale result may go into the figures.',
                'de' => 'Die geschätzte Fähigkeit je Subskala jeder Testbearbeitung, mit lokalem Standardfehler und ob '
                    . 'dieses Skalenergebnis in die Kennzahlen darf.'],
            'scale_maps' => ['key' => ['runid', 'catscaleid'], 'joins' => ['runid' => 'runs.runid',
                'runid, catscaleid' => 'items.runid, items.assignedcatscaleid'],
                'en' => 'Which engine scale is which scale of the design, per run: joins an administered item to its '
                    . 'subscale, also where the engine gave that subscale no estimate.',
                'de' => 'Welche Engine-Skala welche Skala des Designs ist, je Lauf: verbindet ein gestelltes Item mit '
                    . 'seiner Subskala, auch wo die Engine für die Subskala keine Schätzung gab.'],
        ];
    }

    /**
     * Every column of every table: type, unit and meaning.
     *
     * @return array<string, array{type: string, unit: string, en: string, de: string}>
     */
    public static function columns(): array {
        $c = static fn(string $type, string $en, string $de, string $unit = ''): array =>
            ['type' => $type, 'unit' => $unit, 'en' => $en, 'de' => $de];
        $theta = 'logit';

        return [
            // Keys.
            'experimentid' => $c('integer', 'Experiment id.', 'Experiment-ID.'),
            'runid' => $c('integer', 'Run id.', 'Lauf-ID.'),
            'personid' => $c(
                'integer',
                'Person row of the run: the twin as the run materialised it.',
                'Personenzeile des Laufs: der Twin, wie der Lauf ihn angelegt hat.'
            ),
            'twinid' => $c(
                'string',
                'Canonical twin: the same simulated person in every run of a replication.',
                'Kanonischer Twin: dieselbe simulierte Person in jedem Lauf einer Replikation.'
            ),
            'attemptid' => $c(
                'integer',
                'Sitting id (the lab\'s attempt row).',
                'ID der Testbearbeitung (Versuchszeile des Labors).'
            ),
            'questionid' => $c(
                'integer',
                'Moodle question id: different in every run.',
                'Moodle-Frage-ID: in jedem Lauf eine andere.'
            ),
            'itemkey' => $c('string', 'Canonical item key r<replication>-<model>-c<category>-s<subscale>-i<index>: the same item '
                . 'in every run of a replication. Empty for runs before 0.7.61.', 'Kanonischer Itemschlüssel '
                . 'r<Replikation>-<Modell>-c<Kategorie>-s<Subskala>-i<Index>: dasselbe Item in jedem Lauf einer Replikation. '
                . 'Leer für Läufe vor 0.7.61.'),
            'replication' => $c('integer', 'Replication number.', 'Nummer der Replikation.'),
            'step' => $c(
                'integer',
                'Position of the question in the sitting, from 1.',
                'Position der Frage in der Testbearbeitung, ab 1.'
            ),
            // Experiments.
            'name' => $c('string', 'Current name (a rename never changes the design).', 'Aktueller Name (eine Umbenennung ändert '
                . 'nie das Design).'),
            'designhash' => $c('string', 'sha256 of the normalised definition without name and description; empty where the '
                . 'definition cannot be read.', 'sha256 der normalisierten Definition ohne Name und Beschreibung; leer, wenn die '
                . 'Definition nicht lesbar ist.'),
            'definitionsha256' => $c(
                'string',
                'sha256 of the stored definition text.',
                'sha256 des gespeicherten Definitionstexts.'
            ),
            'runs' => $c('integer', 'Runs of this experiment in the package.', 'Läufe dieses Experiments im Paket.'),
            // Runs.
            'cellkey' => $c('string', 'Sweep cell: the factor values of the run.', 'Sweep-Zelle: die Faktorwerte des Laufs.'),
            'tier' => $c('string', 'Experiment tier.', 'Stufe des Experiments.'),
            'strategy' => $c('string', 'CAT strategy key.', 'Schlüssel der CAT-Strategie.'),
            'model' => $c('string', 'IRT model key.', 'Schlüssel des IRT-Modells.'),
            'variant' => $c('string', 'Pool variant (ideal, shifted, miscalibrated, …).', 'Poolvariante (ideal, verschoben, '
                . 'fehlkalibriert, …).'),
            'strength' => $c(
                'number',
                'Strength of the pool disturbance, from variant and recipe; empty for the ideal pool.',
                'Stärke der Poolstörung, aus Variante und Rezept; leer beim idealen Pool.'
            ),
            'stratum' => $c('string', 'Person stratum.', 'Personenstratum.'),
            'severity' => $c('string', 'Strength of the persons\' systematic deviance.', 'Stärke der systematischen Abweichung der '
                . 'Personen.'),
            'status' => $c('string', 'Run status at the time of the export.', 'Status des Laufs zum Zeitpunkt des Exports.'),
            'masterseed' => $c('integer', 'Master seed of the experiment.', 'Master-Seed des Experiments.'),
            'seed' => $c('integer', 'Seed of the run.', 'Seed des Laufs.'),
            'rngschema' => $c('integer', 'How answers were drawn: 2 = common random numbers per replication, twin and item '
                . '(since 0.7.61); 1 = per run, person row and question.', 'Wie Antworten gezogen wurden: 2 = gemeinsame '
                . 'Zufallszahlen je Replikation, Twin und Item (seit 0.7.61); 1 = je Lauf, Personenzeile und Frage.'),
            'itemidentityschema' => $c(
                'integer',
                '1 where the run\'s items have keys and fingerprints, 0 before 0.7.61.',
                '1, wenn die Items des Laufs Schlüssel und Fingerprints haben, 0 vor 0.7.61.'
            ),
            'poolfingerprint' => $c('string', 'sha256 over the sorted lines "itemkey fingerprint" of the run\'s pool; equal in '
                . 'every run that must share the pool.', 'sha256 über die sortierten Zeilen „itemkey fingerprint“ des Pools; '
                . 'gleich in jedem Lauf, der den Pool teilen muss.'),
            'poolitems' => $c('integer', 'Items in the run\'s pool.', 'Items im Pool des Laufs.'),
            'manifestsha256' => $c(
                'string',
                'sha256 of the run\'s manifest (its full record in reproducibility.json).',
                'sha256 des Manifests des Laufs (vollständig in reproducibility.json).'
            ),
            'persons' => $c('integer', 'Twins materialised in the run.', 'Im Lauf angelegte Twins.'),
            'sittings' => $c('integer', 'Sitting rows of the run, whatever their state.', 'Zeilen von Testbearbeitungen des Laufs, '
                . 'gleich welcher Zustand.'),
            'sittingswithtrace' => $c('integer', 'Sittings with a trace: the rows of attempts.', 'Testbearbeitungen mit Trace: die '
                . 'Zeilen in attempts.'),
            'sittingsfailed' => $c('integer', 'Sittings failed for good (no trace): a technical end.', 'Endgültig gescheiterte '
                . 'Testbearbeitungen (ohne Trace): ein technisches Ende.'),
            'sittingscancelled' => $c('integer', 'Sittings closed by an operator when the experiment or the run was stopped '
                . '(#166): neither a failure nor a result.', 'Testbearbeitungen, die beim Stoppen des Experiments oder des Laufs '
                . 'von Hand geschlossen wurden (#166): weder Fehlschlag noch Ergebnis.'),
            'setarget' => $c('number', 'Precision target: the standard error at which the engine may stop.', 'Präzisionsziel: '
                . 'der Standardfehler, bei dem die Engine stoppen darf.', $theta),
            'globalminitems' => $c('integer', 'Least questions a sitting must answer.', 'Mindestzahl der Fragen einer '
                . 'Testbearbeitung.'),
            'globalmaxitems' => $c('integer', 'Most questions a sitting may answer.', 'Höchstzahl der Fragen einer '
                . 'Testbearbeitung.'),
            'subscalemaps' => $c(
                'integer',
                'Subscales the run maps to engine scales; 0: no subscale results.',
                'Subskalen, die der Lauf Engine-Skalen zuordnet; 0: keine Subskalenergebnisse.'
            ),
            // Items.
            'itemname' => $c('string', 'Item name in the blueprint.', 'Itemname im Blueprint.'),
            'truedifficulty' => $c('number', 'True difficulty b.', 'Wahre Schwierigkeit b.', $theta),
            'storeddifficulty' => $c('number', 'Difficulty the engine was given (differs from the true one under a calibration '
                . 'error).', 'Schwierigkeit, die der Engine gegeben wurde (weicht bei Kalibrierungsfehler ab).', $theta),
            'discrimination' => $c('number', 'Discrimination a.', 'Trennschärfe a.'),
            'guessing' => $c('number', 'Guessing c.', 'Rateparameter c.'),
            'truecategory' => $c('integer', 'True category index.', 'Wahrer Kategorieindex.'),
            'truesubscale' => $c('integer', 'True subscale index.', 'Wahrer Subskalenindex.'),
            'truecatscaleid' => $c('integer', 'Engine scale of the true placement.', 'Engine-Skala der wahren Platzierung.'),
            'assignedcatscaleid' => $c(
                'integer',
                'Engine scale the item was assigned to (differs under a tagging error).',
                'Engine-Skala, der das Item zugeordnet wurde (weicht bei Zuordnungsfehler ab).'
            ),
            'miscalibrated' => $c('boolean', 'Calibration error injected.', 'Kalibrierungsfehler eingebaut.'),
            'mistagged' => $c('boolean', 'Tagging error injected.', 'Zuordnungsfehler eingebaut.'),
            'poolvariant' => $c('string', 'Pool variant of the run.', 'Poolvariante des Laufs.'),
            'contentfp' => $c(
                'string',
                'sha256 of the item as generated (key, name, model, true parameters, placement).',
                'sha256 des Items wie erzeugt (Schlüssel, Name, Modell, wahre Parameter, Platzierung).'
            ),
            'fingerprint' => $c('string', 'sha256 of the item as realised (content, stored parameters, assigned placement, '
                . 'error flags, name, text, options).', 'sha256 des Items wie realisiert (Inhalt, gespeicherte Parameter, '
                . 'zugewiesene Platzierung, Fehlerflags, Name, Text, Optionen).'),
            // Twins and truth.
            'twinindex' => $c('integer', 'Index of the twin within its replication.', 'Index des Twins in seiner Replikation.'),
            'abilityglobal' => $c('number', 'True global ability.', 'Wahre globale Fähigkeit.', $theta),
            'deviancemagnitude' => $c('number', 'Systematic deviance added to the true ability on the targeted subscales '
                . '(positive = underperformance); empty without deviance.', 'Systematische Abweichung auf den betroffenen '
                . 'Subskalen (positiv = Minderleistung); leer ohne Abweichung.', $theta),
            'deviancesubscales' => $c('list', 'Targeted subscales as category:subscale; empty = all scales when a magnitude is '
                . 'given.', 'Betroffene Subskalen als Kategorie:Subskala; leer = alle Skalen, wenn eine Stärke angegeben ist.'),
            'person' => $c('string', 'Label of the person.', 'Bezeichnung der Person.'),
            'level' => $c('string', 'global, category or subscale.', 'global, category (Kategorie) oder subscale (Subskala).'),
            'categoryindex' => $c('integer', 'Category index; empty on the global row.', 'Kategorieindex; leer in der globalen '
                . 'Zeile.'),
            'subscaleindex' => $c('integer', 'Subscale index; empty on global and category rows.', 'Subskalenindex; leer in '
                . 'globalen und Kategoriezeilen.'),
            'theta' => $c('number', 'True ability at that level.', 'Wahre Fähigkeit auf dieser Ebene.', $theta),
            // Potential and administered responses.
            'rngseed' => $c('integer', 'Seed the answer is drawn from.', 'Seed, aus dem die Antwort gezogen wird.'),
            'uniform' => $c('number', 'The common random number u in [0, 1) from the seed.', 'Die gemeinsame Zufallszahl u in '
                . '[0, 1) aus dem Seed.'),
            'trueability' => $c(
                'number',
                'True ability on the item\'s true subscale, deviance included.',
                'Wahre Fähigkeit auf der '
                . 'wahren Subskala des Items, inklusive Abweichung.',
                $theta
            ),
            'trueprobs' => $c('list', 'True category probabilities P(0);P(1);…. Dichotomous: correct when u < P(1). Polytomous: '
                . 'the smallest k with u < P(0)+…+P(k).', 'Wahre Kategorienwahrscheinlichkeiten P(0);P(1);…. Dichotom: richtig, '
                . 'wenn u < P(1). Polytom: kleinstes k mit u < P(0)+…+P(k).'),
            'responsecategory' => $c('integer', 'Category drawn (dichotomous: 1 correct, 0 wrong).', 'Gezogene Kategorie '
                . '(dichotom: 1 richtig, 0 falsch).'),
            'potentialfraction' => $c('number', 'Score fraction of the drawn category, in [0, 1].', 'Score-Fraction der '
                . 'gezogenen Kategorie, in [0, 1].'),
            'fraction' => $c('number', 'Share of credit the engine counts on the scale\'s answered items, in [0, 1].', 'Anteil '
                . 'der Punkte, den die Engine auf den beantworteten Items der Skala zählt, in [0, 1].'),
            'oraclefraction' => $c('number', 'Score fraction of the drawn category; equals score where the engine recorded what '
                . 'was drawn.', 'Score-Fraction der gezogenen Kategorie; gleich score, wenn die Engine aufzeichnete, was '
                . 'gezogen wurde.'),
            // Attempts.
            'truetheta' => $c('number', 'True ability (attempts: global; estimated_profiles: the subscale\'s).', 'Wahre Fähigkeit '
                . '(attempts: global; estimated_profiles: die der Subskala).', $theta),
            'esttheta' => $c('number', 'Estimated ability (attempts: final global; steps: after the step; estimated_profiles: the '
                . 'subscale\'s).', 'Geschätzte Fähigkeit (attempts: final global; steps: nach dem Schritt; estimated_profiles: '
                . 'die der Subskala).', $theta),
            'error' => $c('number', 'attempts: esttheta − truetheta. estimated_profiles: estdelta − truedelta.', 'attempts: '
                . 'esttheta − truetheta. estimated_profiles: estdelta − truedelta.', $theta),
            'nitems' => $c('integer', 'Questions answered in the sitting.', 'In der Testbearbeitung beantwortete Fragen.'),
            'se' => $c(
                'number',
                'Standard error (attempts: final; steps: after the step, from the test information).',
                'Standardfehler (attempts: final; steps: nach dem Schritt, aus der Testinformation).',
                $theta
            ),
            'stopreason' => $c('string', 'Stop reason as the engine reported it.', 'Stoppgrund, wie die Engine ihn meldete.'),
            'stopreached' => $c('boolean', 'A stop criterion of the design was reached.', 'Ein Stoppkriterium des Designs wurde '
                . 'erreicht.'),
            'runtimems' => $c('integer', 'Duration of the collected execution.', 'Dauer der gesammelten Ausführung.', 'ms'),
            'endreasoncode' => $c('string', 'Normalised end of the sitting.', 'Normalisiertes Ende der Testbearbeitung.'),
            'endreasonlabel' => $c('string', 'End of the sitting in words.', 'Ende der Testbearbeitung in Worten.'),
            'finalti' => $c('number', 'Test information of the items played, at the end.', 'Testinformation der gespielten Items '
                . 'am Ende.'),
            'activescalesatend' => $c('integer', 'Scales still active at the end.', 'Am Ende noch aktive Skalen.'),
            'abilitydistribution' => $c('string', 'Distribution the true abilities were drawn from.', 'Verteilung, aus der die '
                . 'wahren Fähigkeiten gezogen wurden.'),
            'abilitymean' => $c('number', 'Its mean.', 'Ihr Mittelwert.', $theta),
            'abilitysd' => $c('number', 'Its standard deviation.', 'Ihre Standardabweichung.', $theta),
            'abilitymin' => $c('number', 'Lower end of the scale range.', 'Untere Grenze des Skalenbereichs.', $theta),
            'abilitymax' => $c('number', 'Upper end of the scale range.', 'Obere Grenze des Skalenbereichs.', $theta),
            'finaltiatn' => $c('number', 'TI@n at the end: information of the n most informative pool items at the final '
                . 'estimate, n the test length.', 'TI@n am Ende: Information der n informativsten Poolitems an der finalen '
                . 'Schätzung, n = Testlänge.'),
            'valid' => $c('boolean', 'May go into the figures under the rule in force (estimated_profiles: valid scale result of '
                . 'a valid sitting).', 'Darf unter der geltenden Regel in die Kennzahlen (estimated_profiles: gültiges '
                . 'Skalenergebnis einer gültigen Testbearbeitung).'),
            'validityreason' => $c('string', 'Why not valid; empty where valid.', 'Warum nicht gültig; leer, wenn gültig.'),
            'enginefinished' => $c('boolean', 'The engine finished the sitting.', 'Die Engine hat die Testbearbeitung beendet.'),
            'designstopreached' => $c('boolean', 'It ended as the design planned.', 'Sie endete, wie das Design es vorsah.'),
            'uniformvalid' => $c('boolean', 'Valid by the engine\'s definitions for every strategy.', 'Gültig nach den '
                . 'Definitionen der Engine für jede Strategie.'),
            'enginevalid' => $c('boolean', 'The engine\'s own verdict; empty where it gave none.', 'Das eigene Urteil der Engine; '
                . 'leer, wenn sie keins gab.'),
            'contractviolations' => $c('list', 'Limits of its test the sitting broke, as rule keys.', 'Grenzen ihres Tests, die '
                . 'die Testbearbeitung verletzte, als Regelschlüssel.'),
            'validitystate' => $c('string', 'valid, invalid or unknown under the rule in force (estimated_profiles: of the '
                . 'scale result).', 'valid, invalid oder unknown unter der geltenden Regel (estimated_profiles: des '
                . 'Skalenergebnisses).'),
            'engineverdict' => $c('string', 'The engine\'s verdict in the same three words.', 'Das Urteil der Engine in denselben '
                . 'drei Wörtern.'),
            'engineunknownreason' => $c('string', 'Why the engine gave no verdict.', 'Warum die Engine kein Urteil gab.'),
            'productiveitems' => $c('integer', 'Items the engine counts for its rules (answered, without pilot items).', 'Items, '
                . 'die die Engine für ihre Regeln zählt (beantwortet, ohne Pilotitems).'),
            'engineverdictavailable' => $c('boolean', 'The engine gave a verdict.', 'Die Engine gab ein Urteil.'),
            'statisticalvalid' => $c('boolean', 'The statistical rules alone, apart from how the test ended.', 'Nur die '
                . 'statistischen Regeln, unabhängig vom Testende.'),
            'terminalreasoncode' => $c('string', 'Terminal code from the execution history.', 'Endcode aus der '
                . 'Ausführungshistorie.'),
            'transportevents' => $c('integer', 'Transport failures of executions that were retried.', 'Transportfehler von '
                . 'Ausführungen, die wiederholt wurden.'),
            'recoveredevents' => $c('integer', 'Answer submissions reconciled with the server and continued.', 'Mit dem Server '
                . 'abgeglichene und fortgesetzte Antwortübermittlungen.'),
            'executions' => $c('integer', 'Executions it took.', 'Benötigte Ausführungen.'),
            // Steps.
            'subscaleid' => $c('integer', 'Engine scale the question was played on.', 'Engine-Skala, auf der die Frage gespielt '
                . 'wurde.'),
            'score' => $c('number', 'Score fraction the engine recorded; empty where it recorded none.', 'Von der Engine '
                . 'aufgezeichnete Score-Fraction; leer, wenn sie keine aufzeichnete.'),
            'ti_played' => $c('number', 'Test information of the items played so far.', 'Testinformation der bisher gespielten '
                . 'Items.'),
            'ti_at_n' => $c('number', 'TI@n after the step (where computed).', 'TI@n nach dem Schritt (wo berechnet).'),
            'ti_remaining_min' => $c('number', 'Least information the remaining items could add.', 'Geringste Information, die die '
                . 'übrigen Items hinzufügen könnten.'),
            'ti_remaining_max' => $c('number', 'Most information the remaining items could add.', 'Höchste Information, die die '
                . 'übrigen Items hinzufügen könnten.'),
            'scales_estimated' => $c('integer', 'Scales with an estimate after the step.', 'Skalen mit Schätzung nach dem '
                . 'Schritt.'),
            'scales_active' => $c('integer', 'Scales active after the step.', 'Nach dem Schritt aktive Skalen.'),
            'scales_locked' => $c('integer', 'Scales locked after the step.', 'Nach dem Schritt gesperrte Skalen.'),
            'scales_dropped' => $c('integer', 'Scales dropped after the step.', 'Nach dem Schritt verworfene Skalen.'),
            'slot' => $c('integer', 'The question\'s slot in the sitting\'s question usage.', 'Slot der Frage in der '
                . 'Fragennutzung der Testbearbeitung.'),
            'steptime' => $c('timestamp', 'When the answer was stored.', 'Wann die Antwort gespeichert wurde.', 's'),
            'responsestate' => $c(
                'string',
                'Question state after the answer (gradedright, gradedwrong, gradedpartial, …).',
                'Fragezustand nach der Antwort (gradedright, gradedwrong, gradedpartial, …).'
            ),
            'responsesummary' => $c('string', 'The option answered, as Moodle summarises it.', 'Die gewählte Option, wie Moodle '
                . 'sie zusammenfasst.'),
            // Estimated profiles.
            'category' => $c('integer', 'Category index.', 'Kategorieindex.'),
            'catscaleid' => $c('integer', 'Engine scale (local_catquiz_catscales.id) of the run.', 'Engine-Skala '
                . '(local_catquiz_catscales.id) des Laufs.'),
            'parentcatscaleid' => $c('integer', 'The engine scale above it; empty at the top.', 'Die Engine-Skala '
                . 'darüber; leer an der Spitze.'),
            'nodekey' => $c('string', 'The scale\'s place in the design (c1s2: category 1, subscale 2).', 'Platz der '
                . 'Skala im Design (c1s2: Kategorie 1, Subskala 2).'),
            'subscale' => $c('integer', 'Subscale index.', 'Subskalenindex.'),
            'truedelta' => $c('number', 'True subscale ability − true global ability.', 'Wahre Subskalenfähigkeit − wahre globale '
                . 'Fähigkeit.', $theta),
            'estdelta' => $c('number', 'Estimated subscale ability − estimated global ability.', 'Geschätzte '
                . 'Subskalenfähigkeit − geschätzte globale Fähigkeit.', $theta),
            'localse' => $c(
                'number',
                'Standard error of the subscale estimate.',
                'Standardfehler der Subskalenschätzung.',
                $theta
            ),
            'items' => $c('integer', 'Items administered on the subscale; empty where not known, 0 only where certain.', 'Auf '
                . 'der Subskala gestellte Items; leer, wenn unbekannt, 0 nur, wenn sicher.'),
            'within1se' => $c('boolean', '|error| ≤ 1 standard error.', '|error| ≤ 1 Standardfehler.'),
            'within2se' => $c('boolean', '|error| ≤ 2 standard errors.', '|error| ≤ 2 Standardfehler.'),
            'scalevalid' => $c('boolean', 'Scale result valid by the engine\'s definitions.', 'Skalenergebnis gültig nach den '
                . 'Definitionen der Engine.'),
            'scalereasons' => $c('list', 'Why the scale result is not valid (comma-separated).', 'Warum das Skalenergebnis '
                . 'nicht gültig ist (kommagetrennt).'),
            'enginescalevalid' => $c('boolean', 'The engine\'s verdict on the scale; empty where none.', 'Urteil der Engine über '
                . 'die Skala; leer, wenn keins.'),
            'enginescalereasons' => $c('list', 'The engine\'s own reasons against the scale result, word for word '
                . '(comma-separated; e.g. max_questions_exceeded); empty where none.', 'Die eigenen Gründe der Engine gegen '
                . 'das Skalenergebnis, wörtlich (kommagetrennt; z. B. max_questions_exceeded); leer, wenn keine.'),
            'itemssource' => $c('string', 'Where the item count comes from.', 'Woher die Itemzahl stammt.'),
            'itemsreason' => $c('string', 'Why the item count is not known.', 'Warum die Itemzahl unbekannt ist.'),
            'itemclass' => $c('string', 'Diagnostic class of the item count.', 'Diagnostische Klasse der Itemzahl.'),
            'itemsanswered' => $c('integer', 'Items the engine counts on the scale.', 'Items, die die Engine auf der Skala zählt.'),
            // Analysis population.
            'inpopulation' => $c('boolean', 'Counted by the results page under the package\'s filter.', 'Von der Ergebnisseite '
                . 'unter dem Filter des Pakets gezählt.'),
            'reason' => $c(
                'string',
                'Why not counted (its validity state under the filter\'s validity); empty where counted.',
                'Warum nicht gezählt (Gültigkeitszustand unter der Gültigkeit des Filters); leer, wenn gezählt.'
            ),
        ];
    }
}
