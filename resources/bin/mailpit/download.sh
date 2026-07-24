#!/usr/bin/env sh
# Downloads Mailpit static binaries into resources/bin/mailpit/{mac,win,linux}/
# so MailpitManager::resolveBinary() can bundle them.
#
# Mailpit is MIT-licensed (https://github.com/axllent/mailpit). This script also
# fetches its LICENSE next to the binaries to satisfy the notice requirement.
#
# Architecture is auto-detected: the binary for the OS you're running on matches
# this machine (so it works during `bun run dev`), while the cross-compiled
# targets default to amd64. Override any of them:
#   MAILPIT_MAC_ARCH / MAILPIT_LINUX_ARCH / MAILPIT_WIN_ARCH  (amd64 | arm64 | 386)
#   MAILPIT_VERSION                                            (default v1.30.5)
#
# Run `sh download.sh host` to fetch only this machine's binary (handy for dev);
# otherwise all three desktop targets are fetched (for packaging).
set -eu

VERSION="${MAILPIT_VERSION:-v1.30.5}"
BASE="https://github.com/axllent/mailpit/releases/download/${VERSION}"
DIR="$(cd "$(dirname "$0")" && pwd)"

# uname -m → Mailpit's arch token.
case "$(uname -m)" in
    arm64 | aarch64) HOST_ARCH="arm64" ;;
    x86_64 | amd64)  HOST_ARCH="amd64" ;;
    i386 | i686)     HOST_ARCH="386" ;;
    *)               HOST_ARCH="amd64" ;;
esac

# On macOS, `uname -m` reports x86_64 under a Rosetta-translated shell even on
# Apple Silicon. Trust the CPU capability flag so we still pick arm64 natively.
if [ "$(uname -s)" = "Darwin" ] && [ "$(sysctl -n hw.optional.arm64 2>/dev/null)" = "1" ]; then
    HOST_ARCH="arm64"
fi

# uname -s → our per-OS directory.
case "$(uname -s)" in
    Darwin)              HOST_OS="mac" ;;
    Linux)               HOST_OS="linux" ;;
    MINGW* | MSYS* | CYGWIN* | Windows*) HOST_OS="win" ;;
    *)                   HOST_OS="" ;;
esac

# Host OS gets the detected arch; others default to amd64 (all overridable).
MAC_ARCH="${MAILPIT_MAC_ARCH:-$([ "$HOST_OS" = mac ] && echo "$HOST_ARCH" || echo arm64)}"
LINUX_ARCH="${MAILPIT_LINUX_ARCH:-$([ "$HOST_OS" = linux ] && echo "$HOST_ARCH" || echo amd64)}"
WIN_ARCH="${MAILPIT_WIN_ARCH:-$([ "$HOST_OS" = win ] && echo "$HOST_ARCH" || echo amd64)}"

fetch() {
    os="$1"; asset="$2"; bin="$3"
    echo "→ ${os}: ${asset}"
    tmp="$(mktemp -d)"
    curl -sSL "${BASE}/${asset}" -o "${tmp}/pkg"
    case "$asset" in
        *.zip) unzip -qo "${tmp}/pkg" -d "${tmp}" ;;
        *)     tar -xzf "${tmp}/pkg" -C "${tmp}" ;;
    esac
    mkdir -p "${DIR}/${os}"
    mv "${tmp}/${bin}" "${DIR}/${os}/${bin}"
    [ "${bin}" = "mailpit.exe" ] || chmod +x "${DIR}/${os}/${bin}"
    rm -rf "${tmp}"
}

fetch_mac()   { fetch mac   "mailpit-darwin-${MAC_ARCH}.tar.gz"  mailpit; }
fetch_linux() { fetch linux "mailpit-linux-${LINUX_ARCH}.tar.gz" mailpit; }
fetch_win()   { fetch win   "mailpit-windows-${WIN_ARCH}.zip"    mailpit.exe; }

if [ "${1:-}" = "host" ]; then
    case "$HOST_OS" in
        mac)   fetch_mac ;;
        linux) fetch_linux ;;
        win)   fetch_win ;;
        *)     echo "Unrecognised host OS: $(uname -s)"; exit 1 ;;
    esac
else
    fetch_mac
    fetch_linux
    fetch_win
fi

# License compliance: keep Mailpit's MIT notice with the bundled binaries.
curl -sSL "https://raw.githubusercontent.com/axllent/mailpit/${VERSION}/LICENSE" -o "${DIR}/LICENSE.mailpit"

echo "✓ Mailpit ${VERSION} ready in ${DIR} (host: ${HOST_OS:-unknown}/${HOST_ARCH})"
