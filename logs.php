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
 * Step 5: what happened, in order, from every source at once.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\log_view;
use local_catquizlab\local\registry;

admin_externalpage_setup(registry::ADMIN_PAGE);

$context = context_system::instance();
$component = 'local_catquizlab';
$pageurl = new moodle_url('/local/catquizlab/logs.php');
$PAGE->set_url($pageurl);

require_capability('local/catquizlab:view', $context);

$filter = [
    'experimentid' => optional_param('experimentid', 0, PARAM_INT),
    'runid'        => optional_param('runid', 0, PARAM_INT),
    'channel'      => optional_param('channel', '', PARAM_ALPHA),
    'search'       => optional_param('search', '', PARAM_TEXT),
    'hours'        => optional_param('hours', 24, PARAM_INT),
    // The filters the stored fields can actually answer. What is not here is
    // not coyness: the log tables have no millisecond column and no worker or
    // task id of their own, so a filter for those would be a promise the data
    // cannot keep.
    'from'          => optional_param('from', '', PARAM_TEXT),
    'to'            => optional_param('to', '', PARAM_TEXT),
    'severity'      => optional_param('severity', '', PARAM_ALPHA),
    'action'        => optional_param('logaction', '', PARAM_TEXT),
    'correlationid' => optional_param('correlationid', '', PARAM_ALPHANUMEXT),
    'attemptno'     => optional_param('attemptno', 0, PARAM_INT),
    'userid'        => optional_param('userid', 0, PARAM_INT),
    'newestfirst'   => optional_param('newestfirst', 0, PARAM_BOOL),
];

// An explicit window wins over "the last N hours": somebody who knows when it
// happened should not have to convert that into hours ago.
$from = strtotime((string) $filter['from']) ?: 0;
$to = strtotime((string) $filter['to']) ?: 0;

if ($from > 0) {
    $filter['since'] = $from;
    if ($to > 0) {
        $filter['until'] = $to;
    }
} else if ($filter['hours'] > 0) {
    $filter['since'] = time() - ($filter['hours'] * HOURSECS);
}

// The filtered selection as a file, for when it is too long to select by hand.
// The same filtered lines as JSON, for a ticket, a script or a spreadsheet.
// Text is for reading; this is for anything that has to process it.
if (optional_param('downloadjson', 0, PARAM_BOOL)) {
    require_sesskey();

    send_file(
        json_encode([
            'generated' => time(),
            'filter'    => array_filter($filter, static function ($value): bool {
                return $value !== '' && $value !== 0 && $value !== null;
            }),
            'lines'     => log_view::lines($filter, 5000),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'catquizlab-log-' . date('Ymd-His') . '.json',
        0,
        0,
        true,
        true,
        'application/json'
    );
    exit;
}

if (optional_param('download', 0, PARAM_BOOL)) {
    require_sesskey();

    send_file(
        log_view::as_text($filter, 5000),
        'catquizlab-log-' . date('Ymd-His') . '.txt',
        0,
        0,
        true,
        true,
        'text/plain'
    );
    exit;
}

$lines = log_view::lines($filter);

echo $OUTPUT->header();
echo \local_catquizlab\output\shell::render('logs', (int) $filter['experimentid']);

echo $OUTPUT->heading(get_string('logs:heading', $component), 3);
echo html_writer::tag('p', get_string('logs:explain', $component), ['class' => 'text-muted']);

// The filters, as a plain GET form: a filtered log is a thing people link to.
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out(false), 'class' => 'form-inline mb-3']);

echo html_writer::label(get_string('logs:hours', $component), 'catquizlab-hours', true, ['class' => 'mr-2']);
echo html_writer::select(
    [1 => '1', 6 => '6', 24 => '24', 168 => '168', 0 => get_string('logs:allhours', $component)],
    'hours',
    $filter['hours'],
    false,
    ['id' => 'catquizlab-hours', 'class' => 'custom-select mr-3']
);

echo html_writer::label(get_string('debug:channel', $component), 'catquizlab-channel', true, ['class' => 'mr-2']);
echo html_writer::select(
    [
        ''          => get_string('logs:allchannels', $component),
        'ui'        => 'ui',
        'lifecycle' => 'lifecycle',
        'task'      => 'task',
        'service'   => 'service',
        'worker'    => 'worker',
    ],
    'channel',
    $filter['channel'],
    false,
    ['id' => 'catquizlab-channel', 'class' => 'custom-select mr-3']
);

echo html_writer::label(get_string('logs:run', $component), 'catquizlab-runid', true, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'runid', 'id' => 'catquizlab-runid',
    'value' => $filter['runid'] ?: '', 'class' => 'form-control mr-3', 'style' => 'width: 7rem;',
]);

