#!/usr/bin/env python3
"""X2 exporter regression tests; --database also imports into disposable MariaDB.

The optional database check uses TSDM_PHP_EXECUTABLE, TSDM_PHP_INI and the
TSDM_DB_HOST/PORT/USER/PASSWORD variables used by the PHP integration suites.
"""

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


EXPORTER = Path(__file__).resolve().parents[1] / "migrate/x2_to_x5_export.py"
SPEC = importlib.util.spec_from_file_location("x2_export", EXPORTER)
EXPORT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(EXPORT)
DATABASE = "--database" in sys.argv
if DATABASE:
    sys.argv.remove("--database")

# SQL spelling and independent decoded value, not values escaped by the exporter.
LITERALS = [
    (r"'line\nnext\tend'", "line\nnext\tend"),
    ("'Trainer''s notice'", "Trainer's notice"),
    (r"'Trainer\'s notice'", "Trainer's notice"),
    ("'  title  '", "  title  "),
    ("'寶可夢 😀'", "寶可夢 😀"),
    (r"'C:\\pets\\new\\'", "C:\\pets\\new\\"),
    (r"'\%\_\q\B\x'", "\\%\\_qBx"),
    (r"'\0\b\n\r\t\Z\"'", '\0\b\n\r\t\x1a"'),
    ("'comma, parentheses() and ; -- # /* */'", "comma, parentheses() and ; -- # /* */"),
    ("'actual\r\nline\tend'", "actual\r\nline\tend"),
    ("NULL", None),
    ("'NULL'", "NULL"),
    ("''", ""),
    ("'NO_BACKSLASH_ESCAPES'", "NO_BACKSLASH_ESCAPES"),
    ("'INSERT INTO `pm_config` VALUES (1,2,3);'", "INSERT INTO `pm_config` VALUES (1,2,3);"),
]


def source_dump():
    rows = [f"('key{i}',{literal},'string')" for i, (literal, _) in enumerate(LITERALS)]
    return ("-- Archived MySQL dump\n"
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
            "SET NAMES utf8mb4;\n"
            "INSERT INTO `pm_config` VALUES\n" + ",\n".join(rows) + ";\n"
            "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n")


