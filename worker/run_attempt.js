/**
 * Puppeteer worker stub for local_catquizlab.
 *
 * Final responsibility (backlog E3.3): log in as the simulated user, start the
 * adaptivequiz attempt, read each presented question from the DOM, fetch the
 * answer decision from the plugin's oracle web service, set and submit it,
 * loop until the engine stops the attempt, then report status and timing back.
 *
 * Stub scope: argument parsing and environment validation only — proves the
 * worker can be invoked by the plugin's ad-hoc task and that the toolchain
 * (Node >= 20, Puppeteer) is in place. It performs NO browser actions yet.
 *
 * Invocation (as the ad-hoc task will call it):
 *   node run_attempt.js --base-url=<wwwroot> --run-id=<id> --token=<wstoken>
 */

const args = Object.fromEntries(
    process.argv.slice(2)
        .filter((a) => a.startsWith('--'))
        .map((a) => {
            const [key, ...rest] = a.replace(/^--/, '').split('=');
            return [key, rest.join('=')];
        })
);

const required = ['base-url', 'run-id', 'token'];
const missing = required.filter((key) => !args[key]);

if (missing.length > 0) {
    console.error(`[catquizlab-worker] missing argument(s): ${missing.map((m) => '--' + m).join(', ')}`);
    process.exit(2);
}

console.log('[catquizlab-worker] stub OK — would run attempt', {
    baseUrl: args['base-url'],
    runId: args['run-id'],
    token: '***',
});
process.exit(0);
