# Expanded read-only WP-CLI commands (Tier B, slice 1)

Status: read-only commands only. Writes remain `plan_only` and still require the
sandbox → human approval → apply → verify → receipt → rollback lifecycle.
Implementation is original code against WordPress APIs; no third-party source is copied.

## What changed

`wordpress.execute` previously resolved 21 commands. It now resolves **41**, and
command resolution supports three-token commands (`post meta get 42 key`) without
breaking two-token commands (`post get 42`).

Two defects were fixed on the way:

1. **Subverb resolution.** The normalizer sliced positional arguments after two
   tokens, so `post meta get 42 key` would have produced family `post`, verb
   `meta`, and arguments `['get','42','key']`. `OAI_Command_Registry::resolve()`
   now prefers the three-token key when it exists and slices arguments after the
   matched key.
2. **Argument ranges were a hardcoded map.** Ranges now live on the registry
   entry (`'args' => array(min, max)`), and a read command without a range
   defaults to zero arguments — the same strictness as before.

## New commands

| Command | Arguments | Flags | Returns |
|---|---|---|---|
| `core check-update` | — | — | current version, offers from the `update_core` transient, `network_call=false` |
| `plugin get` | plugin file or name | — | name, version, active, requires_php, requires_wp |
| `theme mod get` | mod name | — | exists, type, bounded value, sha256 |
| `theme mod list` | — | — | mod names with type and size (no values) |
| `post meta get` | post id, key | — | exists, redacted, bounded value, sha256 |
| `post meta list` | post id | — | keys with redaction flags (no values) |
| `post-type list` | — | — | name, label, public, hierarchical, has_archive |
| `taxonomy list` | — | — | name, label, public, hierarchical, object types |
| `term get` | taxonomy, term id | — | term id, name, slug, parent, count |
| `user meta get` | user id, key | — | exists, redacted, bounded value |
| `user meta list` | user id | — | keys with redaction flags (no values) |
| `comment list` | — | `post_id`, `number`, `status` | id, post id, author name, status, date, content length |
| `comment count` | — | `post_id` | raw counts |
| `role list` | — | — | role, display name, capability count |
| `cap list` | optional role | — | capabilities with false entries filtered, or per-role counts |
| `sidebar list` | — | — | sidebar id, widget count |
| `widget list` | optional sidebar | — | widget ids for one sidebar, or counts for all |
| `menu item list` | menu id or name | — | id, title, type, object, parent, position, url |
| `db tables` | — | — | table names and count only, never rows |
| `db prefix` | — | — | table prefix, base prefix, blog id |

## Safety properties enforced by the new handlers

- **Credential redaction.** Any meta key matching `pass|pwd|secret|token|salt|nonce|api_key|private|credential|session|cookie`
  is returned as `redacted=true` with `value=null`. `*_list` commands never return values at all.
- **PII exclusion.** `comment list` returns no email, IP or author URL. A test asserts
  neither the fixture email nor the fixture IP appears anywhere in the payload.
- **Bounded payloads.** Strings over 2000 characters are replaced by
  `{truncated,length,sha256,sample≤200}`; arrays over 100 elements by
  `{truncated,count,sha256}`; lists are capped (300 meta keys, 300 tables, 200 theme mods, 100 comments).
- **No shell.** No handler builds a shell string; everything calls WordPress APIs.
- **No new write paths.** `plan()` still reports `sandbox_then_approval` and
  `executable_now=false` for every non-read command.

## Test

`php tests/cli-expanded-commands.php` — **62 assertions**, no WordPress, no MySQL, no network:

- resolution: three-token, two-token regression, zero-argument regression, too few arguments,
  unknown flag, unknown command, metacharacter rejection;
- every new handler's shape and edge cases (not found → explicit `WP_Error`);
- redaction of `session_tokens` and `api_key`, non-redaction of `_wp_old_slug`;
- absence of comment PII;
- `handler_audit()` proves every registered read command has an implementation;
- catalog size 41 with writes still `plan_only`.

## Known limits

- Read-only slice. `comment approve`, `term create`, `menu item add-*`, `theme mod set`
  are **not** added yet: they need the operation bridge (a CLI write plan must create a real
  `OAI_DB` operation), which is a separate change.
- `core check-update` reads the cached transient; it does not perform an HTTP check.
- Handlers assume standard WordPress tables; multisite table names are returned as-is by `db tables`.
