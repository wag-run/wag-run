# Workspaces

A workspace holds a team's workflows and runs, the people who work on them, and the values and sign-in details those workflows share. Each workspace is separate: a workflow can only use the variables, secrets, and credentials of its own workspace, and can only start workflows in its own workspace.

Switch workspaces with the menu at the top of the sidebar. Organization admins create, rename, and delete workspaces. See [Organization administration](administration.md#workspaces).

## Overview

**Overview** is the workspace's home page. It shows its workflows, the approvals and tasks waiting on you in this workspace, and shortcuts to its variables, secrets, credentials, and members.

## Members and roles

**Members** lists who can use the workspace and their role. Organization admins aren't listed, because they act as Admin in every workspace.

| | Guest | Viewer | Editor | Admin |
|---|:-:|:-:|:-:|:-:|
| Answer approvals and tasks sent to them | ✓ | ✓ | ✓ | ✓ |
| See workflows, runs, and members | | ✓ | ✓ | ✓ |
| See the names of variables, secrets, and credentials | | ✓ | ✓ | ✓ |
| Build, publish, test, and run workflows, and cancel runs | | | ✓ | ✓ |
| Add, change, and delete variables | | | ✓ | ✓ |
| Manage members, secrets, and credentials | | | | ✓ |
| Reveal secret values | | | | ✓ |
| Change workspace settings and read the audit log | | | | ✓ |

**Guest** is the role for people outside the team who only need to answer requests, such as a manager who approves refunds.

### Adding people

Under **Add or invite by email**, enter an email address, choose a role, and click **Add**.

- If the person already has an account, they're added straight away.
- If not, they're emailed an invitation to create an account and join. Invitations expire after 7 days. Until then, they're listed under **Pending invitations**, where you can **Revoke** them.

To change someone's role, pick a new one next to their name. To take away their access, click **Remove**. Removing someone from a workspace doesn't delete their account.

## Variables

Variables are named values that any workflow in the workspace can use, such as a team's email address, a base URL, or a threshold. Read one in a placeholder as `{{ vars.<key> }}`:

```text
{{ vars.api_base_url }}/orders/{{ trigger.order_id }}
```

Click **Add variable** and give it a **Key** (letters, numbers, and underscores), a **Value**, and an optional **Description**. Editors and Admins can manage variables.

Changing a variable affects every run from then on, without republishing. If you delete a variable, workflows that use it get an empty value.

Variables are not secret: anyone who can see the workspace's runs can see their values in step inputs. Use a secret for anything sensitive.

## Secrets

Secrets are for sensitive values, such as tokens, passwords, and signing keys. They're encrypted where they're stored, and wherever a secret's value would show in a run's step input or output, it's masked.

Read one in a placeholder as `{{ secrets.<key> }}`, for example in an HTTP call header:

```text
Authorization: Bearer {{ secrets.billing_api_token }}
```

Webhook triggers also use secrets for their [shared token or signing secret](triggers.md#verifying-requests).

Admins add and edit secrets. When editing, leave the value empty to keep the current one. Viewers and Editors see only secret names, so they can tell which secrets a workflow uses. Admins can reveal a secret's value, and each reveal is recorded in the audit log.

If you delete a secret, workflows that use it fail until it's replaced.

## Credentials

Credentials are reusable sign-in details for other systems. Nodes pick them from a list instead of you typing credentials into each one, and their secret fields are encrypted and masked like secrets.

Click **Add credential**, choose a **Type**, and give it a **Description**, such as `Billing API token`. Nodes list settings by their description, so each one in a workspace must be different.

| Type | Used by | Fields |
|---|---|---|
| **HTTP Basic** | HTTP call | **Username** and **Password** |
| **API token** | HTTP call | **Header name** (defaults to `Authorization`), **Value prefix** (defaults to `Bearer`; leave it empty to send the token alone) and **Token** |
| **AI provider** | LLM call | **Provider**, **API key** (not needed for a local Ollama server), and **Base URL** (optional, but needed for an OpenAI-compatible endpoint) |
| **SMTP server** | Send email | **Host**, **Port**, **Encryption**, **Username**, **Password**, **From address**, and **From name**. Lets a workspace send email through its own server instead of the organization's. |
| **Chat webhook** | Chat message | **Service** (Slack or Mattermost, Microsoft Teams, Discord, or Google Chat) and **Webhook URL**. See [Chat webhooks](chat-webhooks.md) for getting one from each app. |
| **GitHub** | GitHub nodes, HTTP call | None when the install has a GitHub app: you connect a GitHub account (see below). Otherwise the **Client ID** and **Client secret** of your own GitHub OAuth app or GitHub App. |
| **GitHub personal access token** | GitHub nodes | **Token**: a fine-grained token with read and write access to Contents, Issues, Actions, and Webhooks, or a classic token with the `repo` and `admin:repo_hook` scopes |
| **GitHub App** | GitHub nodes | **App ID or client ID**, **Installation ID**, and **Private key** (see [Using a GitHub App](#using-a-github-app)) |
| **wag.run API token** | wag.run nodes | **Address** (leave empty for this install) and **Token**: a [personal access token](api.md#personal-access-tokens) with the `workflows:read` scope |
| **Google** | Google Sheets nodes, HTTP call | None: you connect a Google account (see below) |
| **OAuth 2.0** | HTTP call | **Authorization URL**, **Token URL**, **Client ID**, **Client secret**, and **Scopes**, from the OAuth app you registered with the provider |

The **Used by** column shows how many of the workspace's workflows use each credential, in their draft or their published version. Hover over the count to see which workflows, and click one to open it. A credential no workflow uses is marked **Unused**, which helps before deleting it.

### Connecting an account

GitHub, Google, and OAuth 2.0 settings connect to an account instead of holding a password.

**GitHub and Google** accounts are connected with a **Connect** button, from either place:

- **On this page:** choose **Add credential**, pick **GitHub** or **Google**, and choose **Connect GitHub** (or **Connect Google**). You come back here with the new account listed.
- **From a node:** in the editor, select a GitHub or Google node and choose **Connect GitHub** under its account field (**Connect another GitHub account** if one is already connected). You come back to the same node with the new account filled in.

Either way, you approve the connection at the provider, and wag.run describes the setting by the account, such as `GitHub (octocat)`. On this page you can then **Reconnect** it (to connect a different account, or after access was revoked at the provider), change its description, or delete it. Only workspace admins can connect accounts; on a node, others see a note asking them to ask an admin. If the install's OAuth app isn't set up yet, the form and the node say which settings to add instead of showing the button.

**OAuth 2.0** settings are added under **Add credential**, with the provider's app details. When you save one, wag.run sends you to the provider to approve the connection, then back to **Credentials**, where the setting shows the account it's connected as.

The connection acts as the account that approved it, so use an account with access to everything the workflows need, such as a bot account. wag.run refreshes the access token on its own when the provider gives it a refresh token, as Google does. An HTTP call that uses one of these settings sends its access token as a bearer token.

Google is available once an administrator has set up the install's OAuth app (see [Connecting GitHub and Google](self-hosting.md#connecting-github-and-google)). GitHub works the same way when the install has a GitHub app. Without one, a **GitHub** setting asks for the **Client ID** and **Client secret** of your own GitHub OAuth app, or of a GitHub App to connect as a person through it. Register the **Callback URL** shown in the form with that app, save, and approve the connection at GitHub. The setting's own client wins over the install's, even when the install has one. For an OAuth 2.0 setting, register an app with the provider using the **Callback URL** shown in the form. Its token URL follows the same [internal-address rules](#workspace-settings) as an HTTP call.

### Other ways to connect GitHub

Every GitHub node takes any of three kinds of GitHub setting, listed together under the node's **GitHub connection**:

- **GitHub**: an account connected through OAuth, as above. The nodes act as the person who connected.
- **GitHub personal access token**: the nodes act as the person who made the token, with the access it gives. Make one under your avatar → **Settings → Developer settings → Personal access tokens**. It stops working when it expires, so set a reminder to replace it.
- **GitHub App**: the nodes act as the app, not a person, so the setting keeps working when people leave. It reaches only the repositories you install it on, with the permissions you give it.

#### Using a GitHub App

1. Create the app under your organization's **Settings → Developer settings → GitHub Apps → New GitHub App** (or under your own account's developer settings). Turn off **Webhook → Active**: wag.run doesn't need the app's own webhook.
2. Under **Repository permissions**, give it what the workflows need: **Contents**, **Issues**, and **Pull requests** (read and write) for the actions, **Actions** (read and write) to run and watch Actions workflows, and **Webhooks** (read and write) for GitHub triggers that register a webhook.
3. Create the app. On its settings page, copy the **App ID** (or the **Client ID**, which works too), and under **Private keys** choose **Generate a private key**. Your browser downloads a `.pem` file.
4. Choose **Install App**, install it on your account or organization, and pick the repositories. The installation's settings page address ends in its **Installation ID**, such as `12345678` in `github.com/organizations/acme/settings/installations/12345678`.
5. In wag.run, choose **Add credential → GitHub App**, enter the App ID and installation ID, and paste the whole `.pem` file, including its `BEGIN` and `END` lines, into **Private key**.

wag.run uses the private key to ask GitHub for an installation token, which lasts an hour, and asks for a new one shortly before it expires.

Admins manage credentials. Others in the workspace see only their names and types. Admins can reveal a secret field, and each reveal is recorded in the audit log.

If you delete a credential, workflows that use it fail until it's replaced.

## Workspace settings

Admins can change these under **Settings**:

- **Execution log retention**: how long this workspace's run logs are kept. Turn on **Use a workspace-specific retention period** to set your own number of days. Otherwise, the organization default applies. A workflow can override this too. See [How long runs are kept](runs.md#how-long-runs-are-kept).
- **Internal-address allowlist**: by default, HTTP call nodes can't reach private, loopback, or link-local addresses, so a workflow can't be used to probe your internal network. List the internal hosts this workspace's workflows may call, one per line: a hostname, a wildcard such as `*.corp.internal`, an IP address, or a CIDR range such as `10.0.0.0/8`.

## Audit log

**Audit log** lists changes to this workspace: workflows created, imported, published, rolled back, or deleted; variables, secrets, and credentials created, changed, deleted, or revealed; members added, removed, or given a new role; and invitations. Each entry shows who did it and when.

Admins can read it. The dropdown at the top switches between the workspaces whose log you can read, and the action filter narrows entries by type. Organization admins also see **Organization**: every entry in the organization, including those that belong to no workspace, such as sign-ins (with how each person signed in), users promoted or deactivated, single sign-on, organization settings, and deleted workspaces.
