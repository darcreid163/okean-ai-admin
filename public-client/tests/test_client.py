import importlib.util
import json
import os
import unittest
from pathlib import Path

path = Path(__file__).resolve().parents[1] / "wpai_client.py"
spec = importlib.util.spec_from_file_location("wpai_client", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FakeMCP:
    def __init__(self):
        self.calls = []
        self.uncertain = False

    def call(self, name, arguments):
        self.calls.append((name, arguments))
        value = [{"id": "site-1"}] if name == "cloud_sites_list" else {"ok": True}
        if self.uncertain:
            value = {"error": "request_outcome_pending_reconciliation"}
        return {"content": [{"type": "text", "text": json.dumps(value)}], "isError": self.uncertain}


def call(identity, name, arguments):
    return {"id": identity, "type": "function", "function": {"name": name, "arguments": json.dumps(arguments)}}


CATALOG = [{"name": name, "inputSchema": {"type": "object", "properties": {}}, "description": "fixture"} for name in ["cloud_sites_list", "cloud_usage", "cloud_site_tools_list", "cloud_site_tool_call"]]


class Tests(unittest.TestCase):
    def setUp(self):
        self.mcp = FakeMCP()
        self.adapter = module.QwenAdapter(self.mcp, CATALOG)

    def test_canonical_and_secret_boundary(self):
        with self.assertRaises(module.ClientError):
            module.MCP("secret")
        with self.assertRaises(module.ClientError):
            module.MCP("wpai_cloud_" + "a" * 43, "https://evil.example.com/mcp")

    def test_schema_conversion(self):
        self.assertEqual(module.functions(CATALOG)[0]["function"]["parameters"], CATALOG[0]["inputSchema"])
        with self.assertRaises(module.ClientError):
            module.functions([{"name": "shell_exec"}])

    def test_scope_selection_and_write_confirmation(self):
        with self.assertRaises(module.ClientError):
            self.adapter.execute(call("1", "cloud_site_tools_list", {"site_id": "site-1"}))
        self.adapter.execute(call("2", "cloud_sites_list", {}))
        self.adapter.execute(call("3", "cloud_site_tools_list", {"site_id": "site-1"}))
        denied = self.adapter.execute(call("4", "cloud_site_tool_call", {"site_id": "site-1", "tool": "operation_apply"}))
        self.assertTrue(denied["isError"])
        self.assertEqual(len(self.mcp.calls), 2)

    def test_retry_bound_to_original_arguments(self):
        value = call("original", "cloud_sites_list", {})
        first = self.adapter.execute(value)
        self.assertEqual(first, self.adapter.execute(value))
        self.assertEqual(len(self.mcp.calls), 1)
        with self.assertRaises(module.ClientError):
            self.adapter.execute(call("original", "cloud_usage", {}))

    def test_confirmation_does_not_change_nonce(self):
        self.adapter.confirm = lambda args: True
        self.adapter.execute(call("1", "cloud_sites_list", {}))
        args = {"site_id": "site-1", "tool": "operation_apply", "arguments": {"id": 3}, "idempotency_key": "logical-1"}
        value = call("apply", "cloud_site_tool_call", args)
        self.adapter.execute(value)
        self.adapter.execute(value)
        self.assertEqual(self.mcp.calls[-1][1], args)
        self.assertEqual(len(self.mcp.calls), 2)

    def test_uncertain_result_halts_new_actions(self):
        self.mcp.uncertain = True
        self.adapter.execute(call("1", "cloud_usage", {}))
        with self.assertRaises(module.ClientError):
            self.adapter.execute(call("2", "cloud_usage", {}))
        self.assertEqual(len(self.mcp.calls), 1)

    def test_network_exception_halts_new_actions(self):
        def fail(*args):
            raise module.ClientError("network_outcome_unknown")
        self.mcp.call = fail
        with self.assertRaises(module.ClientError):
            self.adapter.execute(call("1", "cloud_usage", {}))
        self.assertTrue(self.adapter.halted)

    def test_model_loop_uses_qwen38_and_function_results(self):
        os.environ["WPAI_QWEN_API_BASE"] = "https://fixture.example.com/v1"
        os.environ["DASHSCOPE_API_KEY"] = "test-key-not-real"
        class MCP(FakeMCP):
            def initialize(self): pass
            def rpc(self, method): return {"tools": CATALOG}
        rounds = []
        def model(url, payload, headers):
            rounds.append(json.loads(json.dumps(payload)))
            if len(rounds) == 1:
                return {"choices": [{"message": {"role": "assistant", "content": "", "tool_calls": [call("sites", "cloud_sites_list", {})]}}]}
            return {"choices": [{"message": {"role": "assistant", "content": "site-1"}}]}
        self.assertEqual(module.qwen(MCP(), "list sites", model_transport=model), "site-1")
        self.assertEqual(rounds[0]["model"], "qwen3.8-max")
        self.assertEqual(rounds[1]["messages"][-1]["role"], "tool")
        self.assertEqual(rounds[1]["messages"][-1]["tool_call_id"], "sites")

    def test_jsonrpc_id_and_notification_validation(self):
        def wrong(url, payload, headers): return {"jsonrpc": "2.0", "id": 100, "result": {}}
        mcp = module.MCP("wpai_cloud_" + "a" * 43, transport=wrong)
        with self.assertRaises(module.ClientError): mcp.rpc("ping")
        with self.assertRaises(module.ClientError): mcp.rpc("notifications/initialized", notification=True)

    def test_provider_registry_and_oauth_transport(self):
        data = json.loads((path.parent / "providers.json").read_text())
        self.assertEqual(data["mcp_url"], module.ENDPOINT)
        self.assertEqual(set(data["providers"]), {"chatgpt", "qwen", "claude", "deepseek"})
        self.assertEqual(data["providers"]["qwen"]["model"], "qwen3.8-max")
        self.assertEqual(data["providers"]["deepseek"]["stage"], "planned")


if __name__ == "__main__":
    unittest.main()
