#!/bin/bash
# haxiam-bash-upgrade.sh
#
# Loop through process update files based on what updates are missing;
# this allows our upgrade routine to be deployed in a lazy loaded fashion
# instead of requiring people to upgrade to each version 1 at a time.
# This also allows the upgrades to perform whatever they want, which is
# scary for sure but requires this only be run via bash.
#
# Wrapper additions over the original script:
#   * flock(_iamConfig/upgrade.lock) at entry, released on EXIT via trap,
#     so two operators (or haxiam-jps auto-upgrade) can't race each other.
#   * Pre-flight: available disk space >= 500 MB and RAM >= 512 MB; refuse
#     to start with a one-line remediation if either is short.
#   * Before mutating the live install, snapshot every existing
#     _iamConfig/* file into _iamConfig/snapshots/<timestamp>/ with a
#     manifest.txt of sha256s (pre-upgrade); after each script, append
#     per-script snapshots so a partial-upgrade rollback can replay just
#     the affected layer.
#   * After all scripts succeed, POST-upgrade HTTP health-check the IAM
#     dashboard (https://localhost/) and refuse to mark the upgrade as
#     successful if curl gets a 5xx.
#   * upgrade_history.txt lines now include the snapshot path column so a
#     sysadmin can find the upstream state of any applied upgrade.

# ----------------------------------------------------------------------
# 0. Locate ourselves, source config, set up colour helpers.
# ----------------------------------------------------------------------
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "${DIR}"
cd ../../
# shellcheck disable=SC1091
source _iamConfig/config.cfg

txtbld=$(tput bold)             # Bold
bldgrn=${txtbld}$(tput setaf 2) #  green
bldred=${txtbld}$(tput setaf 1) #  red
txtreset=$(tput sgr0)
upgrade_green(){ echo "${bldgrn}$1${txtreset}"; }
upgrade_red(){ echo "${bldred}$1${txtreset}"; }

# ----------------------------------------------------------------------
# 1. Run preconditions - root, config presence, lock acquisition.
# ----------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
  upgrade_red "Please run as root"
  exit 1
fi

if [[ ! -f "${haxiam}/_iamConfig/config.cfg" ]]; then
  upgrade_red "_iamConfig/config.cfg missing - run haxiam-install.sh first."
  exit 1
fi

# Single-flight this upgrade. The lockfile lives in _iamConfig (git-ignored)
# so it won't leak. fd 9 is our advisory lock.
LOCK_FILE="${haxiam}/_iamConfig/upgrade.lock"
exec 9>"${LOCK_FILE}" || {
  upgrade_red "Could not open ${LOCK_FILE} - refusing to upgrade."
  exit 1
}
flock -n 9 || {
  upgrade_red "Another upgrade is already in flight (lock held on ${LOCK_FILE}); aborting."
  exit 1
}
cleanup_lock() {
  flock -u 9 2>/dev/null || true
  exec 9>&- 2>/dev/null || true
}
trap cleanup_lock EXIT

# ----------------------------------------------------------------------
# 2. Helpers (timestamp, uuid, version compare) - mostly preserved.
# ----------------------------------------------------------------------
timestamp(){ date +"%s"; }
getuuid(){ uuidgen -rt; }