echo html_writer::label(get_string('logs:search', $component), 'catquizlab-search', true, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'search', 'name' => 'search', 'id' => 'catquizlab-search',
    'value' => $filter['search'], 'class' => 'form-control mr-3',
]);

// The second row of the filter: a window with two ends, a level, and the
// identifiers a support thread is usually about.
// A full-width spacer: the filter row wraps here rather than running off the
// side of the page.
echo html_writer::div('', 'w-100 mb-2');

$fields = [
    'from'          => ['logs:from', 'datetime-local'],
    'to'            => ['logs:to', 'datetime-local'],
    'logaction'     => ['logs:action', 'text'],
    'correlationid' => ['logs:correlationid', 'text'],
];
foreach ($fields as $name => [$label, $type]) {
    $value = $name === 'logaction' ? $filter['action'] : ($filter[$name] ?? '');
    echo html_writer::label(get_string($label, $component), 'catquizlab-' . $name, true, ['class' => 'mr-2']);
    echo html_writer::empty_tag('input', [
        'type' => $type, 'name' => $name, 'id' => 'catquizlab-' . $name,
        'value' => $value, 'class' => 'form-control mr-3',
    ]);
}

$levels = [];
foreach (log_view::SEVERITIES as $level) {
    $levels[$level] = get_string('severity:' . $level, $component);
}
echo html_writer::label(get_string('logs:severity', $component), 'catquizlab-severity', true, ['class' => 'mr-2']);
echo html_writer::select(
    $levels,
    'severity',
    $filter['severity'],
    ['' => get_string('logs:anyseverity', $component)],
    ['id' => 'catquizlab-severity', 'class' => 'custom-select mr-3']
);

echo html_writer::checkbox(
    'newestfirst',
    1,
    !empty($filter['newestfirst']),
    get_string('logs:newestfirst', $component),
    ['id' => 'catquizlab-newestfirst', 'class' => 'mr-3']
);

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('logs:apply', $component),
]);
echo html_writer::end_tag('form');

if ($lines === []) {
    // When the last thing happened, and a way to see it: "nothing recorded in
    // this window" on a one-hour window read as "nothing is recorded at all".
    $latest = log_view::latest();
    $message = get_string('logs:empty', $component);
    if ($latest > 0) {
        $message .= ' ' . get_string('logs:latest', $component, (object) [
            'when' => userdate($latest),
            'ago'  => \local_catquizlab\local\duration::ago($latest),
        ]) . ' ' . html_writer::link(
            new moodle_url($pageurl, ['hours' => 0] + array_filter($filter, static function ($v): bool {
                return $v !== '' && $v !== 0 && $v !== null;
            })),
            get_string('logs:showall', $component)
        );
    }
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::tag('p', get_string('logs:count', $component, count($lines)), ['class' => 'small text-muted']);

    // One <pre> holding the whole selection. Selecting a table gives somebody
    // the markup around the text; this gives them the text, which is what they
    // are about to paste into a support thread.
    echo html_writer::tag(
        'pre',
        s(log_view::as_text($filter)),
        [
            'class' => 'border rounded p-3 small',
            'style' => 'max-height: 34rem; overflow: auto; white-space: pre;',
            'data-region' => 'catquizlab-log',
        ]
    );

    echo html_writer::div(
        $OUTPUT->single_button(
            new moodle_url($pageurl, $filter + ['download' => 1, 'sesskey' => sesskey()]),
            get_string('logs:download', $component),
            'post'
        )
        . $OUTPUT->single_button(
            new moodle_url($pageurl, $filter + ['downloadjson' => 1, 'sesskey' => sesskey()]),
            get_string('logs:downloadjson', $component),
            'post'
        )
        . html_writer::tag(
            'button',
            get_string('logs:copy', $component),
            [
                'type' => 'button',
                'class' => 'btn btn-secondary ml-1',
                'data-action' => 'catquizlab-copy-log',
            ]
        ),
        'd-flex flex-wrap align-items-start'
    );

    // Selecting thirty screens of text with a mouse is not a reasonable ask of
    // somebody who is already having a bad day.
    $PAGE->requires->js_amd_inline(<<<'JS'
        require(['core/notification'], function(notification) {
            var button = document.querySelector('[data-action="catquizlab-copy-log"]');
            var log = document.querySelector('[data-region="catquizlab-log"]');
            if (!button || !log) {
                return;
            }
            button.addEventListener('click', function() {
                navigator.clipboard.writeText(log.textContent).then(function() {
                    button.classList.add('btn-success');
                }).catch(function(error) {
                    notification.exception(error);
                });
            });
        });
JS);
}

echo $OUTPUT->footer();
