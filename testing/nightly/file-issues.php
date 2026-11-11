<?php
/**
 * Nightly reporting for HAXiam (plain PHP, no AI).
 *
 * Writes a job summary from testing/nightly/flows.php's report and, when the
 * HAX_ISSUES_TOKEN secret is set (Issues: read and write on haxtheweb/issues),
 * opens or comments on one `[nightly] haxiam: <check>` issue per failing check
 * there, the same way the fuzz nightlies do. Without the token it only notes
 * that filing was skipped; the failing workflow step is what turns the run red.
 *
 * Usage: php file-issues.php [--report report.json] [--failed-step <name>]...
 *          [--scope haxcms-php]
 *
 * --scope names the backend a repo's own nightly checked, so its issues read
 * `[nightly] haxiam: <scope> <check>`.
 */
const ISSUES_REPO = 'haxtheweb/issues';
const PREFIX = '[nightly] haxiam: ';

$opts = getopt('', array('report:', 'failed-step:', 'scope:'));
$scope = isset($opts['scope']) ? trim($opts['scope']) : '';
$failures = array();
if (isset($opts['report']) && is_file($opts['report'])) {
    $report = json_decode((string) file_get_contents($opts['report']), true);
    if (is_array($report) && isset($report['checks'])) {
        foreach ($report['checks'] as $name => $c) {
            if (!empty($c['failures'])) {
                $failures[$name] = $c['failures'];
            }
        }
    }
}
$steps = isset($opts['failed-step']) ? (array) $opts['failed-step'] : array();
foreach ($steps as $step) {
    if ($step !== '' && !isset($failures[$step])) {
        $failures[$step] = array("The `$step` step failed; see the run log.");
    }
}

$summary = getenv('GITHUB_STEP_SUMMARY');
function say($line)
{
    global $summary;
    echo $line . "\n";
    if ($summary) {
        file_put_contents($summary, $line . "\n", FILE_APPEND);
    }
}

$server = getenv('GITHUB_SERVER_URL') ?: 'https://github.com';
$repo = getenv('GITHUB_REPOSITORY') ?: 'haxtheweb/haxiam';
$runUrl = getenv('GITHUB_RUN_ID') ? "$server/$repo/actions/runs/" . getenv('GITHUB_RUN_ID') : '';
$sha = getenv('GITHUB_SHA') ?: 'unknown';

say('## HAXiam nightly' . ($scope !== '' ? " ($scope)" : ''));
say('');
if (!count($failures)) {
    say('All checks passed against a throwaway install with the mock identity provider.');
    exit(0);
}
foreach ($failures as $check => $list) {
    say("- **$check**");
    foreach ($list as $f) {
        say('  - ' . str_replace(array("\r", "\n"), ' ', $f));
    }
}
say('');

function github($token, $method, $path, $body = null)
{
    $ch = curl_init('https://api.github.com' . $path);
    $headers = array(
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: haxiam-nightly',
    );
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ));
    $text = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($text === false || $code < 200 || $code > 299) {
        throw new RuntimeException("$method $path -> $code: " . substr((string) $text, 0, 300));
    }
    return $text === '' ? array() : json_decode($text, true);
}

$token = getenv('HAX_ISSUES_TOKEN') ?: '';
if ($token === '') {
    say('Issue filing skipped: the `HAX_ISSUES_TOKEN` secret is not set here (it needs Issues: read and write on ' . ISSUES_REPO . ').');
    exit(0);
}
try {
    $q = 'repo:' . ISSUES_REPO . ' is:issue is:open in:title "' . PREFIX . '"';
    $search = github($token, 'GET', '/search/issues?per_page=100&q=' . rawurlencode($q));
    $open = isset($search['items']) ? $search['items'] : array();
} catch (Throwable $e) {
    say('- Searching for existing issues failed: `' . $e->getMessage() . '`');
    exit(0);
}
foreach ($failures as $check => $list) {
    $title = PREFIX . ($scope !== '' ? $scope . ' ' : '') . $check;
    $body = implode("\n", array(
        'The HAXiam nightly failed this check against a throwaway CI install (mock identity provider, no live deployment).',
        '',
        '- Check: `' . $check . '`',
        '- Commit: `' . $sha . '`',
        $runUrl !== '' ? '- Run: ' . $runUrl : '',
        '',
        'Failures:',
        '',
        '```text',
        implode("\n", $list),
        '```',
        '',
        'Reproduce on a scratch machine: `sudo -E env HAXIAM_NIGHTLY_ALLOW_LOCAL=1 bash testing/nightly/boot.sh && sudo php testing/nightly/flows.php`',
    ));
    try {
        $existing = null;
        foreach ($open as $item) {
            if (isset($item['title']) && $item['title'] === $title) {
                $existing = $item;
                break;
            }
        }
        if ($existing) {
            github($token, 'POST', '/repos/' . ISSUES_REPO . '/issues/' . $existing['number'] . '/comments', array('body' => $body));
            say("- Commented on {$existing['html_url']} for `$check`");
        } else {
            $created = github($token, 'POST', '/repos/' . ISSUES_REPO . '/issues', array(
                'title' => $title,
                'body' => $body . "\n\nTracking: https://github.com/haxtheweb/issues/issues/3262",
            ));
            say("- Opened {$created['html_url']} for `$check`");
        }
    } catch (Throwable $e) {
        say("- Filing the issue for `$check` failed: `" . $e->getMessage() . '`');
    }
}
exit(0);
