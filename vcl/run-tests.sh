#!/bin/sh
# Functional test of default.vcl with varnishtest.
#
# test.vtc contains a ${vcl_body} placeholder: varnishtest injects its own
# backend (s1), so the "vcl 4.0;" marker and the "backend default" block are
# stripped from default.vcl before inlining the rest into the test.
#
# Usage: ./run-tests.sh   (requires varnishd + varnishtest, e.g. in /usr/sbin)

set -eu
cd "$(dirname "$0")"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

awk '/^vcl 4.0;$/{next} /^backend default \{/{skip=1} skip&&/^\}/{skip=0;next} !skip' \
	default.vcl > "$TMP/body.vcl"

awk 'NR==FNR{body=body $0 ORS; next} /\$\{vcl_body\}/{printf "%s", body; next} {print}' \
	"$TMP/body.vcl" test.vtc > "$TMP/test.vtc"

PATH="/usr/sbin:$PATH" varnishtest "$TMP/test.vtc"
