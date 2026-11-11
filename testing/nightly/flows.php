<?php
/**
 * Nightly HAXiam flow checks against the THROWAWAY install that
 * testing/nightly/boot.sh stands up (http://haxiam.test with the mock IdP).
 * Plain PHP + curl, no AI. Never point this at a real deployment: it only
 * accepts a *.test base domain.
 *
 * Usage: php testing/nightly/flows.php [--base haxiam.test] [--report out.json]
 *
 * Every assertion belongs to a named check; the report lists the failures per
 * check so the workflow can file one `[nightly] haxiam: <check>` issue each.
 * Exit 0 when everything passes, 1 otherwise.
 */
$opts = getopt('', array('base:', 'report:', 'root:'));
$base = isset($opts['base']) ? $opts['base'] : 'haxiam.test';
$reportPath = isset($opts['report']) ? $opts['report'] : '';
$root = isset($opts['root']) ? $opts['root'] : '/var/www/iam';
if (!preg_match('/^[a-z0-9.-]+\.test$/', $base)) {
    fwrite(STDERR, "flows.php only runs against a throwaway *.test install, not '$base'\n");
    exit(2);
}
$TENANT = '00000000-0000-4000-8000-00000000a11c';
// Header budgets. Apache's LimitRequestFieldSize (one header line) and nginx's
// large_client_header_buffers both default to 8 KB; browsers cap a single
// cookie at 4096 bytes. Stay under them with room for proxies that add headers.
$HEADER_LINE_LIMIT = 8190;
$COOKIE_LIMIT = 4096;
$REQUEST_HEADERS_LIMIT = 8190;

$results = array();
$current = 'setup';
function check_group($name)
{
    global $current, $results;
    $current = $name;
    if (!isset($results[$name])) {
        $results[$name] = array('pass' => 0, 'fail' => array());
    }
    echo "\n## $name\n";
}
function ok($cond, $label, $detail = '')
{
    global $current, $results;
    if ($cond) {
        $results[$current]['pass']++;
        echo "PASS  $label\n";
    } else {
        $results[$current]['fail'][] = $label . ($detail !== '' ? " ($detail)" : '');
        echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
    }
    return $cond;
}

/**
 * A browser stand-in: one cookie store, records every request's real
 * outgoing header block so header sizes are measured, not estimated.
 */
class Browser
{
    public $name;
    public $share;
    public $maxCookieHeader = 0;
    public $maxRequestHeaders = 0;
    public $maxCookieHeaderUrl = '';
    public $setCookies = array();
    public function __construct($name)
    {
        $this->name = $name;
        $this->share = curl_share_init();
        curl_share_setopt($this->share, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    }
    public function request($method, $url, $headers = array(), $body = null)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_SHARE => $this->share,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ));
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return array('status' => 0, 'headers' => array(), 'body' => '', 'location' => '', 'error' => $err, 'cookies' => array());
        }
        $sent = (string) curl_getinfo($ch, CURLINFO_HEADER_OUT);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $this->measure($sent, $url);
        $headBlock = substr($raw, 0, $hsize);
        $respBody = (string) substr($raw, $hsize);
        $location = '';
        $cookies = array();
        foreach (preg_split('/\r?\n/', $headBlock) as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
            if (stripos($line, 'Set-Cookie:') === 0) {
                $c = trim(substr($line, 11));
                $cookies[] = $c;
                $this->setCookies[] = $c;
            }
        }
        return array('status' => $status, 'headers' => $headBlock, 'body' => $respBody, 'location' => $location, 'cookies' => $cookies, 'error' => '');
    }
    private function measure($sent, $url)
    {
        $total = strlen($sent);
        if ($total > $this->maxRequestHeaders) {
            $this->maxRequestHeaders = $total;
        }
        foreach (preg_split('/\r?\n/', $sent) as $line) {
            if (stripos($line, 'Cookie:') === 0 && strlen($line) > $this->maxCookieHeader) {
                $this->maxCookieHeader = strlen($line);
                $this->maxCookieHeaderUrl = $url;
            }
        }
    }
    /** Cookie header this browser would send to $url right now. */
    public function cookieHeaderFor($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SHARE, $this->share);
        curl_setopt($ch, CURLOPT_COOKIEFILE, '');
        $list = curl_getinfo($ch, CURLINFO_COOKIELIST);
        curl_close($ch);
        $pairs = array();
        foreach ((array) $list as $row) {
            $f = explode("\t", $row);
            if (count($f) < 7) {
                continue;
            }
            $domain = preg_replace('/^#HttpOnly_/', '', $f[0]);
            $sub = ($f[1] === 'TRUE');
            $d = ltrim($domain, '.');
            $match = ($host === $d) || ($sub && substr($host, -strlen('.' . $d)) === '.' . $d);
            if ($match && ((int) $f[4] === 0 || (int) $f[4] > time())) {
                $pairs[] = $f[5] . '=' . $f[6];
            }
        }
        return 'Cookie: ' . implode('; ', $pairs);
    }
    public function cookieNames($url)
    {
        $h = substr($this->cookieHeaderFor($url), 8);
        $names = array();
        foreach (array_filter(explode('; ', $h)) as $p) {
            $names[] = strstr($p, '=', true);
        }
        return $names;
    }
}

