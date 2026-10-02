#!/usr/bin/env bash
#
# Runs the VIP Integration Center conformance checker against the last commit.
#
# It checks `git archive HEAD`, not the working tree, on purpose. The checker
# reads README.md by exact name, which a case-insensitive filesystem satisfies
# with readme.md, and it reads every .md under docs/, which includes the
# gitignored plans. Either one passes here and fails on CI. An export of what
# is committed is what CI sees, so it is the only honest answer.
#
# Extra arguments go to the checker, for example `--format json`.
#
# Keep the version in step with the `validate` job in .github/workflows/ci.yml.

set -euo pipefail

VERSION="0.1.2"

root="$(git rev-parse --show-toplevel)"
dir="$(mktemp -d)"
trap 'rm -rf "$dir"' EXIT

# Untracked files count too: a new doc that is not committed is not checked.
if [ -n "$(git -C "$root" status --porcelain)" ]; then
	echo "Note: uncommitted changes are not checked. Commit them first." >&2
fi

git -C "$root" archive HEAD | tar -x -C "$dir"

npx --yes "@automattic/vip-integration@${VERSION}" validate "$dir" "$@"