class ExportRegression(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="tsdm-x2-export-")
        self.addCleanup(self.directory.cleanup)
        self.src = Path(self.directory.name) / "legacy.sql"
        self.dst = Path(self.directory.name) / "canonical.sql"

    def cli(self, content):
        self.src.write_bytes(content if isinstance(content, bytes) else content.encode("utf-8"))
        return subprocess.run([sys.executable, str(EXPORTER), str(self.src), str(self.dst)],
                              capture_output=True, text=True, encoding="utf-8", timeout=15)

    def test_decodes_mysql_literals(self):
        for literal, expected in LITERALS:
            with self.subTest(literal=literal):
                self.assertEqual(EXPORT.parse_values_tuples(f"({literal})"), [[expected]])

    def test_emits_mysql_controls(self):
        self.assertEqual(EXPORT.esc('\0\b\n\r\t\x1a\\\''), r"'\0\b\n\r\t\Z\\\''")
        self.assertEqual(EXPORT.esc(None), "NULL")
        self.assertEqual(EXPORT.esc("  寶可夢  "), "'  寶可夢  '")

    def test_cli_preserves_values(self):
        result = self.cli("\ufeff" + source_dump())
        self.assertEqual(result.returncode, 0, result.stderr)
        output = self.dst.read_text(encoding="utf-8")
        self.assertIn(f"{len(LITERALS):>7d} rows", result.stdout)
        for i, (_, value) in enumerate(LITERALS):
            self.assertIn(f"('key{i}', {EXPORT.esc(value)}, 'string')", output)
        self.assertNotIn("\0", output)
        self.assertNotIn("\x1a", output)

    def test_cli_batches_and_maps_legacy_columns(self):
        source = "INSERT INTO `pm_config` VALUES " + ",".join(
            f"('key{i}','value{i}','string')" for i in range(101)) + ";\n"
        source += "INSERT INTO `pm_myskill` VALUES (9,11,13,15);\n"
        result = self.cli(source)
        self.assertEqual(result.returncode, 0, result.stderr)
        output = self.dst.read_text(encoding="utf-8")
        self.assertEqual(output.count("INSERT INTO `pm_config`"), 2)
        self.assertIn("INSERT INTO `pm_myskill` (`uid`, `petid`, `skillid`, `skillnum`)", output)
        self.assertIn("(9, 11, 13, 15)", output)

    def test_integer_literals_keep_sql_numeric_values(self):
        self.assertEqual(EXPORT.parse_values_tuples("(001,-02,+3,'001')"), [[1, -2, 3, "001"]])
        result = self.cli("INSERT INTO `pm_config` VALUES ('number',001,'integer');")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("('number', 1, 'integer')", self.dst.read_text(encoding="utf-8"))

    def test_comments_and_other_strings_are_not_rows(self):
        source = ("-- INSERT INTO `pm_config` VALUES ('fake','row','string');\n"
                  "# INSERT INTO `pm_config` VALUES ('fake2','row','string');\n"
                  "/* INSERT INTO `pm_config` VALUES ('fake3','row','string'); */\n"
                  "CREATE TABLE unused (note TEXT DEFAULT 'INSERT INTO `pm_config` VALUES (1,2,3);');\n"
                  "INSERT INTO `pre_forum_post` VALUES (1,'forum text');\n"
                  "INSERT IGNORE INTO pre_common_member (uid,username) VALUES (1,'member');\n"
                  "INSERT INTO `pm_config` VALUES ('real','value','string');\n")
        result = self.cli(source)
        self.assertEqual(result.returncode, 0, result.stderr)
        output = self.dst.read_text(encoding="utf-8")
        self.assertNotIn("fake", output)
        self.assertEqual(output.count("('real'"), 1)
        self.assertNotIn("('1'", output)

    def test_supported_dump_mode_variants(self):
        insert = "INSERT INTO `pm_config` VALUES ('key','line\\nnext','string');"
        for directive in ["SET SQL_MODE='';", "SET SESSION sql_mode='STRICT_TRANS_TABLES';",
                          "SET @@SESSION.sql_mode='NO_AUTO_VALUE_ON_ZERO';",
                          "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='' */;",
                          "/*M!40101 SET SQL_MODE='' */;",
                          "SET @note='SQL_MODE=NO_BACKSLASH_ESCAPES';"]:
            with self.subTest(directive=directive):
                result = self.cli(directive + insert)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(r"'line\nnext'", self.dst.read_text(encoding="utf-8"))

    def test_malformed_input_never_replaces_output(self):
        valid = "INSERT INTO `pm_config` VALUES ('ok','value','string');\n"
        bad_bodies = ["('bad','unfinished", "('bad','slash\\", "('bad','x','string')",
                      "('bad','x','string'); junk", "('bad','x','string'),;",
                      "('bad','x','string') ('second','x','string');",
                      "('bad','x' 'y','string');", "('bad',,'string');",
                      "('bad','x','string'); /* unfinished", "('bad',NOW(),'string');",
                      "('bad','x');", "('bad','x','string','extra');"]
        for body in bad_bodies:
            with self.subTest(body=body):
                self.dst.write_text("existing output", encoding="utf-8")
                result = self.cli(valid + "INSERT INTO `pm_config` VALUES " + body)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("conversion complete", result.stdout)
                self.assertEqual(self.dst.read_text(encoding="utf-8"), "existing output")
                self.dst.unlink()
                result = self.cli(valid + "INSERT INTO `pm_config` VALUES " + body)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.dst.exists())

    def test_unsupported_modes_and_formats_fail(self):
        valid = "INSERT INTO `pm_config` VALUES ('key','value','string');"
        cases = ["SET SQL_MODE='NO_BACKSLASH_ESCAPES';" + valid,
                 "/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;" + valid,
                 "SET @@SESSION.sql_mode='STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES';" + valid,
                 "SET sql_mode=CONCAT(@@sql_mode,',NO_BACKSLASH_ESCAPES');" + valid,
                 "SET sql_mode=@OLD_SQL_MODE;" + valid,
                 "INSERT INTO `pm_config` (`key`,`value`,`data_type`) VALUES ('key','v','string');",
                 "INSERT IGNORE INTO `pm_config` VALUES ('key','v','string');",
                 "INSERT INTO `pm_config` VALUES ('key',_binary'v','string');",
                 "INSERT INTO `pm_config` VALUES ('key',0xFF,'string');",
                 "INSERT INTO `pm_config` VALUES ('key',1e2,'string');",
                 "INSERT INTO `pm_config` VALUES ('key',1.0,'string');",
                 "INSERT INTO `pm_myskill` VALUES (1,2,'1e2',4);",
                 "-- no supported rows\n",
                 valid.encode("utf-8") + b"\n\xff"]
        for content in cases:
            with self.subTest(content=content):
                result = self.cli(content)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.dst.exists())

    @unittest.skipUnless(DATABASE, "pass --database for the disposable MariaDB comparison")
    def test_cli_against_mariadb(self):
        result = self.cli(source_dump())
        self.assertEqual(result.returncode, 0, result.stderr)
        expected = Path(self.directory.name) / "expected.json"
        expected.write_text(json.dumps({f"key{i}": value for i, (_, value) in enumerate(LITERALS)},
                                       ensure_ascii=False), encoding="utf-8")
        fixture = Path(__file__).parent / "support/x2_export_database.php"
        command = [os.environ.get("TSDM_PHP_EXECUTABLE", "php")]
        if os.environ.get("TSDM_PHP_INI"):
            command += ["-c", os.environ["TSDM_PHP_INI"]]
        command += [str(fixture), str(self.src), str(self.dst), str(expected)]
        check = subprocess.run(command, capture_output=True, text=True, encoding="utf-8", timeout=30)
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        self.assertIn(f"{len(LITERALS)} original and converted values match", check.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