function absolute($from, $loc)
{
    if (preg_match('#^https?://#', $loc)) {
        return $loc;
    }
    $p = parse_url($from);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($loc, '/') === 0) {
        return $origin . $loc;
    }
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $origin . $dir . $loc;
}

/**
 * Walk the SSO login: base/login.php -> mock IdP (account picked with
 * $as/$mode) -> oauth-callback.php -> wherever HAXiam sends the browser.
 * Returns the hops and the final response. Never follows a redirect off the
 * *.test domain.
 */
function sso_login(Browser $b, $as, $mode = '', $oid = '')
{
    global $base;
    $url = "http://$base/login.php";
    $hops = array();
    $res = null;
    for ($i = 0; $i < 12; $i++) {
        $res = $b->request('GET', $url);
        $hops[] = array('url' => $url, 'status' => $res['status'], 'location' => $res['location'], 'cookies' => $res['cookies']);
        if ($res['location'] === '') {
            break;
        }
        $next = absolute($url, $res['location']);
        if (strpos($next, "http://idp.$base/") === 0) {
            $next .= '&as=' . rawurlencode($as);
            if ($mode !== '') {
                $next .= '&mode=' . rawurlencode($mode);
            }
            if ($oid !== '') {
                $next .= '&oid=' . rawurlencode($oid);
            }
        }
        $h = parse_url($next, PHP_URL_HOST);
        if (!is_string($h) || ($h !== $base && substr($h, -strlen(".$base")) !== ".$base")) {
            $hops[] = array('url' => $next, 'status' => 0, 'location' => '', 'cookies' => array(), 'offsite' => true);
            break;
        }
        $url = $next;
    }
    return array('hops' => $hops, 'final' => $res, 'finalUrl' => $url);
}

function hops_text($login)
{
    $t = array();
    foreach ($login['hops'] as $h) {
        $t[] = $h['status'] . ' ' . preg_replace('/([?&](code|state|nonce|code_challenge)=)[^&]+/', '$1…', $h['url']);
    }
    return implode(' -> ', $t);
}

function app_settings($html)
{
    $i = strpos($html, 'appSettings = {');
    if ($i === false) {
        return null;
    }
    // cut the object literal at its matching brace (string-aware)
    $s = substr($html, $i + 14);
    $depth = 0;
    $inStr = false;
    for ($k = 0, $n = strlen($s); $k < $n; $k++) {
        $c = $s[$k];
        if ($inStr) {
            if ($c === '\\') {
                $k++;
            } elseif ($c === '"') {
                $inStr = false;
            }
            continue;
        }
        if ($c === '"') {
            $inStr = true;
        } elseif ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                $dec = json_decode(substr($s, 0, $k + 1), true);
                return is_array($dec) ? $dec : null;
            }
        }
    }
    return null;
}

/** Fresh JWT + request tokens from the refresh cookie, like the editor does. */
function session_tokens(Browser $b, $user, $siteName = '')
{
    global $base;
    $body = $siteName !== '' ? array('siteName' => $siteName) : array();
    $r = $b->request('POST', "http://iam.$base/$user/system/api/v1/session/refresh", array(), $body ?: '{}');
    $d = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($d) || empty($d['jwt'])) {
        return null;
    }
    return $d;
}

