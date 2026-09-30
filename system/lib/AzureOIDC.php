<?php
/**
 * AzureOIDC — Microsoft Entra ID (Azure AD) OAuth2 / OIDC provider for HAXiam.
 *
 * Implements the publisher-side invariants #1–3, #6, #8 from
 * _contracts/azure_json_schema.md:
 *
 *   #1. load(): reads `_iamConfig/azure.json`, returns self. Missing / unreadable
 *       files throw RuntimeException with NO credentials in the message text.
 *
 *   #2. isEnabled(): $this->config->enabled === true AND all four of
 *       tenantId / clientId / clientSecret / redirectUri are non-empty strings.
 *
 *   #3. redirectUri must be byte-identical between the install-time config and
 *       the runtime value; the buildAuthorizationUrl() method passes the
 *       stored redirectUri verbatim into GenericProvider.
 *
 *   #6. iamConfig.php (post-bridge) prefers $_SESSION['HAXIAM_USER'] when this
 *       provider is enabled.
 *
 *   #8. ID-token signature is validated against the Microsoft JWKS endpoint
 *       (https://login.microsoftonline.com/{tenantId}/discovery/v2.0/keys) via
 *       Firebase\JWT\JWT::decode; claims `iss`, `aud`, `exp` are then enforced
 *       against the configured values; `nbf` is sanity-checked (5s leeway).
 *
 * Library choice (locked at plan time):
 *   - league/oauth2-client ^2.6  → \League\OAuth2\Client\Provider\GenericProvider
 *     (we hit Microsoft v2.0 endpoints directly with GenericProvider rather
 *      than pull in a sub-provider package like thenetninja/oauth2-microsoft
 *      — fewer composer dependencies, same behaviour. If the user later wants
 *      v1 endpoints, swap the URL prefix in buildAuthorizationUrl().)
 *   - firebase/php-jwt ^7.0        → Firebase\JWT\JWT + Firebase\JWT\JWK
 *     (^7.0 required: CVE-2025-45769 / GHSA-2x45-7fc3-mxwq affects all
 *      versions <7.0.0 and composer refuses to resolve advisory-blocked
 *      packages; the v7 API for JWT::$leeway / JWK::parseKeySet / JWT::decode
 *      is unchanged from v6.)
 *
 * Test seams (these are intentional, not workarounds):
 *   - setJwksHttpFetcher()  → swap the JWKS GET (testing/azureOIDCTest.php uses
 *     this so no real network call is made)
 *   - setIdTokenVerifier()  → swap the ID-token signature/claim verifier
 *     (tests inject a closure that returns canned claims)
 *   - setTokenExchanger()   → swap the OAuth code → token exchange
 *   - setProviderFactory()  → swap the GenericProvider factory
 *
 * If a test seam is NOT set the corresponding call path falls through to the
 * real implementation guarded by the class_exists() of the composer
 * dependencies; the class files itself can be loaded without composer, but
 * public methods that depend on the package merely return null with no
 * side effects, which is what testing/azureOIDCTest.php expects when vendor/
 * is missing on disk (self-skip clean path).
 */

class AzureOIDC
{
    /**
     * The decoded `_iamConfig/azure.json` payload (stdClass).
     * Kept protected so var_dump/exception contexts don't expose
     * clientSecret (review fix #14). Use getConfigField() for access.
     * @var object|null
     */
    protected $config;

    /**
     * Filesystem path the most recent load() read from.
     * @var string|null
     */
    public $path;

    /**
     * Static JWKS HTTP fetcher override (test seam). Should return an array.
     * @var callable|null
     */
    protected static $jwksHttpFetcher = null;

    /**
     * Static ID-token verifier override (test seam). Receives
     *   ($idToken, $jwks, $expectedIssuer, $expectedAudience)
     * and may return anything truthy (claims) or throw / return falsy.
     * @var callable|null
     */
    protected static $idTokenVerifier = null;

