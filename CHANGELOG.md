# Changelog

User-facing changes to Open Product Fields for WooCommerce.

## Unreleased

### Added

- Text, image, and color swatches can accept multiple selections, with minimum
  and maximum selection limits.
- Color swatches support validated hex values, shape and size settings, and
  accessible labels.
- WAPF imports and exports preserve the matching single/multiple swatch type
  and its supported settings.
- Paragraphs support plain text or restricted HTML, with optional WordPress
  shortcode processing. WAPF Extended `p` content imports and exports with its
  markup and shortcode payload preserved.
- Informative images support conditional display, Media Library selection, and
  WAPF `img` migration, preserving image URLs and attachment references for
  JSON/WXR export.
- Nested section markers support conditional wrappers and WAPF import/export;
  WAPF repeated-section settings stay review-required.
