# API and MCP server

Scripts, other apps, and AI assistants can list workflows, start runs, and follow them. There are two ways in, and they do the same things:

- **The REST API**, at `/api/v1`, for scripts and other apps.
- **The MCP server**, at `/mcp`, for AI assistants such as Claude that support the [Model Context Protocol](https://modelcontextprotocol.io).

Both act as you. They can only do what your role allows in each workspace: a Viewer can see runs but not start them, for example. Both also answer with the same JSON.

## Connecting an AI assistant

The MCP server is on unless the install's administrator turned it off with `MCP_SERVER_ENABLED=false` (see [Self-hosting](self-hosting.md#settings)). When it's off, **Preferences → API tokens** doesn't show its URL.


1. Open **Preferences → API tokens** and copy the **MCP server URL**. It's your install's address followed by `/mcp`, such as `https://wag.example.com/mcp`.
2. In the assistant, add it as a custom connector or remote MCP server.
3. The assistant opens a wag.run page asking you to sign in and approve it. Choose **Authorize**.

The assistant then shows up under **Connected apps** on the same settings page. Choose **Disconnect** to take its access away. An install accepts up to 10 new assistant registrations a minute.

The assistant gets these tools:

| Tool | What it does |
|---|---|
| `list-workspaces` | The workspaces you belong to |
| `list-workflows` | A workspace's workflows, with the inputs each one takes |
| `get-workflow` | One workflow, its inputs, and its definition |
| `start-run` | Start a run of a workflow's published version |
| `list-runs` | A workspace's runs, newest first, optionally for one workflow or one status |
| `get-run` | One run with its input, output, error, the current values of its workflow variables, and every step |
| `cancel-run` | Cancel a run that's still going |

An assistant that can't connect through OAuth can use a personal access token instead, sent as a bearer token. It's limited to the token's scopes.

## Personal access tokens

Under **Preferences → API tokens**, give the token a name, choose what it may do, and choose **Create token**. Copy the token right away: it's shown only once. Tokens expire after a year, and you can revoke one at any time.

| Scope | What it allows |
|---|---|
| `workflows:read` | List workspaces and workflows |
| `runs:read` | List runs and see their steps |
| `runs:write` | Start and cancel runs |

Send the token in the `Authorization` header:

```bash
curl https://wag.example.com/api/v1/workspaces \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H "Accept: application/json"
```

Creating and revoking tokens is recorded in the audit log.

## REST API

Every path below starts with `/api/v1`. A workspace is named by its slug, the part of its address after `/w/`. Each token can send up to 120 requests a minute.

| Method and path | What it does | Scope |
|---|---|---|
| `GET /workspaces` | List your workspaces | `workflows:read` |
| `GET /workspaces/{workspace}/workflows` | List a workspace's workflows | `workflows:read` |
| `GET /workspaces/{workspace}/workflows/{id}` | Show a workflow, its inputs, and its definition | `workflows:read` |
| `POST /workspaces/{workspace}/workflows/{id}/runs` | Start a run | `runs:write` |
| `GET /workspaces/{workspace}/runs` | List runs, newest first | `runs:read` |
| `GET /workspaces/{workspace}/runs/{id}` | Show a run, its steps, and its `variables`: the current value of each [workflow variable](editor.md#variables), by name | `runs:read` |
| `POST /workspaces/{workspace}/runs/{id}/cancel` | Cancel a run in progress | `runs:write` |

### Workflow definitions

A workflow's `definition` is its draft: its nodes, connections, variables, and settings, in the format workflows [export and import](editor.md#export-and-import) in. It never holds secret or credential values. Showing one workflow always includes it. Listing workflows leaves it out unless you add `?include=definition`:

```bash
curl "https://wag.example.com/api/v1/workspaces/ops/workflows?include=definition" \
  -H "Authorization: Bearer $WAG_TOKEN"
```

A workflow can call these endpoints itself with the [wag.run nodes](actions.md#wagrun), such as to back every workflow up.

### Starting a run

A run starts from the workflow's [Manual trigger](triggers.md#manual), which lists the inputs it takes. They're also in the workflow's `inputs`. Send their values under `input`:

```bash
curl -X POST https://wag.example.com/api/v1/workspaces/ops/workflows/$WORKFLOW_ID/runs \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"input": {"order_id": 991}}'
```

The answer is `201` with the new run. A workflow without a Manual trigger starts at its first trigger with no input. The run shows **API** as its trigger, and you as the person who started it.

The request returns as soon as the run starts. To follow it, get the run until its `status` is `complete`, `failed`, `cancelled`, or `skipped`. A run that waits for an approval can stay `waiting` for a long time.

### Loops in a run's steps

A step that ran inside a [For loop](actions.md#for-loop) has an `iteration`, such as `L12.0`: the first iteration of the loop whose step `id` is 12. A step in a loop inside that one has `L12.0/L15.3`. A step outside every loop has `null`.

The loop's own step has only `count` in its `output`. What each iteration produced is in that iteration's steps, so the list holds every output once. To read a loop's results, group the steps by `iteration`.

A run that ends with a loop still has the loop's `results` in the run's own `output`.

### Listing runs

`GET /workspaces/{workspace}/runs` takes these query parameters:

| Parameter | What it does |
|---|---|
| `workflow` | Only runs of this workflow id |
| `status` | Only runs with this status: `running`, `waiting`, `complete`, `failed`, `cancelled`, or `skipped` |
| `per_page` | Runs per page, 1 to 100. 25 by default. |
| `page` | The page, from 1 |

The answer has the runs under `data`, and the page details under `meta` and `links`.

### Errors

| Status | When |
|---|---|
| `401` | The token is missing, expired, or revoked |
| `403` | The token doesn't have the scope, your role in the workspace doesn't allow it, or your account is deactivated |
| `404` | The workspace, workflow, or run doesn't exist, or you can't see it |
| `422` | The input doesn't match the workflow's inputs, the workflow isn't published, or a filter is invalid. `errors` says which field. |
| `429` | Too many requests |

Each error has a `message`.
