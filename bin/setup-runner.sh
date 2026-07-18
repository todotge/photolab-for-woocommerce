#!/usr/bin/env bash
# Self-hosted GitHub Actions runner setup for Photolab integration tests.
# Run once on /media/luke/TODOT/ machine.
set -e

RUNNER_DIR="${RUNNER_DIR:-/media/luke/TODOT/actions-runner}"
RUNNER_VERSION="2.335.1"

echo "=== Installing self-hosted runner in $RUNNER_DIR ==="

mkdir -p "$RUNNER_DIR"
cd "$RUNNER_DIR"

if [ ! -f ./config.sh ]; then
    curl -o runner.tar.gz -L "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
    tar xzf runner.tar.gz
    rm runner.tar.gz
    echo "Runner extracted."
fi

echo ""
echo "=== Manual step required ==="
echo "Run the following commands to register the runner:"
echo ""
echo "  cd $RUNNER_DIR"
echo "  ./config.sh \\"
echo "    --url https://github.com/todotge/photolab \\"
echo "    --token <REGISTRATION_TOKEN_FROM_GITHUB> \\"
echo "    --name photolab-runner \\"
echo "    --labels self-hosted,integration \\"
echo "    --unattended"
echo ""
echo "Then install as a service (must run as root or use sudo):"
echo ""
echo "  sudo $RUNNER_DIR/svc.sh install"
echo "  sudo $RUNNER_DIR/svc.sh start"
echo ""
echo "Get the registration token from:"
echo "  https://github.com/todotge/photolab/settings/actions/runners/new"
echo "  (Settings → Actions → Runners → New self-hosted runner)"
