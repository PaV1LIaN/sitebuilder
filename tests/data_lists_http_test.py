"""Run the unchanged lists endpoint with isolated Bitrix/SQLite adapters.
PHP_COMMAND may select PHP 8 with mbstring. No production data is used.
"""
import json
import os
from pathlib import Path
import shlex
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.error import HTTPError
from urllib.request import Request, urlopen

SOURCE = Path(__file__).resolve().parents[1]


class ListHttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='sb-lists-')
        cls.root = Path(cls.temp.name)
        app = cls.root / 'local/sitebuilder'
        (app / 'lib').mkdir(parents=True)
        for name in ['lists_api.php', 'lib/DataListService.php']:
            shutil.copy2(SOURCE / name, app / name)
        for name in ['lists_runtime.php', 'lists_sqlite_bridge.py']:
            shutil.copy2(SOURCE / 'tests/fixtures' / name, cls.root / name)
        for name in ['db.php', 'PageAccessService.php']:
            (app / 'lib' / name).write_text('<?php // Supplied by isolated prolog fixture.\n')
        (app / 'lib/auth.php').write_text('<?php function sitebuilder_require_api_auth() {}\n')
        prolog = cls.root / 'bitrix/modules/main/include/prolog_before.php'
        prolog.parent.mkdir(parents=True)
        prolog.write_text('''<?php
require $_SERVER['DOCUMENT_ROOT'] . '/lists_runtime.php';
function bitrix_sessid() { return 'test-session'; }
$USER = new class {
    function GetID() { return (int)($_SERVER['HTTP_X_TEST_USER'] ?? 0); }
    function IsAuthorized() { return $this->GetID() > 0; }
};
''')
        cls.php = shlex.split(os.environ.get('PHP_COMMAND', 'php'))
        cls.env = dict(os.environ, LISTS_TEST_DB=str(cls.root / 'db.sqlite'))
        subprocess.run(cls.php + ['-r', "require 'lists_runtime.php'; lists_setup();"], cwd=cls.root, env=cls.env, check=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.url = f'http://127.0.0.1:{port}/local/sitebuilder/lists_api.php'
        cls.log = (cls.root / 'server.log').open('w+')
        cls.server = subprocess.Popen(cls.php + ['-S', f'127.0.0.1:{port}', '-t', str(cls.root)], env=cls.env, stdout=cls.log, stderr=cls.log)
        for _ in range(60):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('PHP test server did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.log.close()
        cls.temp.cleanup()

    def request(self, action='records', user=1, method='POST', raw=None, **extra):
        payload = dict(action=action, siteId=1, pageId=10, blockId=100, sessid='test-session')
        payload.update(extra)
        request = Request(self.url, data=(raw if raw is not None else json.dumps(payload).encode()) if method=='POST' else None,
                          method=method, headers={'Content-Type':'application/json','X-Test-User':str(user)})
        try:
            response = urlopen(request, timeout=5)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, json.load(response)

    def test_csrf(self):
        status, _, body = self.request('create', sessid='wrong')
        self.assertEqual(status, 403)
        self.assertIn('Сессия', body['message'])

    def test_authentication_and_acl(self):
        self.assertEqual(self.request(user=0)[0], 401)
        self.assertEqual(self.request('create', user=2)[0], 403)
        self.assertEqual(self.request(user=9)[0], 403)

    def test_method(self):
        self.assertEqual(self.request(method='GET')[0], 405)

    def test_malformed_json(self):
        self.assertEqual(self.request(raw=b'{')[0], 422)

    def test_request_size(self):
        self.assertEqual(self.request(raw=b'x'*1048577)[0], 413)

    def test_create_response_and_cache(self):
        status, headers, body = self.request('create', title='Реестр', fields=[dict(id='title',label='Название',type='text',required=True)])
        self.assertEqual(status, 200)
        self.assertTrue(body['ok'])
        self.assertEqual(body['data']['list']['ownerPageId'], 10)
        self.assertIn('no-store', headers['Cache-Control'])
        self.assertIn('application/json', headers['Content-Type'])

    def test_bad_schema_returns_readable_error(self):
        status, _, body = self.request('create', title='Список', fields=[])
        self.assertEqual(status, 422)
        self.assertNotIn('SELECT', json.dumps(body))
        self.assertIn('50', body['message'])


if __name__ == '__main__':
    unittest.main()
