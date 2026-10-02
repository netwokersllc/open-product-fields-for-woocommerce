# Changelog

User-facing changes to Open Product Fields for WooCommerce.

## Unreleased

### Added

- Formula pricing now includes WAPF math, text-length, and conditional functions
  in server calculations and browser totals.
- Extended `datediff()` formulas now calculate whole calendar days between
  configured date values, including `today()` and validated sibling fields.
- Extended `checked(field ID)` formulas now count selected multi-select values
  in server pricing and browser totals.
- Formula pricing resolves prior-field `[price.ID]` references across groups
  in server carts and browser totals; imported forward or self references
  remain flagged for review.
- WAPF formula imports remap recognized field references to generated OPF IDs
  and flag references that cannot be resolved safely.
- Text, image, and color swatches can accept multiple selections, with minimum
  and maximum selection limits.
- Color swatches support validated hex values, shape and size settings, and
  accessible labels.
- WAPF imports and exports preserve the matching single/multiple swatch type
  and its supported settings.
- WAPF local text-field imports preserve configured default values.
- Paragraphs support plain text or restricted HTML, with optional WordPress
  shortcode processing. WAPF Extended `p` content imports and exports with its
  markup and shortcode payload preserved.
- Informative images support conditional display, Media Library selection, and
  WAPF `img` migration, preserving image URLs and attachment references for
  JSON/WXR export.
- Nested section markers support conditional wrappers and WAPF import/export;
  WAPF repeated-section settings stay review-required.
- WAPF button and quantity clone modes import into review drafts, preserving
  recognized modes and in-range button maxima for later migration work.
- Repeated fields and sections evaluate child conditions and date-based pricing
  formulas against values from the matching clone in browser totals and carts.