    /**
     * Static OAuth-token-exchange override (test seam). Receives ($code, self)
     * and should return an access-token-like object exposing getValues() that
     * includes `id_token`, or null on failure.
     * @var callable|null
     */
    protected static $tokenExchanger = null;

    /**
     * Static GenericProvider factory override (test seam). Receives the five
     * GenericProvider constructor args and returns a provider instance.
     * @var callable|null
     */
    protected static $providerFactory = null;

    /**
     * In-memory JWKS cache keyed by tenantId so we don't hit Microsoft's
     * discovery endpoint on every callback (L3: latency + DoS hardening).
     * Only consulted on the production path; the test seam bypasses it so
     * tests stay deterministic.
     * @var array
     */
    protected static $jwksCache = array();

    /**
     * JWKS cache TTL in seconds. Microsoft rotates signing keys rarely;
     * 1h is a safe upper bound.
     */
    protected static $jwksCacheTtl = 3600;

    /**
     * Default constructor — callers should use load() to populate config.
     */
    public function __construct()
    {
        $this->config = new stdClass();
        $this->path = null;
    }

    /**
     * Safe accessor for config fields. Returns null for missing fields
     * so callers don't need to access $this->config directly (which
     * would expose clientSecret via var_dump/exception contexts).
     *
     * @param string $field
     * @return mixed|null
     */
    public function getConfigField($field)
    {
        if (!is_object($this->config) || !isset($this->config->{$field})) {
            return null;
        }
        return $this->config->{$field};
    }

    /**
     * Set a config field (used by tests to mutate config without
     * directly accessing the protected $config property).
     *
     * @param string $field
     * @param mixed $value
     */
    public function setConfigField($field, $value)
    {
        if (!is_object($this->config)) {
            $this->config = new stdClass();
        }
        $this->config->{$field} = $value;
    }

    /**
     * Redacted debug representation — never exposes clientSecret.
     * @return array
     */
    public function __debugInfo()
    {
        return array(
            'path' => $this->path,
            'enabled' => $this->getConfigField('enabled'),
            'tenantId' => $this->getConfigField('tenantId'),
            'clientId' => $this->getConfigField('clientId'),
            'clientSecret' => '<redacted>',
            'redirectUri' => $this->getConfigField('redirectUri'),
        );
    }

    // Walks up from system/lib/AzureOIDC.php to the HAXiam root to find
    // _iamConfig/azure.json. dirname(__DIR__, 2) goes from system/lib/ to
    // the HAXiam root (two levels up). For installs with a non-standard
    // layout iamConfig.php / oauth-callback.php pass an explicit path;
    // this fallback is just a convenience.
    private static function defaultConfigPath()
    {
        return dirname(__DIR__, 2) . '/_iamConfig/azure.json';
    }

    /**
     * Invariant #1: load the Azure config from disk.
     *
     * @param string|null $path absolute path to azure.json. When null, falls
     *                          back to HAXiam root _iamConfig/azure.json.
     * @return self
     * @throws RuntimeException when the file is missing / unreadable / not
     *         valid JSON. Exception messages contain field NAMES only, never
     *         the secret values.
     */
    public static function load($path = null)
    {
        $self = new self();
        if ($path === null || !is_string($path)) {
            $path = self::defaultConfigPath();
        }
        if (!file_exists($path)) {
            throw new RuntimeException('azure.json not found at expected location');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read azure.json');
        }
        $data = json_decode($raw);
        if (!is_object($data)) {
            throw new RuntimeException('azure.json could not be parsed as JSON');
        }
        // Backwards-compat: missing optional fields become empty strings instead
        // of empty objects/arrays so callers can treat everything as scalar.
        foreach (array('enabled', 'tenantId', 'clientId', 'clientSecret', 'redirectUri', 'issuer', 'scopes', 'providerClass') as $f) {
            if (!property_exists($data, $f)) {
                $data->{$f} = '';
            }
        }
        // Default scopes per schema row.
        if (!is_string($data->scopes) || trim($data->scopes) === '') {
            $data->scopes = 'openid profile email';
        }
        if (!is_string($data->providerClass) || trim($data->providerClass) === '') {
            $data->providerClass = 'AzureOIDC';
        }
        $self->config = $data;
        $self->path = $path;
        return $self;
    }

