<?php
/**
 * Nightly CI only: create the mock IdP's throwaway signing keys.
 *
 * Usage: php keygen.php <state-dir> <tenant-id> <client-id> <redirect-uri>
 *
 * Writes:
 *   signing.pem  RS256 key the mock IdP signs ID tokens with (in jwks.json)
 *   rogue.pem    a second key that is NOT published, used to forge tokens
 *   jwks.json    public key set returned to AzureOIDC's JWKS seam
 *   idp.json     tenant / client / redirect the mock IdP enforces
 *   codes/       one-shot authorization codes
 * Keys are generated fresh on every run and never leave the CI runner.
 */
if ($argc < 5) {
    fwrite(STDERR, "usage: php keygen.php <state-dir> <tenant-id> <client-id> <redirect-uri>\n");
    exit(2);
}
list(, $dir, $tenant, $client, $redirect) = $argv;
@mkdir($dir . '/codes', 0770, true);

function b64url($bin)
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$jwks = array('keys' => array());
foreach (array('signing', 'rogue') as $name) {
    $key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
    if ($key === false) {
        fwrite(STDERR, "openssl_pkey_new failed\n");
        exit(1);
    }
    openssl_pkey_export($key, $pem);
    file_put_contents($dir . '/' . $name . '.pem', $pem);
    chmod($dir . '/' . $name . '.pem', 0640);
    if ($name === 'signing') {
        $details = openssl_pkey_get_details($key);
        $jwks['keys'][] = array(
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'haxiam-nightly',
            'n' => b64url($details['rsa']['n']),
            'e' => b64url($details['rsa']['e']),
        );
    }
}
file_put_contents($dir . '/jwks.json', json_encode($jwks));
file_put_contents($dir . '/idp.json', json_encode(array(
    'tenantId' => $tenant,
    'clientId' => $client,
    'redirectUri' => $redirect,
)));
echo "mock IdP keys written to $dir\n";
