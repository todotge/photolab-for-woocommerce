#!/usr/bin/env bash
# Downloads WooCommerce core + test helpers for integration tests.
set -e

WC_DIR="${WC_DIR:-/tmp/woocommerce}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

echo "Exporting WooCommerce trunk to $WC_DIR ..."
svn export https://github.com/woocommerce/woocommerce/trunk/plugins/woocommerce "$WC_DIR" --force 2>/dev/null || {
	# Fallback: if already exported, just update
	svn update "$WC_DIR" --force 2>/dev/null || true
}

echo "Symlinking WC into WP plugins dir ..."
mkdir -p "$WP_CORE_DIR/wp-content/plugins"
ln -sfn "$WC_DIR" "$WP_CORE_DIR/wp-content/plugins/woocommerce"

echo "Copying WC test helpers ..."
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
HELPERS_SRC="$WC_DIR/tests/legacy/helpers"
HELPERS_DST="$WP_TESTS_DIR/includes"

if [ -d "$HELPERS_SRC" ]; then
	for f in WC_Helper_Product.php WC_Helper_Order.php; do
		[ -f "$HELPERS_SRC/$f" ] && cp "$HELPERS_SRC/$f" "$HELPERS_DST/$f"
	done
	echo "Test helpers copied."
else
	echo "Warning: helpers directory not found at $HELPERS_SRC"
fi

echo "WooCommerce test setup complete."
