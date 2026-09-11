"""Run: python3 tests/shared_folder_test.py (PHP 8 with mbstring required).

Exercises the real share action, folder page, storage adapter and validators
over HTTP. Bitrix objects, identity and database repositories are fixtures.
PHP_COMMAND can provide an alternate executable/ini flags. No live data used.
"""
import copy
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
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import build_opener, HTTPRedirectHandler, ProxyHandler, Request

SOURCE = Path(__file__).resolve().parents[1]
BASE = '/local/sitebuilder/components/disk'


def obj(id, name, parent, type='folder', **extra):
    return dict(id=id, name=name, parentId=parent, type=type, **extra)


OBJECTS = [obj(1, 'PARENT_NOT_TO_BE_SHOWN', 0), obj(10, 'Документы <отдела>', 1),
           obj(11, 'Вложенная папка', 10), obj(12, 'NATIVE_SECRET_FOLDER', 10, deniedUsers=[2]),
           obj(13, 'CUSTOM_SECRET_FOLDER', 10), obj(14, 'DELETED_FOLDER', 10, deleted=True),
           obj(15, 'Пустая папка', 10), obj(20, 'SIBLING_NOT_TO_BE_SHOWN', 1),
           obj(99, 'FOREIGN_FOLDER', 0), obj(1000, 'План.pdf', 10, 'file'),
           obj(1001, 'NATIVE_SECRET_FILE.pdf', 10, 'file', deniedUsers=[2]),
           obj(1002, 'DELETED_FILE.pdf', 10, 'file', deleted=True),
           obj(1100, 'Вложенный документ.docx', 11, 'file')]