function api(Browser $b, $method, $path, $tokens, $body = null, $siteToken = '')
{
    global $base;
    $h = array();
    if (is_array($tokens)) {
        $h[] = 'Authorization: Bearer ' . $tokens['jwt'];
        $h[] = 'X-HAXCMS-User-Token: ' . $tokens['userToken'];
    }
    if ($siteToken !== '') {
        $h[] = 'X-HAXCMS-Site-Token: ' . $siteToken;
    }
    $r = $b->request($method, "http://iam.$base$path", $h, $body);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

function site_json($root, $user, $site)
{
    $f = "$root/users_sites/$user/_sites/$site/site.json";
    return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
}

// ---------------------------------------------------------------------------
check_group('sso login');
$alice = new Browser('alice');
$bob = new Browser('bob');
$la = sso_login($alice, 'alice@example.test', '', 'oid-alice');
ok(substr($la['finalUrl'], 0, strlen("http://iam.$base/alice")) === "http://iam.$base/alice" && $la['final']['status'] === 200,
    'alice signs in through the IdP and lands on her own HAXcms (iam host)', hops_text($la));
$aliceApp = app_settings($la['final']['body']);
ok(is_array($aliceApp) && !empty($aliceApp['jwt']), 'alice\'s HAXcms boots with a JWT in appSettings');
ok(is_dir("$root/users/alice") && is_dir("$root/users_sites/alice/_sites"), 'first login creates users/alice and users_sites/alice');
$marker = "$root/_iamConfig/identities/alice.json";
$m = is_file($marker) ? json_decode((string) @file_get_contents($marker), true) : null;
ok(is_array($m) && isset($m['upn']) && $m['upn'] === 'alice@example.test',
    'first login binds users/alice to alice@example.test (_iamConfig/identities/alice.json)',
    is_file($marker) ? 'marker content is wrong' : 'no marker written: is _iamConfig/identities writable by the web server?');
$lb = sso_login($bob, 'bob@example.test', '', 'oid-bob');
ok(substr($lb['finalUrl'], 0, strlen("http://iam.$base/bob")) === "http://iam.$base/bob" && $lb['final']['status'] === 200,
    'bob signs in and lands on his own HAXcms', hops_text($lb));
// same person, new browser: must reach the same space, not identity_conflict
$alice2 = new Browser('alice-2');
$la2 = sso_login($alice2, 'alice@example.test', '', 'oid-alice');
ok(substr($la2['finalUrl'], 0, strlen("http://iam.$base/alice")) === "http://iam.$base/alice" && $la2['final']['status'] === 200,
    'alice can sign in again (second login reaches the same space)', hops_text($la2));
// already signed in: login.php must route, not error
$again = $alice->request('GET', "http://$base/login.php");
ok($again['status'] >= 300 && $again['status'] < 400 && strpos($again['location'], "iam.$base/alice") !== false,
    'login.php with a live session redirects to the user\'s space', 'HTTP ' . $again['status'] . ' ' . $again['location']);

check_group('idp rejects');
foreach (array('forged' => 'an ID token signed by an unknown key', 'wrongnonce' => 'an ID token with the wrong nonce',
    'wrongtenant' => 'an ID token from another tenant', 'expired' => 'an expired ID token') as $mode => $what) {
    $x = new Browser("mallory-$mode");
    $lx = sso_login($x, "mallory-$mode@example.test", $mode);
    $sawToken = false;
    foreach ($lx['hops'] as $h) {
        foreach ($h['cookies'] as $c) {
            if (stripos($c, 'haxcms_refresh_token=') === 0 && stripos($c, 'haxcms_refresh_token=deleted') !== 0) {
                $sawToken = true;
            }
        }
    }
    ok(strpos($lx['finalUrl'], 'sso_error=callback_failed') !== false && !$sawToken && !is_dir("$root/users/mallory-$mode"),
        "callback rejects $what (no refresh token, no user space)", hops_text($lx));
}
// replayed / forged state
$x = new Browser('mallory-state');
$x->request('GET', "http://$base/login.php");
$cb = $x->request('GET', "http://$base/oauth-callback.php?code=" . str_repeat('a', 32) . '&state=forged');
ok(strpos($cb['location'], 'sso_error=callback_failed') !== false, 'callback rejects a state that login.php did not issue', $cb['location']);
// collision: another identity that sanitizes to the same user name
$evil = new Browser('alice-impostor');
$le = sso_login($evil, 'alice@other.test', '', 'oid-impostor');
ok(strpos($le['finalUrl'], 'sso_error=identity_conflict') !== false && $le['final']['status'] === 403,
    'a different identity mapping to "alice" is blocked (identity_conflict)', hops_text($le));

// ---------------------------------------------------------------------------
check_group('site creation');
$ta = session_tokens($alice, 'alice');
$tb = session_tokens($bob, 'bob');
ok(is_array($ta) && !empty($ta['userToken']), 'alice gets a JWT + user token from session/refresh');
ok(is_array($tb) && !empty($tb['userToken']), 'bob gets a JWT + user token from session/refresh');
$ca = api($alice, 'POST', '/alice/system/api/v1/sites', $ta, array('site' => array('name' => 'alicesite', 'theme' => 'clean-one')));
ok($ca['status'] === 200 && is_file("$root/users_sites/alice/_sites/alicesite/site.json"),
    'alice creates a site in her own space', 'HTTP ' . $ca['status'] . ' ' . substr($ca['body'], 0, 200));
$cbs = api($bob, 'POST', '/bob/system/api/v1/sites', $tb, array('site' => array('name' => 'bobsite', 'theme' => 'clean-one')));
ok($cbs['status'] === 200 && is_file("$root/users_sites/bob/_sites/bobsite/site.json"),
    'bob creates a site in his own space', 'HTTP ' . $cbs['status'] . ' ' . substr($cbs['body'], 0, 200));
$la_list = api($alice, 'GET', '/alice/system/api/v1/sites', $ta);
$names = array();
foreach ((array) (isset($la_list['json']['data']['items']) ? $la_list['json']['data']['items'] : array()) as $it) {
    $names[] = isset($it['metadata']['site']['name']) ? $it['metadata']['site']['name'] : (isset($it['title']) ? $it['title'] : '');
}
ok($la_list['status'] === 200 && in_array('alicesite', $names, true) && !in_array('bobsite', $names, true),
    'alice\'s site list has her site and not bob\'s', 'HTTP ' . $la_list['status'] . ' names=' . implode(',', $names));
$visitor = new Browser('visitor');
$pub = $visitor->request('GET', "http://oer.$base/alice/sites/alicesite/");
ok($pub['status'] === 200, 'alice\'s new site is served to visitors on the open host', 'HTTP ' . $pub['status']);
$pub = $alice->request('GET', "http://oer.$base/alice/sites/alicesite/");
ok($pub['status'] >= 300 && $pub['status'] < 400 && strpos($pub['location'], "http://iam.$base/alice/sites/alicesite/") === 0,
    'alice opening her own site on the open host is sent to its editing address', 'HTTP ' . $pub['status'] . ' ' . $pub['location']);

// ---------------------------------------------------------------------------
check_group('tenant isolation');
// fresh tokens; bob also asks for a site token naming alice's site
$tb2 = session_tokens($bob, 'bob', 'alicesite');
$r = api($bob, 'GET', '/alice/system/api/v1/sites', $tb2);
ok($r['status'] === 401 || $r['status'] === 403, 'bob cannot list sites in alice\'s space', 'HTTP ' . $r['status']);
$r = api($bob, 'POST', '/alice/system/api/v1/sites', $tb2, array('site' => array('name' => 'planted', 'theme' => 'clean-one')));
ok(($r['status'] === 401 || $r['status'] === 403) && !is_dir("$root/users_sites/alice/_sites/planted"),
    'bob cannot create a site in alice\'s space', 'HTTP ' . $r['status']);
$r = api($bob, 'GET', '/alice/system/api/v1/sites/alicesite', $tb2);
ok($r['status'] === 401 || $r['status'] === 403, 'bob cannot read alice\'s site metadata via her path', 'HTTP ' . $r['status']);
$r = api($bob, 'GET', '/bob/system/api/v1/sites/alicesite', $tb2);
ok($r['status'] !== 200, 'bob cannot reach alice\'s site by name through his own path', 'HTTP ' . $r['status']);
foreach (array('archive', 'download', 'clone') as $op) {
    $r = api($bob, 'POST', "/bob/system/api/v1/sites/alicesite/$op", $tb2, array('site' => array('name' => 'alicesite')));
    ok($r['status'] !== 200 && is_file("$root/users_sites/alice/_sites/alicesite/site.json"),
        "bob cannot $op alice's site through his own path", 'HTTP ' . $r['status']);
}
// site-level write: positive control first so the negative means something
$sa = session_tokens($alice, 'alice', 'alicesite');
$before = site_json($root, 'alice', 'alicesite');
$itemId = (is_array($before) && isset($before['items'][0]['id'])) ? $before['items'][0]['id'] : '';
ok($itemId !== '', 'alice\'s new site has a first page');
$own = api($alice, 'PATCH', "/alice/sites/alicesite/x/api/v1/items/$itemId", $sa,
    array('operation' => 'setTitle', 'title' => 'Written by alice'), is_array($sa) && isset($sa['siteToken']) ? $sa['siteToken'] : '');
$after = site_json($root, 'alice', 'alicesite');
ok($own['status'] === 200 && isset($after['items'][0]['title']) && $after['items'][0]['title'] === 'Written by alice',
    'alice can edit a page in her own site (control)', 'HTTP ' . $own['status'] . ' ' . substr($own['body'], 0, 200));
$siteTokenBob = (is_array($tb2) && isset($tb2['siteToken'])) ? $tb2['siteToken'] : '';
$atk = api($bob, 'PATCH', "/alice/sites/alicesite/x/api/v1/items/$itemId", $tb2,
    array('operation' => 'setTitle', 'title' => 'Written by bob'), $siteTokenBob);
$after2 = site_json($root, 'alice', 'alicesite');
ok($atk['status'] !== 200 && isset($after2['items'][0]['title']) && $after2['items'][0]['title'] === 'Written by alice',
    'bob cannot edit a page in alice\'s site, even with a site token he requested for it', 'HTTP ' . $atk['status']);
// bob's browser session on alice's editing address must not boot an editor
$r = $bob->request('GET', "http://iam.$base/alice/");
$app = app_settings($r['body']);
ok($r['status'] !== 200 || !is_array($app) || empty($app['jwt']), 'bob\'s session does not get an editor (JWT) for alice\'s space',
    'HTTP ' . $r['status'] . ' ' . $r['location']);
$anon = new Browser('anonymous');
$r = api($anon, 'GET', '/alice/system/api/v1/sites', null);
ok($r['status'] === 401 || $r['status'] === 403, 'an anonymous caller cannot list alice\'s sites', 'HTTP ' . $r['status']);

// ---------------------------------------------------------------------------
check_group('header size');
// a long working session: many reloads of the editor + a few sites + logins
for ($i = 0; $i < 10; $i++) {
    $alice->request('GET', "http://iam.$base/alice/");
    session_tokens($alice, 'alice', 'alicesite');
}
for ($i = 0; $i < 5; $i++) {
    sso_login($alice, 'alice@example.test', '', 'oid-alice');
}
$alice->request('GET', "http://oer.$base/alice/sites/alicesite/");
foreach (array("http://$base/login.php", "http://iam.$base/alice/", "http://oer.$base/alice/sites/alicesite/") as $u) {
    $hdr = $alice->cookieHeaderFor($u);
    ok(strlen($hdr) < $HEADER_LINE_LIMIT, 'Cookie header to ' . parse_url($u, PHP_URL_HOST) . " stays under $HEADER_LINE_LIMIT bytes",
        strlen($hdr) . ' bytes');
    $names = $alice->cookieNames($u);
    $dupes = array_diff_assoc($names, array_unique($names));
    ok(count($names) <= 4 && count($dupes) <= 1, 'cookies to ' . parse_url($u, PHP_URL_HOST) . ' do not pile up over logins/reloads',
        count($names) . ' cookies: ' . implode(', ', $names));
}
$biggest = 0;
foreach (array_merge($alice->setCookies, $bob->setCookies) as $c) {
    $biggest = max($biggest, strlen(strstr($c, ';', true) ?: $c));
}
ok($biggest < $COOKIE_LIMIT, "every Set-Cookie value stays under the $COOKIE_LIMIT-byte browser cookie limit", "largest $biggest bytes");
$tok = session_tokens($alice, 'alice', 'alicesite');
$auth = 'Authorization: Bearer ' . (is_array($tok) ? $tok['jwt'] : '');
ok(is_array($tok) && strlen($auth) < $HEADER_LINE_LIMIT, "Authorization: Bearer <jwt> stays under $HEADER_LINE_LIMIT bytes", strlen($auth) . ' bytes');
ok($alice->maxRequestHeaders < $REQUEST_HEADERS_LIMIT && $bob->maxRequestHeaders < $REQUEST_HEADERS_LIMIT,
    "largest full request header block stays under $REQUEST_HEADERS_LIMIT bytes",
    'alice ' . $alice->maxRequestHeaders . ', bob ' . $bob->maxRequestHeaders . ' bytes');
$r = api($alice, 'GET', '/alice/system/api/v1/sites', $tok);
ok($r['status'] === 200 && stripos($r['body'], 'Request Header') === false,
    'a request with the accumulated cookies + bearer is accepted (no 400 header-too-large)', 'HTTP ' . $r['status']);
printf("info  largest Cookie header seen: %d bytes (%s); largest request headers: %d bytes\n",
    $alice->maxCookieHeader, $alice->maxCookieHeaderUrl, $alice->maxRequestHeaders);

// ---------------------------------------------------------------------------
check_group('logout');
$pre = new Browser('alice-pre-logout');
sso_login($pre, 'alice@example.test', '', 'oid-alice');
$preTokens = session_tokens($pre, 'alice');
// Every logout assertion is gated on this control: without a live session
// first, "cannot refresh after logout" would pass vacuously.
$live = ok(is_array($preTokens), 'control: a signed-in browser can refresh its session');
// snapshot this browser's cookies to replay after logout (a copied cookie jar)
$stolen = array();
foreach (array("http://$base/", "http://iam.$base/alice/") as $u) {
    $stolen[$u] = substr($pre->cookieHeaderFor($u), 8);
}
$lo = $pre->request('GET', "http://$base/logout.php");
ok($live && $lo['status'] >= 300 && $lo['status'] < 400 && strpos($lo['location'], "https://login.microsoftonline.com/$TENANT/oauth2/v2.0/logout") === 0,
    'logout.php hands off to the IdP logout endpoint (not followed)', 'HTTP ' . $lo['status'] . ' ' . $lo['location']);
$cleared = array();
foreach ($lo['cookies'] as $c) {
    if (preg_match('/^([^=]+)=deleted/', $c, $mm) || preg_match('/^([^=]+)=;/', $c, $mm)) {
        $cleared[] = $mm[1];
    }
}
ok($live && in_array('haxcms_refresh_token', $cleared, true) && in_array('PHPSESSID', $cleared, true),
    'logout.php expires the session and refresh-token cookies', 'cleared: ' . implode(', ', $cleared));
$r = $pre->request('GET', "http://iam.$base/alice/");
ok($live && $r['status'] >= 300 && $r['status'] < 400 && strpos($r['location'], 'login.php') !== false,
    'after logout the editing address sends the browser to login', 'HTTP ' . $r['status'] . ' ' . $r['location']);
$r = $pre->request('POST', "http://iam.$base/alice/system/api/v1/session/refresh", array(), '{}');
ok($live && $r['status'] !== 200, 'after logout the same browser cannot mint a new JWT from leftover cookies', 'HTTP ' . $r['status']);
// The same cookies replayed (a copied jar, or a shared computer): no HAXiam
// host may still mint a JWT from them once the user has logged out. Every
// user space has system/ on every host, so try them all.
$minted = array();
foreach (array($base, "iam.$base", "oer.$base", "courses.$base") as $host) {
    $replay = new Browser("replay-$host");
    $jar = $stolen["http://iam.$base/alice/"] . '; ' . $stolen["http://$base/"];
    $r = $replay->request('POST', "http://$host/alice/system/api/v1/session/refresh", array('Cookie: ' . $jar), '{}');
    $d = json_decode($r['body'], true);
    if ($r['status'] === 200 && is_array($d) && !empty($d['jwt'])) {
        $minted[] = $host;
    }
}
ok($live && count($minted) === 0, 'a refresh token copied before logout cannot mint a new JWT on any HAXiam host after logout',
    count($minted) ? 'still accepted on: ' . implode(', ', $minted) : '');

// ---------------------------------------------------------------------------
$failed = 0;
$report = array('base' => $base, 'checks' => array());
echo "\n== summary\n";
foreach ($results as $name => $r) {
    $failed += count($r['fail']);
    $report['checks'][$name] = array('passed' => $r['pass'], 'failures' => $r['fail']);
    printf("%-18s %d passed, %d failed\n", $name, $r['pass'], count($r['fail']));
}
if ($reportPath !== '') {
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
exit($failed > 0 ? 1 : 0);
