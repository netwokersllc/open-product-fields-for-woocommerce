"""Security boundary regression tests; no WordPress or production mutations."""
import importlib.util
import pathlib
import sqlite3
import subprocess
import sys
import tempfile
import unittest
import uuid

spec = importlib.util.spec_from_file_location('clone_image', pathlib.Path(__file__).with_name('clone-content-image-proof.py'))
clone = importlib.util.module_from_spec(spec)
spec.loader.exec_module(clone)


class CloneBoundaryTest(unittest.TestCase):
    def test_prefix_sibling_and_nested_paths_rejected(self):
        for path in ['/tmp-sibling/opf-image-proof', '/tmp/opf-image-proof/child', '/tmp/opf-image-proof/../escape', '/tmp/opf-image-', 'opf-image-proof']:
            with self.subTest(path=path), self.assertRaises(ValueError):
                clone.validate_destination(path)

    def test_existing_symlink_destination_rejected(self):
        with tempfile.TemporaryDirectory() as external:
            dest = pathlib.Path('/tmp/opf-image-'+uuid.uuid4().hex)
            dest.symlink_to(external, target_is_directory=True)
            try:
                with self.assertRaises(ValueError):
                    clone.validate_destination(str(dest))
            finally:
                dest.unlink()

    def test_dangling_symlink_destination_rejected(self):
        dest = pathlib.Path('/tmp/opf-image-'+uuid.uuid4().hex)
        dest.symlink_to('/tmp/absent-image-target-'+uuid.uuid4().hex)
        try:
            with self.assertRaises(ValueError):
                clone.validate_destination(str(dest))
        finally:
            dest.unlink()

    def test_source_write_target_symlinks_rejected(self):
        for relative in ['wp-config.php', 'wp-content/db.php', 'wp-content/database', 'wp-content/database/.ht.sqlite', 'wp-content/uploads']:
            with self.subTest(path=relative), tempfile.TemporaryDirectory() as source, tempfile.TemporaryDirectory() as external:
                root = pathlib.Path(source)
                path = root/relative
                path.parent.mkdir(parents=True, exist_ok=True)
                sentinel = pathlib.Path(external)/'sentinel'
                sentinel.write_text('unchanged')
                path.symlink_to(sentinel if path.suffix in ['.php', '.sqlite'] else external)
                with self.assertRaises(ValueError):
                    clone.reject_symlinks(root)
                self.assertEqual(sentinel.read_text(), 'unchanged')

    def test_source_root_and_ancestor_symlinks_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            real = root/'real'
            (real/'child').mkdir(parents=True)
            link = root/'linked'
            link.symlink_to(real, target_is_directory=True)
            for source in [link, link/'child']:
                with self.subTest(source=source), self.assertRaises(ValueError):
                    clone.reject_symlinks(source)

    def test_existing_regular_destination_rejected(self):
        with tempfile.TemporaryDirectory(prefix='opf-image-') as directory:
            with self.assertRaises(ValueError):
                clone.validate_destination(directory)

    def test_valid_clone_is_independent_and_guard_survives_optimization(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            source, extended, opf = [root/name for name in ['wordpress', 'extended', 'opf']]
            (source/'wp-content/database').mkdir(parents=True)
            (source/'wp-content/plugins').mkdir()
            (source/'wp-content/uploads').mkdir()
            (source/'wp-config.php').write_text('http://opf.test')
            (source/'wp-content/db.php').write_text(str(source))
            extended.mkdir()
            (extended/'reference.php').write_text('reference')
            opf.mkdir()
            with sqlite3.connect(source/'wp-content/database/.ht.sqlite') as connection:
                connection.execute('CREATE TABLE proof (value TEXT)')
                connection.execute("INSERT INTO proof VALUES ('source')")
            destination = pathlib.Path('/tmp/opf-image-'+uuid.uuid4().hex)
            command = [sys.executable, '-B', '-O', str(pathlib.Path(__file__).with_name('clone-content-image-proof.py')),
                       '--wordpress', str(source), '--extended', str(extended), '--opf', str(opf),
                       '--destination', str(destination), '--port', '8249']
            try:
                result = subprocess.run(command, text=True, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(destination.stat().st_mode & 0o777, 0o700)
                self.assertEqual((destination/'wp-config.php').read_text(), 'http://127.0.0.1:8249')
                self.assertEqual((source/'wp-config.php').read_text(), 'http://opf.test')
                self.assertEqual((source/'wp-content/db.php').read_text(), str(source))
                self.assertEqual((destination/'wp-content/db.php').read_text(), str(destination))
                with sqlite3.connect(destination/'wp-content/database/.ht.sqlite') as connection:
                    connection.execute("UPDATE proof SET value='clone'")
                with sqlite3.connect(source/'wp-content/database/.ht.sqlite') as connection:
                    self.assertEqual(connection.execute('SELECT value FROM proof').fetchone()[0], 'source')
                self.assertEqual((destination/'wp-content/plugins/open-product-fields-for-woocommerce').resolve(), opf)
                self.assertEqual((destination/'wp-content/plugins/advanced-product-fields-for-woocommerce-extended/reference.php').read_text(), 'reference')
                # Optimized Python must reject an already-owned destination too.
                self.assertNotEqual(subprocess.run(command, capture_output=True).returncode, 0)
            finally:
                if destination.is_dir():
                    import shutil
                    shutil.rmtree(destination)

    def test_fresh_direct_child_accepted(self):
        dst = pathlib.Path('/tmp/opf-image-'+uuid.uuid4().hex)
        self.assertEqual(clone.validate_destination(str(dst)), dst)


if __name__ == '__main__':
    unittest.main()
