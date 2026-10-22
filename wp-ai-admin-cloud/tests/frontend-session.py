#!/usr/bin/env python3
"""Exercise the real Surface over HTTP with a stubbed WordPress session validator."""
import http.cookiejar
import json
import os
import pathlib
import shutil
import socket
import subprocess
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class FrontendSession(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get('WPAI_TEST_PHP') or shutil.which('php')
        if not php:
            raise RuntimeError('PHP is required')
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            port = s.getsockname()[1]
        cls.base = f'http://127.0.0.1:{port}'
        cls.server = subprocess.Popen([php, '-n', '-S', f'127.0.0.1:{port}', str(HERE / 'frontend-session-router.php')], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                urllib.request.urlopen(cls.base + '/fixture-cookies', timeout=.2).close()
                break
            except OSError:
                time.sleep(.03)
        else:
            cls.server.terminate()
            raise RuntimeError('PHP fixture did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)

    def request(self, path, cookie='', data=None):
        req = urllib.request.Request(self.base + path, headers={'Cookie': cookie}, data=data)
        try:
            response = urllib.request.build_opener(NoRedirect()).open(req, timeout=3)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read().decode()
            self.assertNotIn('<b>Warning</b>', body)
            self.assertNotIn('<b>Fatal error</b>', body)
            return response.status, response.headers, body

    def test_browser_cookie_scope_and_single_login_handoff(self):
        jar = http.cookiejar.CookieJar()
        browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        with browser.open(self.base + '/fixture-login', timeout=3) as response:
            self.assertEqual(response.status, 200)
            self.assertIn('owned-site', response.read().decode())
            self.assertEqual(response.url, self.base + '/wpai-cloud/connect')
        with browser.open(self.base + '/fixture-cookies') as response:
            cookies = json.load(response)
        self.assertIn('wordpress_logged_in_fixture', cookies)
        self.assertNotIn('wordpress_admin_fixture', cookies)

    def test_anonymous_portal_login_has_no_reauth(self):
        status, headers, _ = self.request('/wpai-cloud/connect?redirect_to=https://evil.example')
        self.assertEqual(status, 302)
        login = urllib.parse.urlparse(headers['Location'])
        query = urllib.parse.parse_qs(login.query)
        self.assertEqual(login.netloc, urllib.parse.urlparse(self.base).netloc)
        self.assertEqual(query['redirect_to'], [self.base + '/wpai-cloud/connect'])
        self.assertNotIn('reauth', query)

    def test_expired_session_requires_login(self):
        status, _, _ = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=expired')
        self.assertEqual(status, 302)

    def test_oauth_login_preserves_parameters_and_ignores_extra_redirect(self):
        query = {'client_id':'client-1','redirect_uri':'https://client.example/callback?a=1&b=2','response_type':'code','scope':'mcp:read offline_access','state':'a+b/&?','code_challenge':'x'*43,'code_challenge_method':'S256','resource':'https://wpaiadmin.ru/mcp'}
        status, headers, _ = self.request('/wpai-cloud/oauth/authorize?' + urllib.parse.urlencode(query | {'redirect_to':'https://evil.example'}))
        self.assertEqual(status, 302)
        outer = urllib.parse.parse_qs(urllib.parse.urlparse(headers['Location']).query)
        return_url = urllib.parse.urlparse(outer['redirect_to'][0])
        self.assertEqual(return_url.path, '/wpai-cloud/oauth/authorize')
        self.assertEqual(return_url.netloc, urllib.parse.urlparse(self.base).netloc)
        self.assertEqual(urllib.parse.parse_qs(return_url.query), {k:[v] for k,v in query.items()})
        self.assertNotIn('reauth', outer)

    def test_authenticated_oauth_reaches_consent(self):
        status, _, body = self.request('/wpai-cloud/oauth/authorize', 'wordpress_logged_in_fixture=validated-session')
        self.assertEqual(status, 200)
        self.assertIn('fixture-consent-reached', body)

    def test_beta_allowlist_still_blocks(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session; fixture_access=blocked')
        self.assertEqual(status, 200)
        self.assertIn('пока не открыт', body)
        self.assertNotIn('name="site_token"', body)

    def test_gateway_off_is_explained(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session; fixture_gateway=off')
        self.assertEqual(status, 200)
        self.assertIn('Gateway ещё не включён', body)
        self.assertNotIn('name="site_token"', body)

    def test_connect_post_still_requires_nonce(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session', b'home=https%3A%2F%2Fowned.example&site_token=test')
        self.assertEqual(status, 403)
        self.assertIn('fixture-invalid-nonce', body)
        self.assertNotIn('fixture-connect-called', body)

    def test_pairing_callback_login_preserves_code_and_state(self):
        status, headers, _ = self.request('/wpai-cloud/pair/callback?state=' + 's'*43 + '&code=' + 'c'*43)
        self.assertEqual(status, 302)
        login_query = urllib.parse.parse_qs(urllib.parse.urlparse(headers['Location']).query)
        callback = urllib.parse.urlparse(login_query['redirect_to'][0])
        self.assertEqual(callback.path, '/wpai-cloud/pair/callback')
        self.assertEqual(urllib.parse.parse_qs(callback.query), {'state':['s'*43], 'code':['c'*43]})
        self.assertNotIn('reauth', login_query)

    def test_automatic_connect_is_primary_form(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session')
        self.assertEqual(status, 200)
        self.assertIn('Подключить автоматически', body)
        self.assertLess(body.index('value="automatic"'), body.index('name="site_token"'))

    def test_second_domain_commits_document_before_navigation(self):
        status, headers, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session', urllib.parse.urlencode({'home':'https://okean.market','connect_mode':'automatic','wpai_nonce':'fixture-nonce'}).encode())
        self.assertEqual(status, 200)
        self.assertNotIn('Location', headers)
        self.assertIn("form-action 'self'", headers['Content-Security-Policy'])
        self.assertNotIn('https:', headers['Content-Security-Policy'])
        self.assertIn('http-equiv="refresh"', body)
        self.assertIn('https://other.example/fixture-site-consent?state=', body)
        self.assertIn('Продолжить подключение', body)

    def test_account_summary_uses_one_plan_for_two_sites(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session; fixture_connected=1')
        self.assertEqual(status, 200)
        self.assertIn('Один аккаунт и один тариф', body)
        self.assertIn('<strong>PRO</strong>', body)
        self.assertIn('Осталось: 492', body)
        self.assertIn('Подключено сайтов: <strong>2</strong>', body)
        self.assertIn('owned-site', body)
        self.assertIn('second-site', body)

    def test_current_pro_uses_daily_utc_window(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session')
        self.assertEqual(status, 200)
        self.assertIn('Период учёта: сутки (UTC)', body)
        self.assertIn('2026-10-23T00:00:00Z', body)
        self.assertIn('https://wpaiadmin.ru/mcp', body)
        self.assertIn('Подключить автоматически', body)
        self.assertNotIn('Сохранены прежние месячные условия', body)
        self.assertNotIn('Сервис подключения временно недоступен', body)

    def test_free_uses_monthly_utc_window(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session; fixture_plan=free')
        self.assertEqual(status, 200)
        self.assertIn('<strong>FREE</strong>', body)
        self.assertIn('Период учёта: месяц (UTC)', body)
        self.assertIn('2026-11-01T00:00:00Z', body)
        self.assertIn('Подключить автоматически', body)

    def test_legacy_pro_retains_monthly_terms(self):
        status, _, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session; fixture_quota=legacy')
        self.assertEqual(status, 200)
        self.assertIn('<strong>PRO</strong>', body)
        self.assertIn('Период учёта: месяц (UTC)', body)
        self.assertIn('2026-11-01T00:00:00Z', body)
        self.assertIn('Сохранены прежние месячные условия', body)
        self.assertIn('Подключить автоматически', body)

    def test_preflight_does_not_register_or_navigate(self):
        status, headers, body = self.request('/wpai-cloud/connect', 'wordpress_logged_in_fixture=validated-session', urllib.parse.urlencode({'home':'https://okean.market','connect_mode':'check','wpai_nonce':'fixture-nonce'}).encode())
        self.assertEqual(status, 200)
        self.assertIn('Связь с сайтом подтверждена', body)
        self.assertIn('Подключено сайтов: <strong>1</strong>', body)
        self.assertNotIn('Location', headers)
        self.assertNotIn('http-equiv="refresh"', body)

    def test_oauth_allow_and_deny_commit_document_before_external_callback(self):
        cookie='wordpress_logged_in_fixture=validated-session; fixture_connected=1; fixture_oauth=ready'
        for decision in ['allow','deny']:
            payload=[('decision',decision),('wpai_nonce','fixture-nonce'),('sites[]','owned-site'),('sites[]','second-site')]
            status, headers, body = self.request('/wpai-cloud/oauth/authorize',cookie,urllib.parse.urlencode(payload).encode())
            self.assertEqual(status,200)
            self.assertNotIn('Location',headers)
            self.assertIn('http-equiv="refresh"',body)
            self.assertIn('state=state%2Band%26value',body)
            self.assertIn('code=' if decision=='allow' else 'error=access_denied',body)

    def test_invalid_automatic_nonce_cannot_navigate(self):
        status, _, body = self.request('/wpai-cloud/connect','wordpress_logged_in_fixture=validated-session',urllib.parse.urlencode({'home':'https://okean.market','connect_mode':'automatic','wpai_nonce':'invalid'}).encode())
        self.assertEqual(status,403)
        self.assertNotIn('http-equiv="refresh"',body)

if __name__ == '__main__':
    unittest.main()
