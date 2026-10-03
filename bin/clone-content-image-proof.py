#!/usr/bin/env python3
"""Copy a test WP installation and take an SQLite backup without modifying source."""
import argparse
import os
import pathlib
import shutil
import sqlite3

def validate_destination(value):
    dst = pathlib.Path(value)
    tmp = pathlib.Path('/tmp')
    if (not dst.is_absolute() or dst.parent != tmp or tmp.resolve() != tmp
            or dst.resolve() != dst or not dst.name.startswith('opf-image-')
            or dst.name == 'opf-image-' or os.path.lexists(dst)):
        raise ValueError('Destination must be a fresh direct /tmp/opf-image-NAME child, without symlinks.')
    return dst


def reject_symlinks(root):
    """Never inherit write-through paths, even when a link points inside source."""
    if not root.is_dir() or root.absolute() != root.resolve():
        raise ValueError(f'Real source directory required: {root}')
    for parent, dirs, files in os.walk(root, followlinks=False):
        for name in dirs + files:
            path = pathlib.Path(parent)/name
            if path.is_symlink():
                raise ValueError(f'Source symlink refused: {path}')


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--wordpress', required=True)
    p.add_argument('--extended', required=True)
    p.add_argument('--opf', required=True)
    p.add_argument('--destination', required=True)
    p.add_argument('--port', type=int, default=8241)
    a = p.parse_args()
    dst = validate_destination(a.destination)
    src, extended = pathlib.Path(a.wordpress), pathlib.Path(a.extended)
    reject_symlinks(src)
    reject_symlinks(extended)
    src, extended = src.resolve(), extended.resolve()
    opf = pathlib.Path(a.opf).resolve(strict=True)
    if not opf.is_dir() or not 1024 <= a.port <= 65535:
        raise ValueError('Real OPF directory and unprivileged valid port required.')
    for required in ['wp-config.php', 'wp-content/db.php', 'wp-content/database/.ht.sqlite']:
        if not (src/required).is_file():
            raise ValueError(f'Missing required source file: {required}')
    # Atomic exclusive creation rejects races/pre-existing dangling links; mode
    # 0700 keeps clone writes private. No source symlink is copied or followed.
    dst.mkdir(mode=0o700)
    shutil.copytree(src, dst, symlinks=False, dirs_exist_ok=True,
                    ignore=shutil.ignore_patterns('.ht.sqlite', '*.sqlite-wal', '*.sqlite-shm'))
    dst.chmod(0o700)
    reject_symlinks(dst)
    with sqlite3.connect((src/'wp-content/database/.ht.sqlite').as_uri()+'?mode=ro', uri=True) as source:
        with sqlite3.connect(str(dst/'wp-content/database/.ht.sqlite')) as target:
            source.backup(target)
    config = dst/'wp-config.php'
    config.write_text(config.read_text().replace('http://opf.test', f'http://127.0.0.1:{a.port}'))
    dropin = dst/'wp-content/db.php'
    dropin.write_text(dropin.read_text().replace(str(src), str(dst)))
    plugin = dst/'wp-content/plugins/open-product-fields-for-woocommerce'
    if plugin.exists():
        shutil.rmtree(plugin)
    # This single new link is deliberate and points at the caller-owned worktree;
    # all database/config/uploads paths remain physically inside the owned clone.
    plugin.symlink_to(opf, target_is_directory=True)
    shutil.copytree(extended, dst/'wp-content/plugins/advanced-product-fields-for-woocommerce-extended', symlinks=False)
    print(f'Created owned SQLite clone {dst}; activate Extended only in clone, then run guarded fixture.')


if __name__ == '__main__':
    main()
