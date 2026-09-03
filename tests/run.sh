#!/bin/sh
# GP Dark Mode — run all bundle-assembler tests (plain PHP CLI, no WordPress).
set -e
cd "$(dirname "$0")"
echo "=== unit.php ==="
php unit.php
echo "=== coverage.php ==="
php coverage.php
echo "=== smoke.php ==="
php smoke.php
echo "All suites passed."
