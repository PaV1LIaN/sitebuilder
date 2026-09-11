"""Offline SQL adapter for list contract tests, NOT a PostgreSQL compatibility test.

Only JSONB casts, row-lock suffixes and schema qualification are translated.
SQLite executes the real service queries; PostgreSQL concurrency needs a portal test.
"""
import datetime
import json
import re
import sqlite3
import sys

db = sqlite3.connect(sys.argv[1] if len(sys.argv) > 1 else ':memory:', isolation_level=None)
db.row_factory = sqlite3.Row
db.create_function('NOW', 0, lambda: datetime.datetime.now(datetime.timezone.utc).isoformat())
db.create_function('strpos', 2, lambda value, needle: str(value).find(str(needle)) + 1)
db.create_function('lower', 1, lambda value: str(value).lower() if value is not None else None)
db.create_function('to_regclass', 1, lambda name: name)
for line in sys.stdin:
    try:
        request = json.loads(line)
        sql = request['sql'].replace('sitebuilder.', '')
        sql = re.sub(r'CAST\((:\w+) AS jsonb\)', r'json(\1)', sql, flags=re.I)
        sql = re.sub(r' FOR (?:UPDATE|SHARE)(?: OF l)?\s*$', '', sql, flags=re.I)
        params = {k.lstrip(':'): v for k, v in (request.get('params') or {}).items()}
        if request.get('script'):
            db.executescript(sql)
            result = []
        else:
            cursor = db.execute(sql, params)
            result = [dict(row) for row in cursor.fetchall()]
        print(json.dumps({'rows': result}, ensure_ascii=False), flush=True)
    except Exception as error:
        print(json.dumps({'error': str(error)}, ensure_ascii=False), flush=True)
