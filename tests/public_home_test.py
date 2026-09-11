"""HTTP regressions using real routes, renderer, template and site handler.

Run: python3 tests/public_home_test.py (PHP 8 with mbstring required).
PHP_COMMAND may specify a PHP executable and extra ini/extension arguments.
Only Bitrix authentication and database storage/revisions are fixture adapters;
no live portal or database is used or modified.
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
from urllib.parse import quote, urlencode
from urllib.request import build_opener, HTTPRedirectHandler, ProxyHandler, Request


SOURCE = Path(os.environ.get("SB_HOME_TEST_SOURCE", Path(__file__).resolve().parents[1]))
BASE = "/local/sitebuilder"


def page(id, slug, parent=0, status="published", site=1, sort=None):
    return dict(id=id, siteId=site, slug=slug, title="Page " + str(id),
                parentId=parent, status=status, sort=id if sort is None else sort)


DATA = {
    "sites": [dict(id=1, slug="project", name="Project", homePageId=20, version=5),
              dict(id=2, slug="сайт", name="Second", homePageId=90, version=1)],
    "pages": [page(10, "about"), page(20, "home"), page(21, "child", parent=20),
              page(30, "draft", status="draft"), page(31, "hidden", parent=30),
              page(40, "orphan", parent=999), page(50, "cycle", parent=51),
              page(51, "cycle-child", parent=50), page(90, "главная", site=2)],
}

RUNTIME = r'''<?php
$fixturePath = $_SERVER['DOCUMENT_ROOT'] . '/data.json';
$fixture = json_decode(file_get_contents($fixturePath), true);
$USER = new class {
    public function GetID() { return (int)($_SERVER['HTTP_X_TEST_USER'] ?? 1); }
    public function IsAdmin() { return false; }
};
$APPLICATION = new class { public function ShowHead() {} };
function sitebuilder_require_auth() {
    if (!$GLOBALS['USER']->GetID()) { http_response_code(403); exit; }
}
function sb_read_sites(): array { return $GLOBALS['fixture']['sites']; }
function sb_read_pages(): array { return $GLOBALS['fixture']['pages']; }
function sb_read_blocks(): array { return []; }
function sb_read_layouts(): array { return []; }
function sb_read_menus(): array { return []; }
function sb_json_error($error, $status = 400, $extra = []) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $error], $extra)); exit;
}
function sb_json_ok($data) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data)); exit;
}
function sb_require_site_role($siteId, $rank) {
    if ((int)($_SERVER['HTTP_X_TEST_RANK'] ?? 4) < $rank) sb_json_error('ACCESS_DENIED', 403);
}
class PageAccessService {
    public static function filterVisiblePages($pages, $siteId, $userId) {
        $visible = $_SERVER['HTTP_X_TEST_VISIBLE'] ?? '*';
        if ($visible === '*') return $pages;
        $ids = array_map('intval', explode(',', $visible));
        return array_values(array_filter($pages, fn($p) => in_array((int)$p['id'], $ids, true)));
    }
}
class RevisionService {
    public static function requireExpectedVersion($version) {
        if ((int)$version < 1) sb_json_error('EXPECTED_VERSION_REQUIRED', 422);
        return (int)$version;
    }
    public static function getSite($id, $deleted) { return sb_find_site($id); }
    public static function saveSite($site, $version, $userId, $operation) {
        $before = sb_find_site($site['id']);
        if ($version !== $before['version']) sb_json_error('VERSION_CONFLICT', 409);
        if ($operation !== 'home_page_change') throw new RuntimeException('Unexpected operation');
        $site['version']++;
        foreach ($GLOBALS['fixture']['sites'] as &$row) {
            if ($row['id'] === $site['id']) $row = $site;
        }
        file_put_contents($GLOBALS['fixturePath'], json_encode($GLOBALS['fixture']));
        return $site;
    }
}
'''

ROUTER = r'''<?php
require __DIR__ . '/runtime.php';
$base = '/local/sitebuilder';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/ready') { echo 'ready'; return; }
if ($path === $base . '/api.php') {
    require __DIR__ . $base . '/lib/helpers.php';
    $action = $_POST['action'] ?? '';
    if ($action !== 'site.setHome') { http_response_code(404); return; }
    require __DIR__ . $base . '/api/handlers/site.php'; return;
}
require __DIR__ . $base . '/lib/PublicRouteService.php';
foreach (PublicRouteService::status($base)['rules'] as $rule) {
    if (preg_match($rule['CONDITION'], $path)) {
        parse_str(preg_replace($rule['CONDITION'], $rule['RULE'], $path), $routeQuery);
        $_GET = array_merge($_GET, $routeQuery);
        require __DIR__ . $rule['PATH']; return;
    }
}
if ($path === $base . '/public.php') { require __DIR__ . $path; return; }
http_response_code(404);
'''


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class PublicHomeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="sb-home-")
        cls.root = Path(cls.temp.name)
        app = cls.root / "local/sitebuilder"
        for filename in ["public.php", "sitemap.php", "lib/public_routes.php",
                         "lib/public_render.php", "lib/PublicRouteService.php",
                         "lib/helpers.php", "views/layout/public_page.php", "api/handlers/site.php"]:
            target = app / filename
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(SOURCE / filename, target)
        for filename in ["auth.php", "db.php", "storage_db.php", "storage_db_extra.php",
                         "json.php", "response.php", "access.php", "PageAccessRepository.php",
                         "PageAccessService.php", "SiteAppearanceService.php", "SiteDeletionService.php"]:
            (app / "lib" / filename).write_text("<?php\n")
        prolog = cls.root / "bitrix/modules/main/include/prolog_before.php"
        prolog.parent.mkdir(parents=True)
        prolog.write_text("<?php\n")
        (cls.root / "runtime.php").write_text(RUNTIME)
        (cls.root / "router.php").write_text(ROUTER)
        (cls.root / "data.json").write_text(json.dumps(DATA))
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        cls.url = "http://127.0.0.1:" + str(port)
        cls.opener = build_opener(ProxyHandler({}), NoRedirect())
        cls.log = tempfile.TemporaryFile()
        cls.server = subprocess.Popen(
            shlex.split(os.environ.get("PHP_COMMAND", "php"))
            + ["-S", "127.0.0.1:" + str(port), "-t", str(cls.root), str(cls.root / "router.php")],
            stdout=cls.log, stderr=cls.log,
        )
        for _ in range(100):
            try:
                with cls.opener.open(cls.url + "/ready", timeout=1) as res:
                    if res.read() == b"ready":
                        return
            except URLError:
                time.sleep(0.02)
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
        self.write_data()

    def write_data(self):
        (self.root / "data.json").write_text(json.dumps(self.data))

    def request(self, path, headers=None, post=None):
        req = Request(self.url + path, headers=headers or {},
                      data=urlencode(post).encode() if post is not None else None)
        try:
            res = self.opener.open(req, timeout=5)
        except HTTPError as error:
            res = error
        with res:
            body = res.read().decode()
            self.assertNotIn("Fatal error", body)
            self.assertNotIn("Warning:", body)
            return res.status, res.headers, body

    def assert_page(self, path, id, headers=None):
        status, response_headers, body = self.request(path, headers)
        self.assertEqual(status, 200, body)
        self.assertIsNone(response_headers.get("Location"))
        self.assertIn("<title>Page " + str(id), body)

    def test_root_renders_selected_home(self):
        self.assert_page(BASE + "/s/project/", 20)
        self.assert_page(BASE + "/s/project/?utm_source=test", 20)

    def test_regular_page_and_home_child_keep_their_paths(self):
        self.assert_page(BASE + "/s/project/about/", 10)
        self.assert_page(BASE + "/s/project/home/child/", 21)

    def test_nested_home_and_its_child(self):
        self.data["sites"][0]["homePageId"] = 21
        self.data["pages"].append(page(22, "deep", parent=21))
        self.write_data()
        self.assert_page(BASE + "/s/project/", 21)
        self.assert_page(BASE + "/s/project/home/child/deep/", 22)

    def test_home_and_legacy_redirects_end_at_root(self):
        for path in ["/s/project/home/", "/s/project", "/public.php?siteId=1",
                     "/public.php?siteId=1&pageId=20"]:
            with self.subTest(path=path):
                status, headers, _ = self.request(BASE + path)
                self.assertEqual(status, 302)
                self.assertEqual(headers["Location"], BASE + "/s/project/")
                self.assert_page(headers["Location"], 20)

    def test_regular_legacy_redirect_preserves_query(self):
        status, headers, _ = self.request(BASE + "/public.php?siteId=1&pageId=21&utm_source=test")
        self.assertEqual(status, 301)
        self.assertEqual(headers["Location"], BASE + "/s/project/home/child/?utm_source=test")

    def test_missing_invalid_unpublished_or_foreign_home_falls_back(self):
        for id in [0, 999, 30, 31, 40, 50, 90]:
            with self.subTest(home=id):
                self.data["sites"][0]["homePageId"] = id
                self.write_data()
                self.assert_page(BASE + "/s/project/", 10)

    def test_viewer_access_filters_home_before_selection(self):
        self.assert_page(BASE + "/s/project/", 10, {"X-Test-Visible": "10"})
        self.assertEqual(self.request(BASE + "/s/project/home/", {"X-Test-Visible": "10"})[0], 404)
        self.assertEqual(self.request(BASE + "/s/project/", {"X-Test-Visible": "0"})[0], 404)
        self.assertEqual(self.request(BASE + "/s/project/", {"X-Test-User": "0"})[0], 403)

    def test_unknown_paths_and_unpublished_pages_stay_404(self):
        for path in ["/s/missing/", "/s/project/missing/", "/s/project/draft/",
                     "/s/project/draft/hidden/", "/s/project/orphan/", "/s/project/child/"]:
            with self.subTest(path=path):
                self.assertEqual(self.request(BASE + path)[0], 404)

    def test_empty_site_is_404(self):
        self.data["pages"] = []
        self.write_data()
        self.assertEqual(self.request(BASE + "/s/project/")[0], 404)

    def test_cyrillic_slug_and_case_normalization(self):
        self.assert_page(BASE + "/s/" + quote("сайт") + "/", 90)
        status, headers, _ = self.request(BASE + "/s/" + quote("САЙТ") + "/")
        self.assertEqual(status, 302)
        self.assertEqual(headers["Location"], BASE + "/s/" + quote("сайт") + "/")

    def test_sitemap_home_uses_root_and_child_keeps_parent(self):
        status, _, body = self.request(BASE + "/s/project/sitemap.xml")
        self.assertEqual(status, 200)
        self.assertIn(self.url + BASE + "/s/project/</loc>", body)
        self.assertNotIn(self.url + BASE + "/s/project/home/</loc>", body)
        self.assertIn(self.url + BASE + "/s/project/home/child/</loc>", body)
        self.assertNotIn('/draft/', body)

    def save_home(self, page_id, version=5, headers=None):
        data = dict(action="site.setHome", siteId=1, expectedVersion=version)
        if page_id is not None:
            data["pageId"] = page_id
        return self.request(BASE + "/api.php", headers, data)

    def test_save_home_then_open_root_and_reset_automatic(self):
        status, _, body = self.save_home(21)
        self.assertEqual(status, 200, body)
        site = json.loads(body)["site"]
        self.assertEqual((site["homePageId"], site["version"]), (21, 6))
        self.assertEqual(site["publicUrl"], BASE + "/s/project/")
        self.assert_page(site["publicUrl"], 21)
        status, _, body = self.save_home(0, version=6)
        self.assertEqual(status, 200, body)
        self.assertEqual(json.loads(body)["site"]["homePageId"], 0)
        self.assert_page(BASE + "/s/project/", 10)

    def test_save_rejects_invalid_home(self):
        for id, error in [(30, "HOME_PAGE_NOT_PUBLISHED"), (31, "HOME_PAGE_NOT_PUBLISHED"),
                          (40, "HOME_PAGE_NOT_PUBLISHED"), (50, "HOME_PAGE_NOT_PUBLISHED"),
                          (90, "PAGE_NOT_IN_SITE"), (999, "PAGE_NOT_IN_SITE"),
                          (None, "SITE_PAGE_REQUIRED"), (-1, "SITE_PAGE_REQUIRED")]:
            with self.subTest(page=id):
                status, _, body = self.save_home(id)
                self.assertEqual(status, 422, body)
                self.assertEqual(json.loads(body)["error"], error)

    def test_save_still_checks_role_and_version(self):
        self.assertEqual(self.save_home(21, headers={"X-Test-Rank": "2"})[0], 403)
        self.assertEqual(self.save_home(21, version=4)[0], 409)
        self.assertEqual(self.save_home(21, version=0)[0], 422)
        self.assert_page(BASE + "/s/project/", 20)


if __name__ == "__main__":
    unittest.main(verbosity=2)
