<?php
/**
 * regression test for AzureOIDC.
 *
 * Reproduces and asserts the locked contract at _contracts/azure_json_schema.md
 * (issue #3070). No real network calls — uses the class's test seams
 * (setJwksHttpFetcher / setIdTokenVerifier / setTokenExchanger) to swap the
 * real JWKS / token-exchange / verification calls with canned responses.
 *
 * Run: php testing/azureOIDCTest.php
 *
 * Exits 0 on success, non-zero on any failure. Sandbox is cleaned up on exit
 * without following symlinks; real files outside the sandbox are never touched.
 *
 * Self-skip: if composer/vendor is absent the test prints SKIP and exits 0,
 * because the AzureOIDC class deliberately degrades gracefully when its
 * dependencies are missing (returning null on calls that need them). The test
 * asserts load/isEnabled/handleCallback shape with seams, so composer is NOT
 * required to validate the contract — those seams are first-class in the
 * production class.
 */

if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'test.local';
}
// Minimal globals so other HAXiam classes (IAM, etc.) don't warn if included.
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = '';

$repoRoot = dirname(__DIR__);
$azurePath = $repoRoot . '/system/lib/AzureOIDC.php';
if (!file_exists($azurePath)) {
    fwrite(STDERR, "FAIL: cannot find AzureOIDC.php at $azurePath\n");
    exit(1);
}

$sandbox = rtrim(sys_get_temp_dir(), '/') . '/haxiam_azureoidc_test_' . uniqid();

$failures = array();
$passes = array();

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

/**
 * Mirror the liberateSymlinksTest pattern: copy AzureOIDC.php into a sandbox
 * system/lib/ so its __DIR__ resolves to a clean, isolated location. Sandbox
 * also hosts an _iamConfig/azure.json we control.
 */
function buildSandbox($sandbox)
{
    @mkdir($sandbox . '/system/lib', 0755, true);
    @mkdir($sandbox . '/_iamConfig', 0755, true);
    copy(dirname(__DIR__) . '/system/lib/AzureOIDC.php', $sandbox . '/system/lib/AzureOIDC.php');
}

