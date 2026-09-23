#!/bin/bash
# testing/docker-test.sh
#
# End-to-end test for the unified installer (scripts/install/haxiam-install.sh)
# inside testing/Dockerfile.ubuntu24. Builds the image, runs the installer
# inside a freshly-launched container with the working tree bind-mounted at
# /var/www/iam, asserts the installer's exit code, and curl-checks that the
# freshly-installed HAXiam is responsive on port 80.
#
# Exits 0 on success, non-zero on failure. Each failed assertion prints a
# remediation line so CI logs explain what failed.
#
# Usage:  bash testing/docker-test.sh
#         DOCKER_PORT=8080 bash testing/docker-test.sh
#         DOCKER_IMAGE_TAG=haxiamspec-test:latest bash testing/docker-test.sh
#
# Local + CI integration: this script does NOT assume any prior
# /var/www/iam install on the host — it operates purely inside a
# throwaway container.

set -euo pipefail

# The installer uses tput for output formatting; without TERM set, tput
# exits 2 which trips the installer's `set -e`/set -o pipefail. Set a sane
# terminal type so the installer can render its colour output and proceed
# past its gating tput calls. Inherited by all child docker run invocations.
export TERM="${TERM:-xterm-256color}"

DOCKER="$(command -v docker)"
if [[ -z "${DOCKER}" ]]; then
  echo "FAIL: docker CLI not found on PATH; install Docker Engine first."
  exit 1
fi
DOCKER_IMAGE_TAG="${DOCKER_IMAGE_TAG:-haxiamspec-test:latest}"
DOCKER_PORT="${DOCKER_PORT:-8080}"
EXIT_OK=0
EXIT_BUILD=10
EXIT_FLAG=11
EXIT_ENDTOEND=12
EXIT_HTTP=13
EXIT_IDEMPOTENT=14

passes=0
failures=0
record_pass() { passes=$((passes+1)); echo "PASS: $*"; }
record_fail() { failures=$((failures+1)); echo "FAIL: $*"; }

# ----------------------------------------------------------------------
# 0. Resolve the working tree.
# ----------------------------------------------------------------------
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_ROOT="$( cd "${SCRIPT_DIR}/../" && pwd )"
cd "${REPO_ROOT}"

# Bail early with a clear remediation if the installer script isn't where
# we expect it (would indicate the merge didn't bring haxiam-install.sh in).
if [[ ! -f scripts/install/haxiam-install.sh ]]; then
  echo "FAIL: scripts/install/haxiam-install.sh missing — merge plan/3070-installer-upgrade first."
  exit ${EXIT_FLAG}
fi

# ----------------------------------------------------------------------
# 1. Build the image.
# ----------------------------------------------------------------------
echo "=== 1/4 Build ==="
if ! ${DOCKER} build \
      -f testing/Dockerfile.ubuntu24 \
      -t "${DOCKER_IMAGE_TAG}" \
      "${REPO_ROOT}" >/tmp/haxiam-docker-build.log 2>&1; then
  echo "FAIL: docker build failed. Tail of /tmp/haxiam-docker-build.log:"
  tail -40 /tmp/haxiam-docker-build.log
  exit ${EXIT_BUILD}
fi
record_pass "docker build succeeded (${DOCKER_IMAGE_TAG})"

# ----------------------------------------------------------------------
# 2. Installer flag-surface unit tests (one-shot container, no install).
# These run as a bash one-liner inside the image so they don't need a
# bind mount or network beyond what the image already has baked in.
# ----------------------------------------------------------------------
echo "=== 2/4 Flag surface ==="
# The installer's contract requires these exit codes (.5 from -h on a
# non-help flag is exit 1; .5 from --le without --domain is exit 6; .5
# from --azure-tenant alone is exit 1; .5 from --le + --cert is exit 1).
# See _contracts/installer_flag_surface.md invariant #3.

