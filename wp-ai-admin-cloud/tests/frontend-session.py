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
            return response.status, response.headers, response.read().decode()

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

if __name__ == '__main__':
    unittest.main()