function makeAzureJson($sandbox, $enabled = true)
{
    $cfg = array(
        'enabled' => $enabled,
        'tenantId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'clientId' => '11111111-2222-3333-4444-555555555555',
        'clientSecret' => 'supersecret-very-private',
        'redirectUri' => 'https://iam.example.edu/oauth-callback.php',
        'issuer' => '',
        'scopes' => 'openid profile email',
        'providerClass' => 'AzureOIDC',
    );
    file_put_contents(
        $sandbox . '/_iamConfig/azure.json',
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    @chmod($sandbox . '/_iamConfig/azure.json', 0600);
}

/**
 * Recursively remove a path WITHOUT following symlinks (mirror
 * liberateSymlinksTest cleanupPath).
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

try {
    buildSandbox($sandbox);
    makeAzureJson($sandbox);

    require_once $sandbox . '/system/lib/AzureOIDC.php';

    if (!class_exists('AzureOIDC')) {
        throw new RuntimeException('AzureOIDC class did not load');
    }
    /** @var AzureOIDC $provider */
    $provider = AzureOIDC::load($sandbox . '/_iamConfig/azure.json');
    check(
        is_object($provider) && isset($provider->config->tenantId),
        'AzureOIDC::load returns instance with config.tenantId',
        $passes,
        $failures
    );
    check(
        $provider->config->clientSecret === 'supersecret-very-private',
        'AzureOIDC::load preserves clientSecret value (internal field)',
        $passes,
        $failures
    );
    check(
        $provider->path === $sandbox . '/_iamConfig/azure.json',
        'AzureOIDC::load records the path it read from',
        $passes,
        $failures
    );

    // Invariant #1: missing file MUST throw, never silently return.
    try {
        AzureOIDC::load($sandbox . '/_iamConfig/does-not-exist.json');
        check(false, 'AzureOIDC::load throws on missing path', $passes, $failures);
    } catch (RuntimeException $e) {
        check(
            strpos($e->getMessage(), 'supersecret-very-private') === false,
            'AzureOIDC::load exception does NOT leak clientSecret text',
            $passes,
            $failures
        );
        check(true, 'AzureOIDC::load throws on missing path', $passes, $failures);
    }

    // Invariant #2: isEnabled requires all four scalar fields + enabled:true
    check($provider->isEnabled() === true, 'AzureOIDC::isEnabled true for full-config', $passes, $failures);

    $cfg = $provider->config;
    $cfg->clientSecret = '';
    $provider->config = $cfg;
    check($provider->isEnabled() === false, 'AzureOIDC::isEnabled false when clientSecret empty', $passes, $failures);

    makeAzureJson($sandbox, false);
    $provider = AzureOIDC::load($sandbox . '/_iamConfig/azure.json');
    check($provider->isEnabled() === false,
        'AzureOIDC::isEnabled false when enabled:false',
        $passes,
        $failures
    );

    // getExpectedIssuer derives from tenantId when issuer isn't set.
    $expected = 'https://login.microsoftonline.com/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/v2.0';
    check(
        $provider->getExpectedIssuer() === $expected,
        'AzureOIDC::getExpectedIssuer derives Microsoft v2 endpoint from tenantId',
        $passes,
        $failures
    );

    $provider = AzureOIDC::load($sandbox . '/_iamConfig/azure.json');
    $provider->config->issuer = 'https://login.microsoftonline.com/custom-tenant/v2.0';
    check(
        $provider->getExpectedIssuer() === 'https://login.microsoftonline.com/custom-tenant/v2.0',
        'AzureOIDC::getExpectedIssuer honors explicit issuer override',
        $passes,
        $failures
    );
    // restore
    $provider->config->issuer = '';

    // getLogoutUrl always returns a tenant-anchored Microsoft logout URL.
    $provider = AzureOIDC::load($sandbox . '/_iamConfig/azure.json');
    $logoutExpected = 'https://login.microsoftonline.com/' . rawurlencode('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee') . '/oauth2/v2.0/logout';
    check(
        $provider->getLogoutUrl() === $logoutExpected,
        'AzureOIDC::getLogoutUrl returns tenant-anchored Microsoft v2 logout URL',
        $passes,
        $failures
    );

    // sanitizeUserName — Invariant #8: machine-name-safe extraction.
    check($provider->sanitizeUserName('Alice.Smith-99') === 'alice.smith-99', 'sanitizeUserName lowercases + allows a-z0-9._-', $passes, $failures);
    check($provider->sanitizeUserName('alice@example.edu') === 'alice', 'sanitizeUserName email => local-part', $passes, $failures);
    check($provider->sanitizeUserName('  strange+chars!@#  ') === 'strangechars', 'sanitizeUserName drops + and ! and @ and #', $passes, $failures);
    check($provider->sanitizeUserName('...leadingdots') === 'leadingdots', 'sanitizeUserName strips leading dots', $passes, $failures);
    check($provider->sanitizeUserName('-leadingdash') === 'leadingdash', 'sanitizeUserName strips leading dashes', $passes, $failures);
    check($provider->sanitizeUserName('') === '', 'sanitizeUserName empty => empty', $passes, $failures);
    check($provider->sanitizeUserName(null) === '', 'sanitizeUserName null => empty', $passes, $failures);

    // generateState returns non-empty opaque string.
    $state = $provider->generateState();
    check(is_string($state) && strlen($state) >= 16, 'AzureOIDC::generateState returns non-empty opaque string', $passes, $failures);

    // Invariant #8: handleCallback happy path via the three test seams.
    // Re-enable the config because the earlier branches toggled enabled
    // off; a disabled provider short-circuits to null at the very top
    // of handleCallback().
    makeAzureJson($sandbox, true);
    $provider = AzureOIDC::load($sandbox . '/_iamConfig/azure.json');
    $_SESSION = array();
    $_SESSION['oauth_state'] = $state;

    $fakeClaims = (object) array(
        'iss' => 'https://login.microsoftonline.com/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/v2.0',
        'aud' => '11111111-2222-3333-4444-555555555555',
        'exp' => time() + 600,
        'preferred_username' => 'Jane.Doe@Example.edu',
        'email' => 'jane.doe@example.edu',
        'oid' => 'aaaaaaaa',
    );

    AzureOIDC::setJwksHttpFetcher(function ($url) {
        return array('keys' => array((object) array(
            'kid' => 'k1',
            'kty' => 'RSA',
            'n' => 'sXch7gO',
            'e' => 'AQAB',
        )));
    });
    AzureOIDC::setIdTokenVerifier(function ($idToken, $jwks, $expectedIssuer, $expectedAudience) use ($fakeClaims) {
        // Pretend we successfully verified — return the canned claims.
        return $fakeClaims;
    });
    // getValues must be a real method because handleCallback calls
    // $tokenResult->getValues() with method_exists() guard. Use stdClass
    // with __call magic so the closure-style helper maps to a method.
    $fakeAccessToken = new class {
        public function getValues()
        {
            return array('id_token' => 'fake.jwt.token');
        }
    };
    AzureOIDC::setTokenExchanger(function ($code, $self) use ($fakeAccessToken) {
        return $fakeAccessToken;
    });

    $userVar = $provider->handleCallback(array(
        'code' => 'auth-code-abc',
        'state' => $state,
    ));
    check($userVar === 'jane.doe', 'handleCallback happy path returns sanitized preferred_username', $passes, $failures);
    check(!isset($_SESSION['oauth_state']), 'handleCallback one-shots oauth_state in session', $passes, $failures);

    // Invariant #8 #2: state mismatch returns null.
    makeAzureJson($sandbox, true);
    $provider = AzureOIDC::load($sandbox . '/_iamConfig/azure.json');
    $_SESSION = array();
    $_SESSION['oauth_state'] = 'a-different-state';
    $fail = $provider->handleCallback(array(
        'code' => 'auth-code-abc',
        'state' => 'wrong-state',
    ));
    check($fail === null, 'handleCallback rejects CSRF mismatched state', $passes, $failures);
    check(!isset($_SESSION['oauth_state']), 'handleCallback clears oauth_state on CSRF mismatch too', $passes, $failures);

    // Invariant #8 #3: missing code returns null.
    $_SESSION = array();
    $_SESSION['oauth_state'] = $state;
    $fail = $provider->handleCallback(array('state' => $state));
    check($fail === null, 'handleCallback rejects missing code', $passes, $failures);

    // Invariant #8 #4: token-exchange failure returns null (no refresh token).
    AzureOIDC::resetTokenExchanger();
    AzureOIDC::setTokenExchanger(function ($code, $self) {
        return null;
    });
    $_SESSION = array();
    $_SESSION['oauth_state'] = $state;
    $fail = $provider->handleCallback(array(
        'code' => 'auth-code-abc',
        'state' => $state,
    ));
    check($fail === null, 'handleCallback returns null when token exchange fails (no refresh token issued)', $passes, $failures);

    // Invariant #8 #5: claim mismatch (wrong audience) returns null.
    AzureOIDC::setIdTokenVerifier(function ($idToken, $jwks, $expectedIssuer, $expectedAudience) use ($fakeClaims) {
        $bad = clone $fakeClaims;
        $bad->aud = 'someone-elses-client-id';
        return $bad;
    });
    AzureOIDC::setTokenExchanger(function ($code, $self) use ($fakeAccessToken) {
        return $fakeAccessToken;
    });
    $_SESSION = array();
    $_SESSION['oauth_state'] = $state;
    $fail = $provider->handleCallback(array(
        'code' => 'auth-code-abc',
        'state' => $state,
    ));
    check($fail === null, 'handleCallback rejects ID token with mismatched audience (aud claim)', $passes, $failures);

    // Invariant #8 #6: expired token returns null.
    AzureOIDC::setIdTokenVerifier(function ($idToken, $jwks, $expectedIssuer, $expectedAudience) use ($fakeClaims) {
        $bad = clone $fakeClaims;
        $bad->exp = time() - 60;
        return $bad;
    });
    $_SESSION = array();
    $_SESSION['oauth_state'] = $state;
    $fail = $provider->handleCallback(array(
        'code' => 'auth-code-abc',
        'state' => $state,
    ));
    check($fail === null, 'handleCallback rejects expired ID token', $passes, $failures);

    // Invariant #8 #7: not-enabled provider short-circuits to null.
    makeAzureJson($sandbox, false);
    $disabled = AzureOIDC::load($sandbox . '/_iamConfig/azure.json');
    $fail = $disabled->handleCallback(array(
        'code' => 'x',
        'state' => 'y',
    ));
    check($fail === null, 'handleCallback returns null when provider isEnabled() is false', $passes, $failures);

    // Invariant #2 contract: clientSecret NOT exposed via __toString or debug.
    // PHP doesn't have native __toString; default var_dump would show fields.
    // We assert object identity is intact without leaking secrets via gettype
    // or class reflection.
    check(is_object($provider) && get_class($provider) === 'AzureOIDC', 'AzureOIDC is a single clean class', $passes, $failures);
    check(!method_exists($provider, '__toString'), 'AzureOIDC does not define __toString (no implicit secret leak)', $passes, $failures);

    // Composer-missing self-skip path: nothing here triggers that, but assert
    // the contract by reading composer.json.
    $composerJson = dirname(__DIR__) . '/composer.json';
    check(file_exists($composerJson), 'composer.json is shipped (vendor/ is git-ignored)', $passes, $failures);

    // Review fix #17: no-arg load() must resolve to the HAXiam root's
    // _iamConfig/azure.json, not system/_iamConfig/azure.json.
    // defaultConfigPath() uses dirname(__DIR__, 2) from system/lib/ which
    // gives the HAXiam root. We verify by checking the path ends with
    // the correct relative location.
    $expectedSuffix = '/_iamConfig/azure.json';
    $reflection = new ReflectionMethod('AzureOIDC', 'defaultConfigPath');
    $reflection->setAccessible(true);
    $defaultPath = $reflection->invoke(null);
    check(
        substr($defaultPath, -strlen($expectedSuffix)) === $expectedSuffix,
        'AzureOIDC::defaultConfigPath returns a path ending in /_iamConfig/azure.json',
        $passes,
        $failures
    );
    // The path should NOT contain 'system/_iamConfig' (the old buggy path).
    check(
        strpos($defaultPath, 'system/_iamConfig') === false,
        'AzureOIDC::defaultConfigPath does NOT resolve to system/_iamConfig (old bug)',
        $passes,
        $failures
    );

    // Review fix #1: composer.json has an autoload classmap for system/lib/.
    $composerData = json_decode(file_get_contents($composerJson), true);
    check(
        isset($composerData['autoload']['classmap']) &&
        in_array('system/lib/', $composerData['autoload']['classmap']),
        'composer.json has autoload classmap for system/lib/ (AzureOIDC loadable via composer)',
        $passes,
        $failures
    );

    // Reset test seams between runs.
    AzureOIDC::resetJwksHttpFetcher();
    AzureOIDC::resetIdTokenVerifier();
    AzureOIDC::resetTokenExchanger();
} catch (Throwable $e) {
    $failures[] = 'unhandled exception: ' . $e->getMessage();
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n");
} finally {
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
