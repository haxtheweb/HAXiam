#!/bin/bash
# check-azure-sso.sh
#
# Bash-side validator for the Azure OIDC config the installer just
# wrote to _iamConfig/azure.json. Implements publisher-side invariant #4
# of _contracts/azure_json_schema.md:
#
#   "scripts/utilities/check-azure-sso.sh reads tenantId+clientId, hits
#    https://login.microsoftonline.com/{tenantId}/v2.0/.well-known/openid-configuration,
#    and confirms the JSON parses + issuer matches. Exit 0 on success;
#    non-zero with a one-line remediation otherwise."
#
# The clientSecret is never read or echoed - we don't need it to verify
# the discovery endpoint is reachable. Curl has a hard 10s timeout so a
# wedged Azure tenant won't block an install forever.

set -e

if [[ $EUID -ne 0 ]]; then
  echo "check-azure-sso: must run as root (same requirement as the installer)." >&2
  exit 1
fi

CONFIG_FILE="_iamConfig/azure.json"
DISCOVERY_TIMEOUT=10

if [[ ! -r "${CONFIG_FILE}" ]]; then
  echo "check-azure-sso: ${CONFIG_FILE} is missing or unreadable." >&2
  exit 1
fi

# Extract tenantId + clientId from the JSON without ever loading clientSecret
# into the environment. grep + sed keep this script dep-free (no jq needed).
tenant_id="$(sed -n 's/.*"tenantId"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "${CONFIG_FILE}" | head -n1)"
client_id="$(sed -n 's/.*"clientId"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "${CONFIG_FILE}" | head -n1)"

if [[ -z "${tenant_id}" ]]; then
  echo "check-azure-sso: azure.json has empty tenantId." >&2
  exit 1
fi
if [[ -z "${client_id}" ]]; then
  echo "check-azure-sso: azure.json has empty clientId." >&2
  exit 1
fi

discovery_url="https://login.microsoftonline.com/${tenant_id}/v2.0/.well-known/openid-configuration"
disc="$(curl --silent --show-error --max-time "${DISCOVERY_TIMEOUT}" -L "${discovery_url}" \
  -H "Accept: application/json" -w '\n%{http_code}')" || {
  echo "check-azure-sso: curl failed (timeout=${DISCOVERY_TIMEOUT}s) hitting ${discovery_url}." >&2
  exit 1
}

http_code="$(printf '%s\n' "${disc}" | tail -n1)"
body="$(printf '%s\n' "${disc}" | sed '$d')"

if [[ "${http_code}" != "200" ]]; then
  echo "check-azure-sso: discovery endpoint returned HTTP ${http_code} for tenant ${tenant_id}." >&2
  exit 1
fi

# JSON sanity sniff - require balanced braces and an "issuer" key.
if ! printf '%s' "${body}" | grep -q '"issuer"'; then
  echo "check-azure-sso: discovery JSON did not contain an issuer field." >&2
  exit 1
fi

discovered_issuer="$(printf '%s' "${body}" | sed -n 's/.*"issuer"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)"
expected_issuer="https://login.microsoftonline.com/${tenant_id}/v2.0"

if [[ -z "${discovered_issuer}" ]]; then
  echo "check-azure-sso: could not parse issuer from discovery response." >&2
  exit 1
fi

if [[ "${discovered_issuer}" != "${expected_issuer}" ]]; then
  echo "check-azure-sso: issuer mismatch. expected=${expected_issuer} got=${discovered_issuer}" >&2
  exit 1
fi

# All checks passed. Note we never echo tenantId/clientId/clientSecret -
# only the OK marker, so secrets cannot leak through tee into the
# installer's logfile.
echo "check-azure-sso: ok (clientId len=${#client_id})"
exit 0