    /**
     * Invariant #2: isEnabled when the master switch is true AND the four
     * required fields are non-empty, non-whitespace strings.
     *
     * @return bool
     */
    public function isEnabled()
    {
        if (!is_object($this->config)) {
            return false;
        }
        if (isset($this->config->enabled) && $this->config->enabled === true) {
            // continue
        } else {
            return false;
        }
        foreach (array('tenantId', 'clientId', 'clientSecret', 'redirectUri') as $f) {
            if (!isset($this->config->{$f})) {
                return false;
            }
            if (!is_string($this->config->{$f})) {
                return false;
            }
            if (trim($this->config->{$f}) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the configured issuer URL. Defaults to the Microsoft v2 endpoint
     * derived from tenantId when the operator left `issuer` blank. This is
     * what handleCallback() and check-azure-sso.sh both compare against.
     *
     * @return string
     */
    public function getExpectedIssuer()
    {
        $tenant = (isset($this->config->tenantId) && is_string($this->config->tenantId)) ? $this->config->tenantId : '';
        $issuer = (isset($this->config->issuer) && is_string($this->config->issuer)) ? trim($this->config->issuer) : '';
        if ($issuer === '') {
            return 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/v2.0';
        }
        return $issuer;
    }

    /**
     * Convenience: derive the user-friendly Microsoft logout URL for the
     * tenant. iamConfig.php uses this to populate $IAM->enterprise->logout
     * when Azure is the auth source.
     *
     * @return string
     */
    public function getLogoutUrl()
    {
        $tenant = (isset($this->config->tenantId) && is_string($this->config->tenantId)) ? $this->config->tenantId : '';
        return 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/logout';
    }

    /**
     * Compute a stable, opaque `state` value that the caller stores in
     * $_SESSION before redirecting to the Azure authorize endpoint. The
     * callback verifies the round-trip.
     *
     * @return string
     */
    public function generateState()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Compute an opaque OIDC `nonce` for replay protection (M4). The caller
     * stores this in $_SESSION['oauth_nonce'] before redirecting to the
     * Azure authorize endpoint; handleCallback() asserts the ID-token
     * `nonce` claim round-trips so a captured code/token can't be replayed
     * in a different session.
     *
     * @return string
     */
    public function generateNonce()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Compute a PKCE `code_verifier` (M5). 43 chars of URL-safe entropy.
     * The caller stores this in $_SESSION['oauth_code_verifier'] and
     * buildAuthorizationUrl() derives the S256 code_challenge from it so a
     * leaked authorization code can't be redeemed without the verifier.
     *
     * @return string
     */
    public function generateCodeVerifier()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Invariant #3 / publisher-side: build the Microsoft v2.0 authorize URL.
     *
     * Uses GenericProvider against the v2.0 endpoints so the PKCE/redirect_uri
     * behaviour matches what Microsoft expects (response_type=code, scopes
     * joined, state appended). The redirectUri we hand to GenericProvider is
     * byte-identical to azure.json's redirectUri field, so a tenant-side
     * misconfiguration will surface as an error from Microsoft rather than a
     * silent mismatch.
     *
     * @param string $state opaque value the caller has stashed in $_SESSION
     * @return string full authorize URL
     * @throws RuntimeException when the provider is not enabled or the
     *         composer dependencies are unavailable
     */
    public function buildAuthorizationUrl($state, $nonce = null, $codeVerifier = null)
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('AzureOIDC is not enabled');
        }
        if (!is_string($state) || trim($state) === '') {
            throw new RuntimeException('state is required to build the authorization URL');
        }

        $scopes = isset($this->config->scopes) ? $this->config->scopes : 'openid profile email';
        $scopesArray = array_values(array_filter(preg_split('/\s+/', $scopes), function ($s) {
            return is_string($s) && $s !== '';
        }));
        if (count($scopesArray) === 0) {
            $scopesArray = array('openid', 'profile', 'email');
        }

        $tenantId = $this->config->tenantId;
        $authorizeUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/authorize';
        $accessTokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

        if (!class_exists('\League\OAuth2\Client\Provider\GenericProvider')) {
            throw new RuntimeException('league/oauth2-client is not available — run composer install');
        }

        $provider = self::$providerFactory !== null
            ? call_user_func(self::$providerFactory, $this->config->clientId, $this->config->clientSecret, $authorizeUrl, $accessTokenUrl, $this->config->redirectUri)
            : new \League\OAuth2\Client\Provider\GenericProvider(array(
                'clientId' => $this->config->clientId,
                'clientSecret' => $this->config->clientSecret,
                'redirectUri' => $this->config->redirectUri,
                'urlAuthorize' => $authorizeUrl,
                'urlAccessToken' => $accessTokenUrl,
                'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
                'accessTokenMethod' => 'POST',
            ));

        try {
            // league/oauth2-client serializes params via http_build_query, so
            // passing an array produces scope[0]=openid... instead of the
            // space-delimited scope=openid%20profile%20email Microsoft
            // requires. Pass a space-delimited string (review fix #10).
            $authParams = array(
                'scope' => implode(' ', $scopesArray),
                'state' => $state,
                'response_type' => 'code',
                'prompt' => 'select_account',
            );
            // OIDC nonce (M4): binds the returned ID token to this
            // authorization request for replay protection.
            if (is_string($nonce) && trim($nonce) !== '') {
                $authParams['nonce'] = $nonce;
            }
            // PKCE (M5): S256 code_challenge so a leaked authorization code
            // can't be redeemed without the verifier held in our session.
            if (is_string($codeVerifier) && trim($codeVerifier) !== '') {
                $authParams['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                $authParams['code_challenge_method'] = 'S256';
            }
            $url = $provider->getAuthorizationUrl($authParams);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to build Azure authorization URL');
        }
        return $url;
    }

    /**
     * Invariant #8: handle the OAuth callback. Validates ID token signature +
     * iss/aud/exp claims. On success, returns the machine-name-safe username
     * derived from the ID-token claims. On any failure, returns null and the
     * caller MUST redirect to login.php?sso_error=<reason> WITHOUT issuing a
     * refresh token (publishers/installer-upgrade contract).
     *
     * @param array $get the $_GET superglobal copy from the PHP runtime
     * @return string|null machine-name-safe username OR null on failure
     */
    public function handleCallback(array $get)
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if (!isset($get['code']) || !is_string($get['code']) || trim($get['code']) === '') {
            return null;
        }
        $code = $get['code'];

        // Defense against CSRF on the OAuth callback. The state was generated
        // by login.php and stashed in $_SESSION; it MUST round-trip exactly.
        $stateSent = isset($get['state']) ? (string)$get['state'] : '';
        $stateStored = isset($_SESSION['oauth_state']) ? (string)$_SESSION['oauth_state'] : '';
        if ($stateStored === '' || $stateSent === '' || !hash_equals($stateStored, $stateSent)) {
            unset($_SESSION['oauth_state']);
            return null;
        }
        // One-shot: clear so a re-POST can't replay the same code.
        unset($_SESSION['oauth_state']);

        // Pull the OIDC nonce + PKCE verifier stashed by login.php before
        // clearing them, so we can bind the ID token to this request (M4)
        // and redeem the code with the verifier (M5). Both are optional:
        // legacy/test sessions that didn't set them simply skip the checks.
        $nonceStored = isset($_SESSION['oauth_nonce']) ? (string)$_SESSION['oauth_nonce'] : '';
        $codeVerifierStored = isset($_SESSION['oauth_code_verifier']) ? (string)$_SESSION['oauth_code_verifier'] : '';
        unset($_SESSION['oauth_nonce'], $_SESSION['oauth_code_verifier']);

        $tenantId = $this->config->tenantId;
        $clientId = $this->config->clientId;
        $clientSecret = $this->config->clientSecret;
        $redirectUri = $this->config->redirectUri;

        // Step 1: OAuth code -> access token (which carries id_token for OIDC).
        $tokenResult = null;
        if (self::$tokenExchanger !== null) {
            try {
                $tokenResult = call_user_func(self::$tokenExchanger, $code, $this);
            } catch (Throwable $e) {
                $tokenResult = null;
            }
        } else {
            if (!class_exists('\League\OAuth2\Client\Provider\GenericProvider')) {
                return null;
            }
            try {
                $provider = new \League\OAuth2\Client\Provider\GenericProvider(array(
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                    'redirectUri' => $redirectUri,
                    'urlAuthorize' => 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/authorize',
                    'urlAccessToken' => 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token',
                    'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
                    'accessTokenMethod' => 'POST',
                ));
                $tokenParams = array('code' => $code);
                // PKCE (M5): include the verifier when we sent a challenge.
                if ($codeVerifierStored !== '') {
                    $tokenParams['code_verifier'] = $codeVerifierStored;
                }
                $tokenResult = $provider->getAccessToken('authorization_code', $tokenParams);
            } catch (Throwable $e) {
                $tokenResult = null;
            }
        }
        if (!is_object($tokenResult)) {
            return null;
        }

        $values = array();
        if (method_exists($tokenResult, 'getValues')) {
            $candidate = $tokenResult->getValues();
            if (is_array($candidate)) {
                $values = $candidate;
            }
        }
        $idToken = isset($values['id_token']) ? (string)$values['id_token'] : '';
        if ($idToken === '') {
            return null;
        }

        // Step 2: fetch Microsoft JWKS for signature verification.
        try {
            $jwks = $this->fetchJwks($tenantId);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys']) || count($jwks['keys']) === 0) {
            return null;
        }

        $expectedIssuer = $this->getExpectedIssuer();
        $expectedAudience = (string)$clientId;

        // Step 3: verify signature + decoded claims.
        $claimsRaw = null;
        if (self::$idTokenVerifier !== null) {
            try {
                $claimsRaw = call_user_func(self::$idTokenVerifier, $idToken, $jwks, $expectedIssuer, $expectedAudience);
            } catch (Throwable $e) {
                $claimsRaw = null;
            }
        } else {
            try {
                $claimsRaw = $this->defaultVerifyIdToken($idToken, $jwks, $expectedIssuer, $expectedAudience);
            } catch (Throwable $e) {
                $claimsRaw = null;
            }
        }
        if (is_object($claimsRaw)) {
            $claims = json_decode(json_encode($claimsRaw), true);
        } else if (is_array($claimsRaw)) {
            $claims = $claimsRaw;
        } else {
            return null;
        }
        if (!is_array($claims)) {
            return null;
        }

        // Step 4: enforce claim contracts.
        $now = time();
        if (!isset($claims['iss']) || !is_string($claims['iss']) || $claims['iss'] !== $expectedIssuer) {
            return null;
        }
        $audOk = false;
        if (isset($claims['aud'])) {
            if (is_string($claims['aud']) && $claims['aud'] === $expectedAudience) {
                $audOk = true;
            } else if (is_array($claims['aud']) && in_array($expectedAudience, $claims['aud'], true)) {
                $audOk = true;
            }
        }
        if (!$audOk) {
            return null;
        }
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int)$claims['exp'] < $now) {
            return null;
        }
        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && (int)$claims['nbf'] > ($now + 5)) {
            return null;
        }

        // OIDC nonce (M4): when login.php sent a nonce, the ID token MUST
        // echo it back. A mismatch means the token wasn't minted for this
        // authorization request (replay / token substitution).
        if ($nonceStored !== '') {
            if (!isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($nonceStored, (string)$claims['nonce'])) {
                return null;
            }
        }

        // Step 5: derive a machine-name-safe username.
        $candidate = '';
        if (isset($claims['preferred_username']) && is_string($claims['preferred_username'])) {
            $candidate = $claims['preferred_username'];
        } else if (isset($claims['email']) && is_string($claims['email'])) {
            $candidate = $claims['email'];
        } else if (isset($claims['upn']) && is_string($claims['upn'])) {
            $candidate = $claims['upn'];
        } else if (isset($claims['oid']) && is_string($claims['oid']) && trim($claims['oid']) !== '') {
            $candidate = 'oid:' . $claims['oid'];
        }
        // Capture the raw identity (full UPN/email) before sanitization so
        // oauth-callback.php can bind users/<name> to a specific identity and
        // block cross-identity collisions (H2).
        $this->lastRawIdentity = (is_string($candidate) && $candidate !== '') ? $candidate : null;
        $safe = $this->sanitizeUserName($candidate);
        if ($safe === '') {
            return null;
        }
        return $safe;
    }

    /**
     * Reduce an arbitrary string from an ID-token claim (preferred_username,
     * email, upn, etc.) to a username suitable for use as `users/{name}`
     * directory on disk. The directory structure in HAXiam creates a folder
     * per user, and `liberate()` symlinks into it; the filesystem-safe subset
     * is [a-z0-9._-] with no leading dots/dashes/underscores.
     *
     * @param mixed $candidate
     * @return string empty string on failure (caller MUST treat as rejection)
     */
    public function sanitizeUserName($candidate)
    {
        if (!is_string($candidate)) {
            return '';
        }
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }
        // Email-like inputs collapse to the local-part.
        $at = strpos($candidate, '@');
        if ($at !== false && $at > 0) {
            $candidate = substr($candidate, 0, $at);
        }
        $safe = strtolower($candidate);
        // Drop anything outside [a-z0-9._-]. Note we are NOT using `?.` per
        // the project-wide rule, so plain-string indexing elsewhere works.
        $safe = preg_replace('/[^a-z0-9._-]+/', '', $safe);
        if ($safe === null) {
            return '';
        }
        // Trim noise.
        $safe = trim($safe, '._-');
        if ($safe === '') {
            return '';
        }
        if (strlen($safe) > 64) {
            $safe = substr($safe, 0, 64);
            $safe = trim($safe, '._-');
        }
        // Final regex sanity: must start with [a-z0-9], may end with [a-z0-9].
        if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $safe)) {
            return '';
        }
        return $safe;
    }

    /**
     * Fetch the Microsoft v2.0 signing keys (JWKS). Swappable via the test
     * seam setJwksHttpFetcher().
     *
     * @param string $tenantId
     * @return array
     * @throws RuntimeException
     */
    protected function fetchJwks($tenantId)
    {
        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/discovery/v2.0/keys';
        if (self::$jwksHttpFetcher !== null) {
            $result = call_user_func(self::$jwksHttpFetcher, $url);
            if (!is_array($result)) {
                throw new RuntimeException('JWKS override returned non-array');
            }
            return $result;
        }
        // Production cache (L3): avoid hitting the discovery endpoint on every
        // callback. The test seam above bypasses this so tests stay deterministic.
        if (isset(self::$jwksCache[$tenantId]['expires']) && time() < self::$jwksCache[$tenantId]['expires']) {
            return self::$jwksCache[$tenantId]['data'];
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for JWKS fetch');
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        // Explicit TLS peer verification (L3): the JWKS is the root of trust
        // for ID-token signatures, so be explicit rather than rely on cURL
        // defaults. Use the system CA bundle when one is configured.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        if (defined('CURLOPT_SSL_VERIFYHOST')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
        $caFile = ini_get('openssl.cafile');
        if (is_string($caFile) && $caFile !== '' && is_file($caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || (int)$httpCode < 200 || (int)$httpCode >= 300) {
            throw new RuntimeException('Microsoft JWKS fetch failed (HTTP ' . (int)$httpCode . ')');
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Microsoft JWKS did not parse as JSON');
        }
        self::$jwksCache[$tenantId] = array('data' => $decoded, 'expires' => time() + self::$jwksCacheTtl);
        return $decoded;
    }

    /**
     * Production ID-token verifier using Firebase\JWT\JWT::decode against the
     * parsed JWKS.
     *
     * @param string $idToken raw JWT (header.payload.signature)
     * @param array  $jwks    keys array as returned by the JWKS endpoint
     * @param string $expectedIssuer
     * @param string $expectedAudience
     * @return object|array decoded claims
     * @throws RuntimeException when validation fails or libraries are missing
     */
    protected function defaultVerifyIdToken($idToken, $jwks, $expectedIssuer, $expectedAudience)
    {
        if (!class_exists('Firebase\\JWT\\JWT') || !class_exists('Firebase\\JWT\\JWK')) {
            throw new RuntimeException('firebase/php-jwt is not available — run composer install');
        }
        if (!is_array($jwks) || empty($jwks['keys'])) {
            throw new RuntimeException('JWKS keys list is empty');
        }
        // Defense-in-depth (L1): reject any token whose JOSE header isn't
        // RS256 before signature verification. firebase/php-jwt v7 binds the
        // algorithm to the parsed Key, but an explicit allowlist guards
        // against alg-confusion / alg=none regressions in future versions.
        $headerJson = false;
        $headerParts = explode('.', $idToken);
        if (isset($headerParts[0])) {
            $headerJson = base64_decode(strtr($headerParts[0], '-_', '+/'), true);
        }
        if ($headerJson === false || $headerJson === '') {
            throw new RuntimeException('ID token header is not valid base64url');
        }
        $header = json_decode($headerJson, true);
        if (!is_array($header) || !isset($header['alg']) || $header['alg'] !== 'RS256') {
            throw new RuntimeException('ID token alg must be RS256');
        }
        // Allow a 30-second clock leeway for production-distributed deployments
        // before issuer-side nbf/exp asserts fail.
        \Firebase\JWT\JWT::$leeway = 30;
        $keys = \Firebase\JWT\JWK::parseKeySet($jwks);
        $decoded = \Firebase\JWT\JWT::decode($idToken, $keys);
        return $decoded;
    }

    // ------------------------------------------------------------------
    //  Test seams (also used for ad-hoc diagnostic overrides).
    // ------------------------------------------------------------------

    /**
     * Override the JWKS HTTP fetcher. Receives ($url, $self) — should return
     * the parsed JWKS array.
     * @param callable $fn
     */
    public static function setJwksHttpFetcher($fn)
    {
        self::$jwksHttpFetcher = $fn;
    }

    public static function resetJwksHttpFetcher()
    {
        self::$jwksHttpFetcher = null;
    }

    /**
     * Override the ID-token verifier. Receives
     *   ($idToken, $jwks, $expectedIssuer, $expectedAudience)
     * should return an object/array of decoded claims on success or throw.
     * @param callable $fn
     */
    public static function setIdTokenVerifier($fn)
    {
        if ($fn !== null && !is_callable($fn)) {
            throw new InvalidArgumentException('ID-token verifier must be callable');
        }
        self::$idTokenVerifier = $fn;
    }

    public static function resetIdTokenVerifier()
    {
        self::$idTokenVerifier = null;
    }

    /**
     * Override the OAuth code → token exchange step. Receives ($code, $self).
     * Should return an AccessToken-shaped object (League\OAuth2\Client\Token\AccessToken)
     * that exposes a `getValues()` method including the `id_token` key, or null
     * on failure.
     * @param callable $fn
     */
    public static function setTokenExchanger($fn)
    {
        if ($fn !== null && !is_callable($fn)) {
            throw new InvalidArgumentException('Token exchanger must be callable');
        }
        self::$tokenExchanger = $fn;
    }

    public static function resetTokenExchanger()
    {
        self::$tokenExchanger = null;
    }

    /**
     * Override the GenericProvider factory. Receives
     *   ($clientId, $clientSecret, $authorizeUrl, $accessTokenUrl, $redirectUri)
     * should return a configured GenericProvider.
     * @param callable $fn
     */
    public static function setProviderFactory($fn)
    {
        if ($fn !== null && !is_callable($fn)) {
            throw new InvalidArgumentException('Provider factory must be callable');
        }
        self::$providerFactory = $fn;
    }

    public static function resetProviderFactory()
    {
        self::$providerFactory = null;
    }

    /**
     * Clear the in-memory JWKS cache (L3). Tests call this between cases so
     * a cached keyset from one scenario can't leak into another.
     */
    public static function resetJwksCache()
    {
        self::$jwksCache = array();
    }

    // ------------------------------------------------------------------
    //  Identity binding / collision guard (H2).
    //  Each users/<name> directory is bound to the full UPN/email that first
    //  claimed it via a marker in _iamConfig/identities/<name>.json (that dir
    //  is vhost-denied + git-ignored, so the marker is not web-accessible).
    //  On a subsequent login, a DIFFERENT identity that sanitizes to the
    //  same name is blocked instead of being silently dropped into the
    //  existing user's space.
    // ------------------------------------------------------------------

    /**
     * Decide whether a sanitized username can safely be bound to the given
     * raw identity. Returns one of:
     *   'new'      - the user directory does not exist yet (caller liberates,
     *                then calls bindNewUserIdentity()).
     *   'ok'       - the directory is bound to this identity (or a legacy
     *                un-bound directory was just back-filled).
     *   'conflict' - the directory is bound to a DIFFERENT identity; the
     *                caller MUST block (redirect to login.php?sso_error=
     *                identity_conflict) and issue no refresh token.
     *
     * @param string $userDir     absolute path to users/<name>
     * @param string $markerPath  absolute path to _iamConfig/identities/<name>.json
     * @param string $rawIdentity full UPN/email from the ID token
     * @return string
     */
    public static function resolveIdentityConflict($userDir, $markerPath, $rawIdentity)
    {
        if (!is_string($markerPath) || trim($markerPath) === '') {
            return 'conflict';
        }
        $userExists = is_string($userDir) && is_dir($userDir);
        if (!$userExists) {
            return 'new';
        }
        if (!is_file($markerPath)) {
            // Legacy directory pre-dating this guard. Back-fill so a future
            // cross-identity collision is caught rather than silently allowed.
            self::writeIdentityMarker($markerPath, $rawIdentity);
            return 'ok';
        }
        $raw = @file_get_contents($markerPath);
        if ($raw === false) {
            // Unreadable marker — don't lock the user out; treat as bound.
            return 'ok';
        }
        $data = json_decode($raw, true);
        $bound = (is_array($data) && isset($data['upn']) && is_string($data['upn'])) ? $data['upn'] : '';
        if ($bound === '') {
            // Corrupt/empty marker — back-fill with the current identity.
            self::writeIdentityMarker($markerPath, $rawIdentity);
            return 'ok';
        }
        if ($bound === $rawIdentity) {
            return 'ok';
        }
        return 'conflict';
    }

    /**
     * Bind a freshly-liberated user directory to its identity. No-op if the
     * marker already exists (e.g. resolveIdentityConflict already wrote it).
     *
     * @param string $userDir     absolute path to users/<name>
     * @param string $markerPath  absolute path to _iamConfig/identities/<name>.json
     * @param string $rawIdentity full UPN/email from the ID token
     */
    public static function bindNewUserIdentity($userDir, $markerPath, $rawIdentity)
    {
        if (!is_string($userDir) || !is_dir($userDir)) {
            return;
        }
        if (!is_file($markerPath)) {
            self::writeIdentityMarker($markerPath, $rawIdentity);
        }
    }

    /**
     * Write the identity marker (0640, locked write). The parent dir is
     * created 0750 if missing.
     *
     * @param string $markerPath
     * @param string $rawIdentity
     */
    protected static function writeIdentityMarker($markerPath, $rawIdentity)
    {
        $dir = dirname($markerPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $payload = array('upn' => is_string($rawIdentity) ? $rawIdentity : '', 'createdAt' => time());
        @file_put_contents($markerPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($markerPath, 0640);
    }
}