# Note: the image bakes /var/www/iam itself (no host bind mount needed for
# these one-shot tests). The end-to-end test (phase 3) is the only phase
# that needs a writable mount.

# Help: exit 0 + grep for locked flag names.
# Capture output first, then grep, so pipefail doesn't cause a false
# failure when grep exits early and tee gets SIGPIPE (review
# previously-missed #3).
${DOCKER} run --rm -e TERM "${DOCKER_IMAGE_TAG}" \
      one-shot --help > /tmp/haxiam-docker-help.log 2>&1 || true
if grep -q -- "--azure-redirect-base" /tmp/haxiam-docker-help.log; then
  record_pass "--help exits 0 and mentions --azure-redirect-base"
else
  record_fail "--help didn't mention --azure-redirect-base"
  cat /tmp/haxiam-docker-help.log
fi

# --le without --domain: must exit 6 (TLS phase failure).
# Capture ONLY the exit code: redirect stdout+stderr to the log (otherwise
# the installer's stdout pollutes the captured value) and use `|| var=$?`
# so set -e never fires on the expected non-zero exit.
le_exit=0
${DOCKER} run --rm -e TERM "${DOCKER_IMAGE_TAG}" one-shot --le >/tmp/haxiam-docker-le.log 2>&1 || le_exit=$?
if [[ "${le_exit}" == "6" ]]; then
  record_pass "--le alone exits 6 (TLS phase)"
else
  record_fail "--le alone exited ${le_exit}, expected 6 — see /tmp/haxiam-docker-le.log"
fi

# --azure-tenant alone (no client/secret): must exit 1 (pre-flight).
az_exit=0
${DOCKER} run --rm -e TERM "${DOCKER_IMAGE_TAG}" one-shot --azure-tenant only >/tmp/haxiam-docker-az.log 2>&1 || az_exit=$?
if [[ "${az_exit}" == "1" ]]; then
  record_pass "--azure-tenant alone exits 1 (flag validation)"
else
  record_fail "--azure-tenant alone exited ${az_exit}, expected 1"
fi

# Full --azure-* triple with a fake tenant. The check-azure-sso.sh curl
# will fail with exit 5 when DNS to Microsoft is reachable but the tenant
# GUID is bogus; depends on egress network. Accept 0 or 5.
az_full_exit=0
${DOCKER} run --rm -e TERM "${DOCKER_IMAGE_TAG}" one-shot \
  --azure-tenant 11111111-2222-3333-4444-555555555555 \
  --azure-client 11111111-2222-3333-4444-555555555555 \
  --azure-secret testsecret-not-real \
  --azure-redirect-base https://hax.test.local >/tmp/haxiam-docker-az-full.log 2>&1 || az_full_exit=$?
case "${az_full_exit}" in
  5|0)
    record_pass "full Azure flags exit ${az_full_exit} (expected 0 or 5)" ;;
  *)
    record_fail "full Azure flags exited ${az_full_exit}, expected 0 or 5" ;;
esac

# Unknown flag (--storage-limit-gb) must exit 1 (flag validation).
# This verifies the fix for the JPS deploy-app.sh bug where
# --storage-limit-gb was passed but the installer doesn't know it.
unk_exit=0
${DOCKER} run --rm -e TERM "${DOCKER_IMAGE_TAG}" one-shot --storage-limit-gb 10 >/tmp/haxiam-docker-unk.log 2>&1 || unk_exit=$?
if [[ "${unk_exit}" == "1" ]]; then
  record_pass "--storage-limit-gb (unknown flag) exits 1 (flag validation)"
else
  record_fail "--storage-limit-gb exited ${unk_exit}, expected 1"
fi