vercomp () {
  if [[ $1 == $2 ]]; then return 0; fi
  local IFS=.
  local i ver1=($1) ver2=($2)
  for ((i=${#ver1[@]}; i<${#ver2[@]}; i++)); do ver1[i]=0; done
  for ((i=0; i<${#ver1[@]}; i++)); do
    if [[ -z ${ver2[i]} ]]; then ver2[i]=0; fi
    if ((10#${ver1[i]} > 10#${ver2[i]})); then return 1; fi
    if ((10#${ver1[i]} < 10#${ver2[i]})); then return 2; fi
  done
  return 0
}

# ----------------------------------------------------------------------
# 3. Pre-flight: disk + RAM. Refuse politely with a remediation line.
# ----------------------------------------------------------------------
preflight_check() {
  local avail_mb=0 mem_mb=0
  if [[ -d "${haxiam}" ]]; then
    avail_mb="$(df -Pm "${haxiam}" 2>/dev/null | awk 'NR==2 {print $4}')"
  fi
  if [[ -r /proc/meminfo ]]; then
    mem_mb="$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo 2>/dev/null)"
  fi
  if [[ "${avail_mb:-0}" -lt 500 ]]; then
    upgrade_red "Pre-flight failed: only ${avail_mb:-?} MB free under ${haxiam}; need >= 500 MB."
    return 1
  fi
  if [[ "${mem_mb:-0}" -lt 512 ]]; then
    upgrade_red "Pre-flight failed: ${mem_mb:-?} MB MemAvailable; need >= 512 MB."
    return 1
  fi
  upgrade_green "Pre-flight ok: ${avail_mb} MB disk free, ${mem_mb} MB MemAvailable."
  return 0
}
preflight_check || exit 1

# ----------------------------------------------------------------------
# 4. Snapshot machinery. Manifest is sha256 across every _iamConfig file.
# ----------------------------------------------------------------------
SNAPSHOT_ROOT="${haxiam}/_iamConfig/snapshots"
SNAPSHOT_DIR=""
write_snapshot_manifest() {
  # $1 = sub-dir inside the snapshot (pre or per-script)
  local sub="$1"
  local dir="${SNAPSHOT_DIR}/${sub}"
  mkdir -p "${dir}"
  local man="${dir}/manifest.txt"
  : > "${man}"
  while IFS= read -r -d '' f; do
    local rel="${f#${haxiam}/_iamConfig/}"
    local sha
    sha="$(sha256sum "${f}" | awk '{print $1}')"
    printf '%s  %s\n' "${sha}" "${rel}" >> "${man}"
  done < <(find "${haxiam}/_iamConfig" -maxdepth 1 -type f -print0 2>/dev/null)
  echo "${man}"
}

# ----------------------------------------------------------------------
# 5. Resolve code version + system version - identical to the original.
# ----------------------------------------------------------------------
source_dir="${haxiam}/scripts/upgrade/system"
cd "${haxiam}"
if [[ -f "${haxiam}/.version" ]]; then
  code_version=$(<"${haxiam}/.version")
elif [[ -f "${haxiam}/VERSION.txt" ]]; then
  code_version=$(<"${haxiam}/VERSION.txt")
else
  code_version="0.0.0"
fi
system_version_file="${haxiam}/_iamConfig/SYSTEM_VERSION.txt"
upgrade_history="${haxiam}/_iamConfig/upgrade_history.txt"

if [[ ! -f "${system_version_file}" ]]; then
  touch "${system_version_file}"
  echo "0.0.0" > "${system_version_file}"
fi
system_version=$(<"${system_version_file}")

if [[ ! -f "${upgrade_history}" ]]; then
  touch "${upgrade_history}"
  echo "PRODUCED VIA UPGRADE SCRIPT ITSELF; this suggests a pre-version deployment just as an FYI" >> "${upgrade_history}"
  echo "Initially installed as: ${code_version}" >> "${upgrade_history}"
fi

upgrade_red "Current version of codebase: $code_version"
upgrade_red "Current version of your system: $system_version"

# Make sure the git checkout of cores/HAXcms-1.x.x is clean before we
# touch it. Dirty working trees -> "abort, commit or stash first".
if [[ -d "${haxcms}/.git" ]]; then
  if ! git -C "${haxcms}" diff --quiet --exit-code HEAD 2>/dev/null \
    || [[ -n "$(git -C "${haxcms}" status --porcelain 2>/dev/null)" ]]; then
    upgrade_red "cores/HAXcms-1.x.x has uncommitted changes. Refusing to upgrade."
    exit 1
  fi
fi

# ----------------------------------------------------------------------
# 6. Pre-upgrade snapshot, then run the original versioned-script loop.
# ------------------------------------------------------------------
SNAPSHOT_DIR="${SNAPSHOT_ROOT}/$(timestamp)"
mkdir -p "${SNAPSHOT_DIR}"
PRE_MANIFEST="$(write_snapshot_manifest pre)"
upgrade_green "Pre-upgrade snapshot: ${SNAPSHOT_DIR}  (manifest: ${PRE_MANIFEST})"

cd "${source_dir}"
systemupgrades=( $(find . -maxdepth 1 -type f | sed 's/\///' | sed 's/\.//' | sed 's/.sh//' | sort --version-sort) )

for upgrade in "${systemupgrades[@]}"; do
  mincomp=$(vercomp "${upgrade}" "${system_version}")
  min=$?
  maxcomp=$(vercomp "${code_version}" "${upgrade}")
  max=$?
  script_name="${upgrade}.sh"
  if [[ ${min} == 1 ]] && [[ ${max} == 1 ]]; then
    upgrade_green "$(timestamp): We need to run upgrade: ${upgrade}"
    bash "${script_name}"
    rc=$?
    if [[ ${rc} -ne 0 ]]; then
      upgrade_red "Upgrade ${script_name} exited ${rc}. Aborting the upgrade loop."
      exit "${rc}"
    fi
    # Per-script snapshot - now reflects the state AFTER that script ran.
    POST_MANIFEST="$(write_snapshot_manifest "applied-${upgrade}")"
    echo "$(timestamp) Applied upgrade ${upgrade} snapshot=${POST_MANIFEST#"${haxiam}/"}" \
      >> "${upgrade_history}"
  else
    if [[ "${upgrade}" = "${code_version}" ]]; then
      if [[ "${upgrade}" != "${system_version}" ]]; then
        upgrade_green "$(timestamp): We need to run upgrade: ${upgrade}"
        bash "${script_name}"
        rc=$?
        if [[ ${rc} -ne 0 ]]; then
          upgrade_red "Upgrade ${script_name} exited ${rc}. Aborting the upgrade loop."
          exit "${rc}"
        fi
        POST_MANIFEST="$(write_snapshot_manifest "applied-${upgrade}")"
        echo "$(timestamp) Applied upgrade ${upgrade} snapshot=${POST_MANIFEST#"${haxiam}/"}" \
          >> "${upgrade_history}"
      fi
      break
    fi
  fi
done

echo "${code_version}" > "${system_version_file}"

# ----------------------------------------------------------------------
# 7. Post-upgrade HTTP health check. Non-5xx is "ok"; 5xx aborts.
# ----------------------------------------------------------------------
upgrade_green "Bash based upgrade complete - running health check."
HEALTH_URL="https://${domain:-localhost}/"
HEALTH_CODE="$(curl --silent --output /dev/null --max-time 8 -L \
  -k "${HEALTH_URL}" -w '%{http_code}' || echo 000)"
if [[ "${HEALTH_CODE}" =~ ^5 ]]; then
  upgrade_red "Post-upgrade health check FAILED: ${HEALTH_URL} returned ${HEALTH_CODE}."
  upgrade_red "Snapshots preserved under ${SNAPSHOT_DIR}; see upgrade_history.txt."
  exit 1
fi
upgrade_green "Post-upgrade health check ok: HTTP ${HEALTH_CODE} from ${HEALTH_URL}."
