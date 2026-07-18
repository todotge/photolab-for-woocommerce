#!/bin/bash
set -e

if [ -f .env.testing ]; then
    export $(grep -v '^#' .env.testing | xargs)
fi

bash bin/install-wp-tests.sh "${DB_NAME:-wordpress_test}" "${DB_USER:-root}" "${DB_PASS:-}" "${DB_HOST:-localhost}" latest
bash bin/install-wc-tests.sh

echo "Setup complete. Run: phpunit --testsuite integration"