# ----------------------------------------------------------------------
# 3. End-to-end install. Stage a writable copy of the repo under /tmp so
# the installer's writes (and the idempotency restart in phase 4) work
# against an isolated tree, NOT the operator's checkout.
# ----------------------------------------------------------------------
echo "=== 3/4 End-to-end install + HTTP smoke ==="
STAGE_DIR="$(mktemp -d -t haxiam-test-stage.XXXXXX)"
# The installer runs as root inside the container, so the staged tree ends
# up root-owned and a host-user rm gets Permission denied. chown the tree
# back to the host uid via a throwaway container before removing it.
cleanup_stage() {
  if [[ -n "${STAGE_DIR}" && -d "${STAGE_DIR}" ]]; then
    ${DOCKER} run --rm --entrypoint chown \
      -v "${STAGE_DIR}:/stage" "${DOCKER_IMAGE_TAG}" \
      -R "$(id -u):$(id -g)" /stage >/dev/null 2>&1 || true
    rm -rf "${STAGE_DIR}" 2>/dev/null || true
  fi
}
trap cleanup_stage EXIT
# rsync-like copy: cp -a preserves mode bits so the installer reads them
# correctly. Exclude the same .gitignored dirs the installer's idempotency
# check relies on.
cp -a "${REPO_ROOT}/." "${STAGE_DIR}/"
rm -rf "${STAGE_DIR}/_iamConfig" \
       "${STAGE_DIR}/cores" \
       "${STAGE_DIR}/users" \
       "${STAGE_DIR}/users_sites" \
       "${STAGE_DIR}/vendor"
record_pass "staged repo copy at ${STAGE_DIR}"

container=$(${DOCKER} run --rm -d -e TERM \
      -p "${DOCKER_PORT}:80" \
      -v "${STAGE_DIR}:/var/www/iam" \
      "${DOCKER_IMAGE_TAG}" 2>&1) || {
  record_fail "docker run -d failed: ${container}"
  exit ${EXIT_ENDTOEND}
}

# `container` is the container ID; verify it's running.
if ! ${DOCKER} ps -q --filter "id=${container}" | grep -q .; then
  record_fail "container ${container} not running"
  ${DOCKER} logs "${container}" || true
  exit ${EXIT_ENDTOEND}
fi
record_pass "docker run -d container ${container:0:12} started"

# Wait for installer to finish writing _iamConfig/config.cfg with a
# haxiam= line, OR for the installer's "Existing install detected" path
# (idempotent). Timeout 5 minutes — npm install + composer can take a
# while on first run.

ok=0
for i in $(seq 1 60); do
  if ${DOCKER} exec "${container}" \
        grep -Eq '^[[:space:]]*haxiam[[:space:]]*=' \
        /var/www/iam/_iamConfig/config.cfg 2>/dev/null; then
    ok=1; break
  fi
  sleep 5
done
if [[ ${ok} -ne 1 ]]; then
  record_fail "container never reached a fully-installed state in 5 min"
  ${DOCKER} logs "${container}" | tail -200
  ${DOCKER} stop "${container}" >/dev/null 2>&1 || true
  exit ${EXIT_ENDTOEND}
fi
record_pass "container reached installed state"

# Wait for apache to be ready (HEALTHCHECK or simple curl). Smoke the
# site root: index.php delegates to grantFreedom.php which redirects or
# renders — either way a 2xx/3xx proves apache + php-fpm are wired up.
apk=0
for i in $(seq 1 30); do
  if curl --silent --fail --max-time 5 "http://127.0.0.1:${DOCKER_PORT}/" >/dev/null 2>&1; then
    apk=1; break
  fi
  sleep 2
done
if [[ ${apk} -ne 1 ]]; then
  record_fail "apache did not respond on port ${DOCKER_PORT} inside 60 s"
  ${DOCKER} logs "${container}" | tail -80
  ${DOCKER} stop "${container}" >/dev/null 2>&1 || true
  exit ${EXIT_HTTP}
fi
record_pass "apache responded on http://127.0.0.1:${DOCKER_PORT}/"

