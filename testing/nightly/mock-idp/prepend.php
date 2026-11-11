<?php
/**
 * Nightly CI only: stand-in identity provider wiring for HAXiam.
 *
 * Loaded through php-fpm's auto_prepend_file by testing/nightly/boot.sh on a
 * throwaway CI install. It is never part of a real deployment: nothing in the
 * HAXiam tree includes it, and boot.sh refuses to run outside CI.
 *
 * It swaps only the network edges of AzureOIDC through its documented test
 * seams, so the real login.php -> oauth-callback.php -> iamConfig.php code runs:
 *   - authorize URL      -> the local mock IdP (idp.haxiam.test/authorize.php)
 *   - code -> token       -> one-shot code file written by the mock IdP
 *   - JWKS fetch          -> the mock IdP's public key set
 * ID-token verification is NOT swapped: the real firebase/php-jwt RS256 check
 * (signature, iss, aud, tid, exp, nonce) runs against the mock key set.
 */
$__mockIdpState = getenv('HAXIAM_MOCK_IDP_STATE') ?: '/var/lib/haxiam-mockidp';
$__mockIdpHost = getenv('HAXIAM_MOCK_IDP_HOST') ?: 'http://idp.haxiam.test';
$__iamRoot = getenv('HAXIAM_ROOT') ?: '/var/www/iam';
if (!is_file($__iamRoot . '/system/lib/AzureOIDC.php')) {
    return;
}
include_once $__iamRoot . '/system/lib/AzureOIDC.php';

class HaxiamMockIdpProvider
{
    private $base;
    private $clientId;
    private $redirectUri;
    public function __construct($base, $clientId, $redirectUri)
    {
        $this->base = $base;
        $this->clientId = $clientId;
        $this->redirectUri = $redirectUri;
    }
    public function getAuthorizationUrl(array $params = array())
    {
        $params['client_id'] = $this->clientId;
        $params['redirect_uri'] = $this->redirectUri;
        return rtrim($this->base, '/') . '/authorize.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

class HaxiamMockIdpToken
{
    private $values;
    public function __construct(array $values)
    {
        $this->values = $values;
    }
    public function getValues()
    {
        return $this->values;
    }
}

AzureOIDC::setProviderFactory(function ($clientId, $clientSecret, $authorizeUrl, $accessTokenUrl, $redirectUri) use ($__mockIdpHost) {
    return new HaxiamMockIdpProvider($__mockIdpHost, $clientId, $redirectUri);
});

AzureOIDC::setTokenExchanger(function ($code, $self) use ($__mockIdpState) {
    if (!is_string($code) || preg_match('/^[a-f0-9]{32}$/', $code) !== 1) {
        return null;
    }
    $file = $__mockIdpState . '/codes/' . $code . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    // Authorization codes are one-shot, like a real IdP.
    @unlink($file);
    if (!is_array($data) || !isset($data['id_token']) || !is_string($data['id_token'])) {
        return null;
    }
    return new HaxiamMockIdpToken(array('id_token' => $data['id_token']));
});

AzureOIDC::setJwksHttpFetcher(function ($url) use ($__mockIdpState) {
    $jwks = json_decode((string) @file_get_contents($__mockIdpState . '/jwks.json'), true);
    return is_array($jwks) ? $jwks : array('keys' => array());
});
