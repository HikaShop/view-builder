#!/usr/bin/env sh
# View Builder Plugin build script (cross-platform).
# Uses the `zip` CLI so archive entries always use forward-slash separators.
# PowerShell's Compress-Archive wrote backslash separators, which broke
# extraction on Linux/PHP hosts (see issue #4).
set -eu

cd "$(dirname "$0")"

plugin_name="plg_system_viewbuilder"
version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' viewbuilder.xml | head -n1)"
if [ -z "$version" ]; then
    echo "Could not read <version> from viewbuilder.xml" >&2
    exit 1
fi

versioned_zip="${plugin_name}_v${version}.zip"
stable_zip="${plugin_name}.zip"

include="src services media language viewbuilder.xml README.md CHANGELOG.md cache"

echo "Creating package ${versioned_zip}..."
rm -f "$versioned_zip" "$stable_zip"

# -r recurse, -X strip extra file attributes for reproducible archives.
# cache/ ships only index.html; the plugin regenerates Original*.php at runtime.
zip -r -X -q "$versioned_zip" $include -x '*/.DS_Store' 'cache/Original*.php'

# Generic copy used by the stable release download link.
cp "$versioned_zip" "$stable_zip"

echo "Build complete: ${versioned_zip} and ${stable_zip}"
