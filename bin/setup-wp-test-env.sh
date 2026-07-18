#!/usr/bin/env bash
# One-time setup of WP + WC test environment on the runner machine.
# Idempotent — safe to re-run.
set -e

echo "=== Setting up Photolab integration test environment ==="

# Ensure image processing extensions are available
echo "Checking image extensions..."
for ext in gd imagick; do
    if php -m 2>/dev/null | grep -q "^$ext\$"; then
        echo "  $ext: OK"
    else
        echo "  $ext: MISSING — installing..."
        sudo apt-get update -qq && sudo apt-get install -y -qq "php-${ext}" || echo "  Warning: could not install php-${ext}"
    fi
done

# Ensure MySQL test database exists
echo "Creating test database..."
mysql -u root -e "CREATE DATABASE IF NOT EXISTS wordpress_test" 2>/dev/null || {
    echo "ERROR: Cannot connect to MySQL. Ensure MySQL is running and root has no password."
    exit 1
}

# Install WP test suite (idempotent — skips if already installed)
if [ -f /tmp/wordpress-tests-lib/includes/bootstrap.php ]; then
    echo "WP test suite already installed in /tmp/wordpress-tests-lib/"
else
    echo "Installing WP test suite..."
    cd "$(dirname "$0")"
    bash install-wp-tests.sh wordpress_test root '' localhost latest
fi

# Install WC test helpers (idempotent)
if [ -f /tmp/wordpress-tests-lib/includes/WC_Helper_Product.php ]; then
    echo "WC test helpers already present."
else
    echo "Installing WC test helpers..."
    cd "$(dirname "$0")"
    bash install-wc-tests.sh
fi

echo ""
echo "=== Test environment ready ==="
echo "Run: cd photolab && phpunit --testsuite integration"
