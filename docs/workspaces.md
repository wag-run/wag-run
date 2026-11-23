# Workspaces

A workspace holds a team's workflows and runs, the people who work on them, and the values and sign-in details those workflows share. Each workspace is separate: a workflow can only use the variables, secrets and authentication settings of its own workspace, and can only start workflows in its own workspace.

Switch workspaces with the menu at the top of the sidebar. Organization admins create, rename and delete workspaces. See [Organization administration](administration.md#workspaces).

## Overview

**Overview** is the workspace's home page. It shows its workflows, the approvals and tasks waiting on you in this workspace, and shortcuts to its variables, secrets, authentication settings and members.

## Members and roles

**Members** lists who can use the workspace and their role. Organization admins aren't listed, because they act as Admin in every workspace.

| | Guest | Viewer | Editor | Admin |
|---|:-:|:-:|:-:|:-:|
| Answer approvals and tasks sent to them | ✓ | ✓ | ✓ | ✓ |
| See workflows, runs and members | | ✓ | ✓ | ✓ |
| See the names of variables, secrets and authentication settings | | ✓ | ✓ | ✓ |
| Build, publish, test and run workflows, and cancel runs | | | ✓ | ✓ |
| Add, change and delete variables | | | ✓ | ✓ |
| Manage members, secrets and authentication settings | | | | ✓ |
| Reveal secret values | | | | ✓ |
| Change workspace settings and read the audit log | | | | ✓ |

**Guest** is the role for people outside the team who only need to answer requests, such as a manager who approves refunds.

### Adding people

Under **Add or invite by email**, enter an email address, choose a role and click **Add**.

- If the person already has an account in the organization, they're added straight away.
- If not, they're emailed an invitation to create an account and join. Invitations expire after 7 days. Until then, they're listed under **Pending invitations**, where you can **Revoke** them.

To change someone's role, pick a new one next to their name. To take away their access, click **Remove**. Removing someone from a workspace doesn't delete their account.

## Variables

Variables are named values that any workflow in the workspace can use, such as a team's email address, a base URL or a threshold. Read one in a placeholder as `{{ vars.<key> }}`:

```text
{{ vars.api_base_url }}/orders/{{ trigger.order_id }}
```

Click **Add variable** and give it a **Key** (letters, numbers and underscores), a **Value** and an optional **Description**. Editors and Admins can manage variables.

Changing a variable affects every run from then on, without republishing. If you delete a variable, workflows that use it get an empty value.

Variables are not secret: anyone who can see the workspace's runs can see their values in step inputs. Use a secret for anything sensitive.

## Secrets

Secrets are for sensitive values, such as tokens, passwords and signing keys. They're encrypted where they're stored, and wherever a secret's value would show in a run's step input or output, it's masked.

Read one in a placeholder as `{{ secrets.<key> }}`, for example in an HTTP call header:

```text
Authorization: Bearer {{ secrets.billing_api_token }}
```

Webhook triggers also use secrets for their [shared token or signing secret](triggers.md#verifying-requests).

Admins add and edit secrets. When editing, leave the value empty to keep the current one. Viewers and Editors see only secret names, so they can tell which secrets a workflow uses. Admins can reveal a secret's value, and each reveal is recorded in the audit log.

If you delete a secret, workflows that use it fail until it's replaced.

## Authentication

Authentication settings are reusable sign-in details for other systems. Nodes pick them from a list instead of you typing credentials into each one, and their secret fields are encrypted and masked like secrets.

Click **Add authentication**, choose a **Type**, and give it a **Key** and an optional **Description**.

| Type | Used by | Fields |
|---|---|---|
| **HTTP Basic** | HTTP call | **Username** and **Password** |
| **API token** | HTTP call | **Header name** (defaults to `Authorization`), **Value prefix** (defaults to `Bearer`; leave it empty to send the token alone) and **Token** |
| **AI provider** | LLM call | **Provider**, **API key** (not needed for a local Ollama server) and **Base URL** (optional, but needed for an OpenAI-compatible endpoint) |
| **SMTP server** | Send email | **Host**, **Port**, **Encryption**, **Username**, **Password**, **From address** and **From name**. Lets a workspace send email through its own server instead of the organization's. |
| **Chat webhook** | Chat message | **Service** (Slack or Mattermost, Microsoft Teams, Discord, or Google Chat) and **Webhook URL**. See [Chat webhooks](chat-webhooks.md) for getting one from each app. |
| **GitHub** | GitHub nodes, HTTP call | None: connected from a GitHub node (see below) |
| **Google** | Google Sheets nodes, HTTP call | None: connected from a Google node (see below) |
| **OAuth 2.0** | HTTP call | **Authorization URL**, **Token URL**, **Client ID**, **Client secret** and **Scopes**, from the OAuth app you registered with the provider |

### Connecting an account

GitHub, Google and OAuth 2.0 settings connect to an account instead of holding a password.

**GitHub and Google** are connected from the nodes that use them, not from **Add authentication**:

1. In the editor, select a GitHub or Google node.
2. Under its account field, choose **Connect GitHub** (or **Connect Google**). If the workspace already has an account connected, the button says **Connect another GitHub account**.
3. Approve the connection at the provider.

You're sent back to the same node, with the new account filled in. wag.run names the setting after the account, such as `github_octocat`, and lists it on **Authentication**, where you can **Reconnect** it (to connect a different account, or after access was revoked at the provider) or delete it. Only workspace admins see the button; others see a note asking them to ask an admin. If the install's OAuth app isn't set up yet, the node says which settings to add instead.

**OAuth 2.0** settings are added under **Add authentication**, with the provider's app details. When you save one, wag.run sends you to the provider to approve the connection, then back to **Authentication**, where the setting shows the account it's connected as.

The connection acts as the account that approved it, so use an account with access to everything the workflows need, such as a bot account. wag.run refreshes the access token on its own when the provider gives it a refresh token, as Google does. An HTTP call that uses one of these settings sends its access token as a bearer token.

GitHub and Google are available once an administrator has set up the install's OAuth apps (see [Connecting GitHub and Google](self-hosting.md#connecting-github-and-google)). For an OAuth 2.0 setting, register an app with the provider using the **Callback URL** shown in the form. Its token URL follows the same [internal-address rules](#workspace-settings) as an HTTP call.

Admins manage authentication settings. Others in the workspace see only their names and types. Admins can reveal a secret field, and each reveal is recorded in the audit log.

If you delete an authentication setting, workflows that use it fail until it's replaced.

## Workspace settings

Admins can change these under **Settings**:

- **Execution log retention**: how long this workspace's run logs are kept. Turn on **Use a workspace-specific retention period** to set your own number of days. Otherwise, the organization default applies. A workflow can override this too. See [How long runs are kept](runs.md#how-long-runs-are-kept).
- **Internal-address allowlist**: by default, HTTP call nodes can't reach private, loopback or link-local addresses, so a workflow can't be used to probe your internal network. List the internal hosts this workspace's workflows may call, one per line: a hostname, a wildcard such as `*.corp.internal`, an IP address, or a CIDR range such as `10.0.0.0/8`.

## Audit log

**Audit log** lists changes to this workspace: workflows created, imported, published, rolled back or deleted; variables, secrets and authentication settings created, changed, deleted or revealed; members added, removed or given a new role; and invitations. Each entry shows who did it and when.

Admins can read it. The dropdown at the top switches between the workspaces whose log you can read, and the action filter narrows entries by type. Organization admins also see **Organization**: every entry in the organization, including those that belong to no workspace, such as sign-ins (with how each person signed in), users promoted or deactivated, single sign-on, organization settings, and deleted workspaces.
