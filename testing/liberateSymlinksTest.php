<?php
/**
 * Regression test for IAM::liberate() symlink creation.
 *
 * Reproduces and guards against the bug where newly liberated user accounts
 * were missing the users_sites/{user}/wc-registry.json symlink. Without that
 * link, site pages preload a wc-registry.json that 404s, and save/rebuild
 * surfaces it because rebuildManagedFiles() writes a hard preload into index.html.
 *
 * Run: php testing/liberateSymlinksTest.php
 *
 * The test builds an isolated sandbox under the system temp dir, includes the
 * REAL HAXiam system/lib/IAM.php, calls liberate(), and asserts:
 *   1. users_sites/{user}/wc-registry.json exists as a symlink
 *   2. it resolves to the core wc-registry.json file
 *   3. the site-level chain _sites/{site}/wc-registry.json -> ../../wc-registry.json
 *      resolves all the way to the core file (this is the chain the browser hits)
 *   4. users/{user}/wc-registry.json also exists (created by liberate()'s readdir
 *      loop) so the server-side HAXCMS_ROOT fallback in HAXCMS::getWCRegistryJson()
 *      keeps working
 *
 * Exits 0 on success, non-zero on any failure. Sandbox is cleaned up on exit
 * without following symlinks, so real files outside the sandbox are never touched.
 */

// Minimal $_SERVER so IAM's constructor (which reads HTTP_HOST) doesn't warn.
$_SERVER['HTTP_HOST'] = 'test.local';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = '';

$repoRoot = dirname(__DIR__);
$iamPath = $repoRoot . '/system/lib/IAM.php';
if (!file_exists($iamPath)) {
    fwrite(STDERR, "FAIL: cannot find IAM.php at $iamPath\n");
    exit(1);
}

$core = 'HAXcms-1.x.x';
$user = 'testuser_' . substr(uniqid(), -6);
$sandbox = rtrim(sys_get_temp_dir(), '/') . '/haxiam_liberate_test_' . uniqid();

$failures = array();
$passes = array();

/**
 * Recursively remove a path WITHOUT following symlinks.
 * Symlinks are unlinked directly; directories are recursed then removed.
 */
function cleanupPath($path)
{
    if (is_link($path)) {
        @unlink($path);
        return;
    }
    if (is_dir($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            cleanupPath($path . '/' . $entry);
        }
        @rmdir($path);
        return;
    }
    if (file_exists($path)) {
        @unlink($path);
    }
}

/**
 * Build a minimal sandbox that mirrors the HAXiam layout liberate() expects.
 */
function buildSandbox($sandbox, $core)
{
    // core directory with the files the explicit symlinks reference
    $coreDir = $sandbox . '/cores/' . $core;
    @mkdir($coreDir, 0755, true);
    // files referenced by the hardcoded symlink list in liberate()
    foreach (array('build', 'gitlist', 'babel', 'haxcms-jwt.php', '.htaccess') as $f) {
        touch($coreDir . '/' . $f);
    }
    // the registry file itself
    file_put_contents($coreDir . '/wc-registry.json', '{"test":true}');
    // userData boilerplate is copied during liberate()
    @mkdir($coreDir . '/system/boilerplate/systemsetup', 0755, true);
    touch($coreDir . '/system/boilerplate/systemsetup/userData.json');
    // _iamConfig files symlinked into user _config
    @mkdir($sandbox . '/_iamConfig', 0755, true);
    touch($sandbox . '/_iamConfig/config.json');
    touch($sandbox . '/_iamConfig/.htaccess');
    touch($sandbox . '/_iamConfig/SALT.txt');
    touch($sandbox . '/_iamConfig/my-custom-elements.js');
    touch($sandbox . '/_iamConfig/HAXcmsConfig.php');
}

/**
 * Assert helper.
 */
function check($cond, $label, array &$passes, array &$failures)
{
    if ($cond) {
        $passes[] = $label;
        echo "PASS: $label\n";
    } else {
        $failures[] = $label;
        echo "FAIL: $label\n";
    }
}

try {
    buildSandbox($sandbox, $core);

    // IAM.php self-instantiates a global $IAM at include time.
    // We need IAM_ROOT (defined inside IAM.php) to point at our sandbox, so we
    // temporarily chdir to the sandbox before include. IAM_ROOT is derived from
    // __FILE__ though, so we instead define IAM_ROOT before include by loading
    // the source with the path rewritten. Simplest robust approach: copy IAM.php
    // into the sandbox's system/lib/ so its __FILE__ resolves the sandbox as root.
    $sandboxLibDir = $sandbox . '/system/lib';
    @mkdir($sandboxLibDir, 0755, true);
    copy($iamPath, $sandboxLibDir . '/IAM.php');

    // stub iamConfig.php that grantFreedom includes; liberate() itself doesn't
    // require it but including IAM.php is enough for our purpose.
    include_once $sandboxLibDir . '/IAM.php';

    if (!isset($GLOBALS['IAM']) || !is_object($GLOBALS['IAM'])) {
        throw new Exception('IAM global was not instantiated after include');
    }
    /** @var IAM $IAM */
    $IAM = $GLOBALS['IAM'];
    // override coresDir so it points at our sandbox cores
    $IAM->coresDir = $sandbox . '/cores';

    $IAM->liberate($user, $core);

    $coreFile = $sandbox . '/cores/' . $core . '/wc-registry.json';
    $coreReal = realpath($coreFile);

    // 1. users_sites/{user}/wc-registry.json is a symlink
    $sitesLink = $sandbox . '/users_sites/' . $user . '/wc-registry.json';
    check(is_link($sitesLink), "users_sites/$user/wc-registry.json is a symlink", $passes, $failures);

    // 2. it resolves to the core file
    $sitesReal = is_link($sitesLink) ? realpath($sitesLink) : false;
    check(
        $sitesReal === $coreReal,
        "users_sites/$user/wc-registry.json resolves to core wc-registry.json",
        $passes,
        $failures
    );

    // 3. site-level chain: _sites/{site}/wc-registry.json -> ../../wc-registry.json -> core
    //    This mirrors what HAXCMSSite::newSite() creates and what the browser fetches.
    $siteDir = $sandbox . '/users_sites/' . $user . '/_sites/mysite';
    @mkdir($siteDir, 0755, true);
    $siteLevelLink = $siteDir . '/wc-registry.json';
    @symlink('../../wc-registry.json', $siteLevelLink);
    $siteReal = realpath($siteLevelLink);
    check(
        $siteReal === $coreReal,
        "_sites/mysite/wc-registry.json -> ../../wc-registry.json resolves to core",
        $passes,
        $failures
    );

    // 4. users/{user}/wc-registry.json exists (via the readdir loop) so the
    //    server-side HAXCMS_ROOT fallback in HAXCMS::getWCRegistryJson() works
    $userLink = $sandbox . '/users/' . $user . '/wc-registry.json';
    check(is_link($userLink), "users/$user/wc-registry.json is a symlink (readdir loop)", $passes, $failures);
    $userReal = is_link($userLink) ? realpath($userLink) : false;
    check(
        $userReal === $coreReal,
        "users/$user/wc-registry.json resolves to core wc-registry.json",
        $passes,
        $failures
    );
} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n");
} finally {
    // always clean up, never following symlinks out of the sandbox
    cleanupPath($sandbox);
}

echo "\n" . count($passes) . " passed, " . count($failures) . " failed\n";
if (count($failures) > 0) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "ALL PASS\n";
exit(0);
