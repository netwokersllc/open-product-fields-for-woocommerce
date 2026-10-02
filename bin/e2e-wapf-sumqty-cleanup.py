"""Read-only cleanup audit, independent of the WordPress fixture handler."""
import json
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

database = Path('/tmp/opf-sumqty-import-wp/wp-content/database/.ht.sqlite')
proof = json.loads(Path(sys.argv[1]).read_text())
ids = proof['owned_ids']
assert ids and all(type(item) is int and item > 0 for item in ids)
with sqlite3.connect(f'file:{database}?mode=ro', uri=True) as connection:
    counts = {
        'fixture_post_types': connection.execute(
            "SELECT COUNT(*) FROM wpt_posts WHERE post_type IN ('wapf_product','opf_field_group','product')"
        ).fetchone()[0],
        'owned_postmeta': connection.execute(
            f"SELECT COUNT(*) FROM wpt_postmeta WHERE post_id IN ({','.join('?' for _ in ids)})", ids
        ).fetchone()[0],
        'fixture_option': connection.execute(
            "SELECT COUNT(*) FROM wpt_options WHERE option_name='opf_sumqty_import_owned'"
        ).fetchone()[0],
    }
assert all(value == 0 for value in counts.values()), counts
print(json.dumps({'time_utc': datetime.now(timezone.utc).isoformat(), 'database': str(database),
                  'read_only': True, 'owned_ids': ids, 'counts': counts}, indent=2))
