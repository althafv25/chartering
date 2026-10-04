"""Exercise recovery-set publication and failure handling without touching a database."""
import gzip
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest


class BackupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="offshore-backup-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.docs = self.root / "private documents"
        self.docs.mkdir()
        (self.docs / "invoice.txt").write_text("Private invoice fixture")
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.dump = self.bin / "mysqldump"
        self.dump.write_text("#!/bin/sh\nprintf 'CREATE TABLE fixture (id INT);\\n'\n")
        self.dump.chmod(0o700)
        self.backups = self.root / "backups"

    def run_backup(self):
        return subprocess.run(
            ["bash", str(Path(__file__).with_name("backup-database.sh"))],
            env={**os.environ, "PATH": f"{self.bin}:{os.environ['PATH']}",
                 "BACKUP_DIR": str(self.backups), "DOCUMENTS_ROOT": str(self.docs),
                 "DB_NAME": "fixture", "DB_PASSWORD": "", "MYSQL_DEFAULTS_FILE": ""},
            text=True, capture_output=True, check=False,
        )

    def test_publishes_restorable_database_and_document_pair_with_private_permissions(self):
        result = self.run_backup()
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        sets = list(self.backups.glob("fixture_*"))
        self.assertEqual(1, len(sets))
        self.assertEqual(0o700, sets[0].stat().st_mode & 0o777)
        with gzip.open(sets[0] / "database.sql.gz", "rt") as dump:
            self.assertEqual("CREATE TABLE fixture (id INT);\n", dump.read())
        with tarfile.open(sets[0] / "documents.tar.gz") as documents:
            self.assertEqual(b"Private invoice fixture", documents.extractfile("./invoice.txt").read())

    def test_failed_dump_publishes_nothing_and_preserves_previous_backup(self):
        self.assertEqual(0, self.run_backup().returncode)
        previous = list(self.backups.glob("fixture_*"))
        self.dump.write_text("#!/bin/sh\nprintf 'partial dump'\nexit 1\n")
        result = self.run_backup()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(previous, list(self.backups.glob("fixture_*")))
        self.assertEqual([], list(self.backups.glob(".*.partial")))

    def test_missing_documents_is_a_failed_backup(self):
        (self.docs / "invoice.txt").unlink()
        self.docs.rmdir()
        self.assertNotEqual(0, self.run_backup().returncode)


if __name__ == "__main__":
    unittest.main()
