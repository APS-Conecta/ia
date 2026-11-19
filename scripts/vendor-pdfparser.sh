#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# smalot/pdfparser, the only library «IA» ships (gestion-aio#162, TextReader), vendored into
# lib/Vendor with its namespace prefixed (Smalot\PdfParser → OCA\Ia\Vendor\Smalot\PdfParser), so
# it never clashes with the copy Nextcloud's Assistant loads, and Nextcloud's own autoloader for
# OCA\Ia\ loads it. Release tarballs carry only tracked files, so the result is committed; rerun
# this to move the pin: change VERSION and SHA256 together.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=2.13.1
SHA256=12612ca0b6bb16fa75ef20d97d369d75e495998aba35d338f31ea3e05a2f7b25
TARGET=lib/Vendor/Smalot/PdfParser

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
curl -fsSL -o "$work/pdfparser.tar.gz" "https://codeload.github.com/smalot/pdfparser/tar.gz/refs/tags/v$VERSION"
echo "$SHA256  $work/pdfparser.tar.gz" | sha256sum -c --quiet -
tar -xzf "$work/pdfparser.tar.gz" -C "$work"

rm -rf "$TARGET"
mkdir -p "$TARGET"
cp -R "$work/pdfparser-$VERSION/src/Smalot/PdfParser/." "$TARGET/"
cp "$work/pdfparser-$VERSION/LICENSE.txt" "$TARGET/LICENSE.txt"
# Every spelling of the namespace, one backslash or two (a class name in a string), keeps its own.
find "$TARGET" -name '*.php' -exec perl -pi -e 's/Smalot(\\+)PdfParser/OCA${1}Ia${1}Vendor${1}Smalot${1}PdfParser/g' {} +
! grep -rqE '(^|[^A-Za-z\\])\\?Smalot\\+PdfParser' --include='*.php' "$TARGET" || { echo "an unprefixed reference is left" >&2; exit 1; }
echo "smalot/pdfparser $VERSION vendored into $TARGET"