DATA = dict(objects={str(o['id']): o for o in OBJECTS}, blockRoot=1,
            pageDeniedUsers=[], diskDeniedUsers=[], folderRoles={'2:13': 'DENY'})


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class SharedFolderTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='sb-shared-folder-')
        cls.root = Path(cls.temp.name)
        app = cls.root / BASE.lstrip('/')
        files = ['open_folder.php', 'open_folder.css', 'actions/get_internal_link.php']
        files += ['lib/' + name + '.php' for name in ['DiskContext', 'DiskValidator',
                  'DiskPermissionService', 'DiskRootResolver', 'DiskBitrixStorageAdapter',
                  'DiskSharedFolderService', 'DiskCsrf', 'DiskResponse', 'helpers']]
        for name in files:
            target = app / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(SOURCE / 'components/disk' / name, target)
        shutil.copy2(SOURCE / 'tests/fixtures/shared_folder_runtime.php', cls.root / 'runtime.php')
        requires = ['DiskContext', 'DiskValidator', 'DiskPermissionService', 'DiskRootResolver',
                    'DiskBitrixStorageAdapter', 'DiskCsrf', 'DiskResponse', 'helpers']
        (app / 'bootstrap.php').write_text("<?php\ndefine('SB_DISK_TEST_RUNTIME', true);\n"
            + "require $_SERVER['DOCUMENT_ROOT'] . '/runtime.php';\n"
            + ''.join("require __DIR__ . '/lib/" + n + ".php';\n" for n in requires))
        (app / 'share.php').write_text("<?php\nrequire __DIR__ . '/bootstrap.php';\n"
            + "try { require __DIR__ . '/actions/get_internal_link.php'; }"
            + " catch (Throwable $e) { http_response_code(403); DiskResponse::error($e->getMessage()); }")
        (cls.root / 'ready').write_text('ready')
        (cls.root / 'data.json').write_text(json.dumps(DATA))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.url = 'http://127.0.0.1:' + str(port)
        cls.opener = build_opener(ProxyHandler({}), NoRedirect())
        cls.log = tempfile.TemporaryFile()
        cls.server = subprocess.Popen(shlex.split(os.environ.get('PHP_COMMAND', 'php'))
            + ['-S', '127.0.0.1:' + str(port), '-t', str(cls.root)], stdout=cls.log, stderr=cls.log)
        for _ in range(100):
            try:
                with cls.opener.open(cls.url + '/ready', timeout=1) as res:
                    if res.read() == b'ready':
                        return
            except URLError:
                time.sleep(.02)
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.log.seek(0)
        raise RuntimeError(cls.log.read().decode())

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.log.close()
        cls.temp.cleanup()

    def setUp(self):
        self.data = copy.deepcopy(DATA)
        self.save_data()

    def save_data(self):
        (self.root / 'data.json').write_text(json.dumps(self.data))

    def request(self, path, user=2, data=None):
        headers = {'X-Test-User': str(user)}
        if data is not None:
            headers['Content-Type'] = 'application/json'
        req = Request(self.url + path, headers=headers,
                      data=json.dumps(data).encode() if data is not None else None)
        try:
            res = self.opener.open(req, timeout=5)
        except HTTPError as error:
            res = error
        with res:
            body = res.read().decode()
            self.assertNotIn('Fatal error', body)
            self.assertNotIn('Warning:', body)
            return res.status, res.headers, body

    def folder(self, user=2, **params):
        query = dict(siteId=1, pageId=2, blockId=3, folderId=10)
        query.update(params)
        return self.request(BASE + '/open_folder.php?' + urlencode(query), user)

    def share(self, type='folder', id=10, user=1, **params):
        query = dict(siteId=1, pageId=2, blockId=3, entityType=type, entityId=id, sessid='test-session')
        query.update(params)
        return self.request(BASE + '/share.php', user, query)

    def assert_denied(self, **params):
        status, headers, body = self.folder(**params)
        self.assertEqual(status, 403)
        self.assertIn('Папка недоступна', body)
        self.assertIn('no-store', headers['Cache-Control'])
        self.assertNotIn('План.pdf', body)

    def test_share_link_opens_folder_in_sitebuilder(self):
        status, _, body = self.share()
        self.assertEqual(status, 200, body)
        url = urlsplit(json.loads(body)['data']['url'])
        self.assertEqual(url.path, BASE + '/open_folder.php')
        self.assertEqual(parse_qs(url.query), {'siteId': ['1'], 'pageId': ['2'], 'blockId': ['3'], 'folderId': ['10']})
        status, headers, body = self.request(url.path + '?' + url.query, user=2)
        self.assertEqual(status, 200)
        self.assertIsNone(headers.get('Location'))
        self.assertIn('План.pdf', body)

    def test_file_share_link_keeps_standalone_viewer(self):
        status, _, body = self.share('file', 1000)
        self.assertEqual(status, 200)
        url = urlsplit(json.loads(body)['data']['url'])
        self.assertEqual(url.path, BASE + '/open_file.php')
        self.assertEqual(parse_qs(url.query)['fileId'], ['1000'])

    def test_listing_filters_native_custom_and_deleted_items(self):
        status, headers, body = self.folder()
        self.assertEqual(status, 200, body)
        self.assertIn('no-store', headers['Cache-Control'])
        self.assertIn('Документы &lt;отдела&gt;', body)
        self.assertIn('Вложенная папка', body)
        for hidden in ['NATIVE_SECRET', 'CUSTOM_SECRET', 'DELETED_', 'PARENT_NOT', 'SIBLING_NOT', 'FOREIGN_FOLDER']:
            self.assertNotIn(hidden, body)
        self.assertIn('open_file.php?siteId=1&amp;pageId=2&amp;blockId=3&amp;fileId=1000', body)

    def test_nested_navigation_preserves_shared_root(self):
        status, _, body = self.folder(currentFolderId=11)
        self.assertEqual(status, 200)
        self.assertIn('Вложенный документ.docx', body)
        self.assertIn('folderId=10&amp;currentFolderId=10', body)
        self.assertNotIn('PARENT_NOT', body)

    def test_current_folder_cannot_escape_shared_root(self):
        for id in [1, 20, 99, 0, -1, 1000]:
            with self.subTest(id=id):
                self.assert_denied(currentFolderId=id)

    def test_shared_root_must_belong_to_block(self):
        self.assert_denied(folderId=99)

    def test_context_substitution_is_denied(self):
        for field in ['siteId', 'pageId', 'blockId']:
            with self.subTest(field=field):
                self.assert_denied(**{field: 999})

    def test_recipient_page_and_disk_access_required(self):
        for field in ['pageDeniedUsers', 'diskDeniedUsers']:
            with self.subTest(field=field):
                self.data = copy.deepcopy(DATA)
                self.data[field] = [2]
                self.save_data()
                self.assert_denied()

    def test_recipient_native_read_required(self):
        self.data['objects']['10']['deniedUsers'] = [2]
        self.save_data()
        self.assertEqual(self.share()[0], 200)
        self.assert_denied()

    def test_revoke_shared_root_custom_access_blocks_nested_link(self):
        self.data['folderRoles']['2:10'] = 'DENY'
        self.data['folderRoles']['2:11'] = 'VIEWER'
        self.save_data()
        self.assert_denied(currentFolderId=11)

    def test_closed_child_direct_link_denied(self):
        self.assert_denied(currentFolderId=12)
        self.assert_denied(currentFolderId=13)

    def test_empty_folder_message(self):
        status, _, body = self.folder(currentFolderId=15)
        self.assertEqual(status, 200)
        self.assertIn('нет доступных файлов', body)

    def test_deleted_moved_and_missing_roots(self):
        for change in ['deleted', 'moved', 'missing']:
            with self.subTest(change=change):
                self.data = copy.deepcopy(DATA)
                if change == 'deleted': self.data['objects']['10']['deleted'] = True
                if change == 'moved': self.data['objects']['10']['parentId'] = 99
                if change == 'missing': del self.data['objects']['10']
                self.save_data()
                self.assert_denied()

    def test_unresolved_block_root_denied(self):
        self.data['blockRoot'] = None
        self.save_data()
        self.assert_denied()

    def test_cyclic_folder_chain_does_not_hang(self):
        self.data['objects']['10']['parentId'] = 11
        self.save_data()
        self.assert_denied()

    def test_unauthenticated_recipient_returns_after_login(self):
        status, headers, _ = self.folder(user=0, currentFolderId=11)
        self.assertEqual(status, 302)
        return_url = parse_qs(urlsplit(headers['Location']).query)['return'][0]
        self.assertEqual(parse_qs(urlsplit(return_url).query)['currentFolderId'], ['11'])

    def test_share_still_requires_csrf_and_permissions(self):
        self.assertEqual(self.share(sessid='wrong')[0], 403)
        self.assertEqual(self.share(id=12, user=2)[0], 403)
        self.assertEqual(self.share(id=13, user=2)[0], 403)
        self.assertEqual(self.share(id=99)[0], 403)


if __name__ == '__main__':
    unittest.main(verbosity=2)
