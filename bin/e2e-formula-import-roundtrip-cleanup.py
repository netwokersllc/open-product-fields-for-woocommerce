"""Independent read-only SQLite audit; no WordPress bootstrapping or fixture handler."""
import datetime
import json
import sqlite3
from pathlib import Path

database = Path('/tmp/opf-formula-roundtrip-wp/wp-content/database/.ht.sqlite')
if database.resolve() != database or not database.is_file():
    raise RuntimeError('Requires the dedicated isolated SQLite database')

queries = {
    'fixture_posts': "SELECT COUNT(*) FROM wpt_posts WHERE post_type IN ('product','wapf_product','opf_field_group')",
    'fixture_meta': "SELECT COUNT(*) FROM wpt_postmeta WHERE meta_key IN ('_wapf_fieldgroup','_opf_imported_from','_opf_archive_import_key')",
    'fixture_option': "SELECT COUNT(*) FROM wpt_options WHERE option_name='opf_formula_roundtrip_state'",
    'hpos_orders': 'SELECT COUNT(*) FROM wpt_wc_orders',
    'order_items': 'SELECT COUNT(*) FROM wpt_woocommerce_order_items',
    'order_item_meta': 'SELECT COUNT(*) FROM wpt_woocommerce_order_itemmeta',
}
with sqlite3.connect(database.as_uri() + '?mode=ro', uri=True) as connection:
    counts = {name: connection.execute(sql).fetchone()[0] for name, sql in queries.items()}
    result = {
        'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'database': str(database),
        'mechanism': 'Separate Python sqlite3 process; SQLite URI mode=ro; no WordPress runtime',
        'queries': queries,
        'counts': counts,
    }
    print(json.dumps(result, indent=2))
    if any(counts.values()):
        raise RuntimeError('Independent cleanup audit found fixture or commerce records')
