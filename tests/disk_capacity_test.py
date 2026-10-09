"""Real quota/checkUpload API and ACL over HTTP; reuse the native object fixtures.
Run: python3 tests/disk_capacity_test.py (PHP_COMMAND can select PHP 8).
"""
import json
import shutil
import unittest
import shared_folder_test as shared


class DiskCapacityTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        shared.SharedFolderTest.setUpClass.__func__(cls)
        app = cls.root / shared.BASE.lstrip('/')
        for name in ['api.php', 'actions/quota.php', 'lib/DiskQuotaService.php']:
            shutil.copy2(shared.SOURCE / 'components/disk' / name, app / name)
        with (app / 'bootstrap.php').open('a') as f:
            f.write("require __DIR__ . '/lib/DiskQuotaService.php';\n")

    @classmethod
    def tearDownClass(cls):
        shared.SharedFolderTest.tearDownClass.__func__(cls)

    request = shared.SharedFolderTest.request
    save_data = shared.SharedFolderTest.save_data

    def setUp(self):
        shared.SharedFolderTest.setUp(self)
        self.data.update(blockRoot=10, quotaTest=True, settings={'allowUpload': True},
                         quotaLimits=[{'props_json': {'maxDiskSize': 4096}, 'disk_folder_id': 10}])
        self.data['folderRoles']['1:10'] = 'EDITOR'
        self.save_data()

    def api(self, action='quota', user=2, **params):
        payload = dict(siteId=1, pageId=2, blockId=3, currentFolderId=10, sessid='test-session')
        payload.update(params)
        return self.request(shared.BASE + '/api.php?action=' + action, user, payload)

    def test_reader_gets_only_aggregate_usage_with_no_cache(self):
        status, headers, body = self.api()
        self.assertEqual(status, 200, body)
        self.assertIn('no-store', headers.get('Cache-Control', ''))
        self.assertEqual(json.loads(body)['data']['quota'], dict(
            usedBytes=3072, limitBytes=4096, availableBytes=1024, hasAdditionalLimit=False))
        self.assertNotIn('SECRET', body)

    def test_upload_permission_required_even_for_metadata(self):
        files = [dict(name='a.txt', size=1)]
        status, _, body = self.api('checkUpload', files=files)
        self.assertGreaterEqual(status, 400)
        self.assertNotIn('quota', json.loads(body)['data'])
        status, _, body = self.api('checkUpload', user=1, files=files)
        self.assertEqual(status, 200, body)
        self.assertTrue(json.loads(body)['data']['upload']['fits'])

    def test_whole_batch_preflight_and_exact_fit(self):
        for size, fits in [(1024, True), (1025, False)]:
            status, _, body = self.api('checkUpload', user=1,
                                       files=[dict(name='a.txt', size=512), dict(name='b.txt', size=size-512)])
            self.assertEqual(status, 200, body)
            self.assertEqual(json.loads(body)['data']['upload']['fits'], fits)

    def test_context_csrf_and_folder_scope_are_enforced(self):
        for params in [dict(siteId=9), dict(pageId=9), dict(blockId=9), dict(sessid='wrong'),
                       dict(currentFolderId=99), dict(currentFolderId=20), dict(currentFolderId=14),
                       dict(currentFolderId=12), dict(currentFolderId=13)]:
            with self.subTest(params=params):
                status, _, body = self.api(**params)
                self.assertGreaterEqual(status, 400, body)
                self.assertFalse(json.loads(body)['ok'])
                self.assertNotIn('usedBytes', body)

    def test_page_and_disk_denial_prevent_usage_disclosure(self):
        for key in ['pageDeniedUsers', 'diskDeniedUsers']:
            with self.subTest(key=key):
                self.data[key] = [2]
                self.save_data()
                status, _, body = self.api()
                self.assertGreaterEqual(status, 400, body)
                self.assertNotIn('usedBytes', body)
                self.data[key] = []

    def test_authentication_is_required(self):
        status, headers, _ = self.api(user=0)
        self.assertEqual(status, 302)
        self.assertIn('/login', headers['Location'])

    def test_changed_limits_and_deleted_files_are_reflected(self):
        self.data['quotaLimits'][0]['props_json']['maxDiskSize'] = 3100
        self.data['objects']['1001']['deleted'] = True
        self.save_data()
        status, _, body = self.api(currentFolderId=11)
        self.assertEqual(status, 200, body)
        self.assertEqual(json.loads(body)['data']['quota']['availableBytes'], 1052)

    def test_malformed_metadata_is_rejected(self):
        for files in [None, [], [dict(name='a.txt', size=-1)], [dict(name='a.txt', size='1')]]:
            status, _, body = self.api('checkUpload', user=1, files=files)
            self.assertEqual(status, 422, body)
            self.assertEqual(json.loads(body)['error'], 'INVALID_UPLOAD_METADATA')


if __name__ == '__main__':
    unittest.main()
