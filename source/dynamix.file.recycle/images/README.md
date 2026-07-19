# Plugin images

This directory holds the plugin's branding images.

- `dynamix.file.recycle.svg`  — vector source.
- `dynamix.file.recycle.png`  — 128×128 PNG (used by `plugins.json`).

If the PNG is missing you can regenerate it from the SVG with ImageMagick:

    convert -background none -resize 128x128 \
        dynamix.file.recycle.svg dynamix.file.recycle.png

Or with rsvg-convert:

    rsvg-convert -w 128 -h 128 dynamix.file.recycle.svg \
        -o dynamix.file.recycle.png
