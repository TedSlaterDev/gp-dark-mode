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

# The scripts ship unbuilt, so at least prove they parse.
if command -v node >/dev/null 2>&1; then
	for js in ../blocks/toggle/index.js ../assets/admin/gp-dark-mode-admin.js ../assets/admin/gp-dark-mode-audit.js ../assets/js/dark-mode-toggle.js; do
		echo "=== ${js#../} (node --check) ==="
		node --check "$js" || exit 1
		echo "syntax OK"
	done
else
	echo "(node not found — skipped the script syntax checks)"
fi
echo "All suites passed."
