#!/bin/sh
# GP Dark Mode — run all test suites (plain PHP CLI, no WordPress).
#
# Each suite must BOTH exit 0 AND print its pass marker. A script that dies
# early — e.g. an include's ABSPATH guard calling exit(), which is silent and
# exits 0 — would otherwise count as a pass (that happened once).
set -e
cd "$(dirname "$0")"

run_suite() {
	echo "=== $1 ==="
	out=$(php "$1")
	printf '%s\n' "$out"
	case "$out" in
		*"$2"*) ;;
		*)
			echo "FAIL: $1 did not print its pass marker ('$2') — did it exit early?"
			exit 1
			;;
	esac
}

run_suite unit.php     " passed, 0 failed"
run_suite coverage.php "PASS: all"
run_suite smoke.php    " passed, 0 failed"

# The block's editor script ships unbuilt, so at least prove it parses.
if command -v node >/dev/null 2>&1; then
	echo "=== blocks/toggle/index.js (node --check) ==="
	node --check ../blocks/toggle/index.js
	echo "syntax OK"
else
	echo "(node not found — skipped the editor-script syntax check)"
fi
echo "All suites passed."
