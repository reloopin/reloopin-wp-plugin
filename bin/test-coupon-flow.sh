#!/usr/bin/env bash
# Runs bin/test-coupon-flow.php using the plugin's PHP classes.
exec php "$(dirname "$0")/test-coupon-flow.php" "$@"