# /login.php should be reachable too (the auth entry point; expect a
# non-5xx response — 200 renders the form, 3xx means already-authed).
login_code=$(curl --silent --output /dev/null \
  --max-time 5 -w '%{http_code}' \
  "http://127.0.0.1:${DOCKER_PORT}/login.php")
# Only accept 2xx/3xx as a pass — a 404 (missing login.php) should NOT
# count as a healthy install (review fix #11).
case "${login_code}" in
  2*|3*)
    record_pass "/login.php responded ${login_code} (2xx/3xx)" ;;
  *)
    record_fail "/login.php responded ${login_code} (expected 2xx/3xx)" ;;
esac

# ----------------------------------------------------------------------
# 4. Idempotency. We EXPLICITLY run the installer a second time inside
# the container (via docker exec one-shot) against the populated
# _iamConfig/config.cfg — installer must NOT clobber anything (invariant
# #2). We assert by file count comparison and the "Existing install
# detected" banner (review fix #13: the old test only checked the
# entrypoint skip message, never actually invoked the installer twice).
# ----------------------------------------------------------------------
echo "=== 4/4 Idempotency ==="

# Capture file count BEFORE the explicit second installer run.
pre_count=$(${DOCKER} exec "${container}" sh -c \
  'find /var/www/iam -type f -not -path "*/vendor/*" -not -path "*/cores/*" 2>/dev/null | wc -l' 2>/dev/null || echo 0)
pre_count="${pre_count//[!0-9]/}"
pre_count="${pre_count:-0}"
record_pass "captured file count before second installer run: ${pre_count}"

# Explicitly run the installer a second time inside the running container.
# This exercises the installer's own idempotency path (not just the
# entrypoint's skip check). Capture stdout+stderr to a log.
idem_exit=0
${DOCKER} exec -e TERM "${container}" \
  bash /var/www/iam/scripts/install/haxiam-install.sh \
  --distro auto --skip-le --non-interactive \
  >/tmp/haxiam-docker-idem.log 2>&1 || idem_exit=$?

if [[ ${idem_exit} -ne 0 ]]; then
  record_fail "second explicit installer run exited ${idem_exit} (expected 0)"
  cat /tmp/haxiam-docker-idem.log | tail -30
else
  record_pass "second explicit installer run exited 0"
fi

# Check the log for the "Existing install detected" banner.
if grep -q 'Existing install detected' /tmp/haxiam-docker-idem.log; then
  record_pass "second installer run detected existing install (idempotent path)"
else
  record_fail "second installer run did not log 'Existing install detected'"
  cat /tmp/haxiam-docker-idem.log | tail -30
fi

# Capture file count AFTER the second run and compare.
post_count=$(${DOCKER} exec "${container}" sh -c \
  'find /var/www/iam -type f -not -path "*/vendor/*" -not -path "*/cores/*" 2>/dev/null | wc -l' 2>/dev/null || echo 0)
post_count="${post_count//[!0-9]/}"
post_count="${post_count:-0}"
if [[ "${pre_count}" == "${post_count}" ]]; then
  record_pass "file count unchanged after second run (${pre_count} == ${post_count})"
else
  record_fail "file count changed: ${pre_count} -> ${post_count} (idempotency violated)"
fi

# Verify apache still responds after the second installer run.
apk=0
for i in $(seq 1 30); do
  if curl --silent --fail --max-time 5 "http://127.0.0.1:${DOCKER_PORT}/" >/dev/null 2>&1; then
    apk=1; break
  fi
  sleep 2
done
if [[ ${apk} -ne 1 ]]; then
  record_fail "apache did not respond after second installer run"
else
  record_pass "apache responded after second installer run"
fi

${DOCKER} stop "${container}" >/dev/null 2>&1 || true

# ----------------------------------------------------------------------
# Summary
# ----------------------------------------------------------------------
echo
echo "${passes} passed, ${failures} failed"
if [[ ${failures} -gt 0 ]]; then
  exit 1
fi
exit ${EXIT_OK}
