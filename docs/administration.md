# Organization administration

Organization admins look after the whole wag.run install: who has an account, which workspaces exist, how people sign in, and how email is sent. They also act as **Admin** in every workspace.

The first account registered on a new install becomes an organization admin. Admins can make others admins too. Their pages are under **Org settings** in the account menu at the bottom of the sidebar, which only admins see. Each page is a tab: **Workspaces**, **Users**, **Sign-in**, **Email**, **Masking**, and **General**. The **Audit log** link beside the tabs opens the organization's audit log.

## Workspaces

**Org settings → Workspaces** lists every workspace with how many members, data store entries, secrets, and credentials it has.

- **New workspace** creates one. Add people to it from its **Members** page. See [Members and roles](workspaces.md#members-and-roles).
- **Rename** changes its name.
- **Delete** removes it with all its workflows, run logs, data store, secrets, and credentials. This can't be undone.

## Users

**Org settings → Users** lists everyone with an account. Members get their access from their workspace roles. Organization admins have access to everything.

- **Invite by email**: send someone an invitation to create an account, and choose their **Organization role**. Invitations expire after 7 days and are listed under **Pending invitations** until then, where you can **Revoke** them. To invite someone straight into a workspace, invite them from that workspace's **Members** page instead.
- **Promote to organization admin** or **Demote to member**: change someone's organization role. The last organization admin can't be demoted.
- **Deactivate**: sign someone out and stop them signing in, without deleting their account or history. **Reactivate** lets them back in. You can't deactivate yourself or the last organization admin.
- **Generate password-reset link**: create a link that lets someone set a new password, for when email isn't set up or didn't arrive. Give it to them yourself. It works once and expires after 60 minutes.

A deactivated person can't be chosen as an approver, assignee, or notification recipient, and workflows they own no longer notify them of failed runs.

## Sign-in

**Org settings → Sign-in** controls who can create an account and how people sign in.

### Registration

Turn on **Allow anyone to register** to let anyone who can reach the sign-in page create an account. When it's off, people join only by invitation. New accounts join as organization Members with no workspace access either way, until someone adds them to a workspace. People who register themselves have to confirm their email address before they can use the app, so set up [email](#email) first. Invited people and people who sign in through a provider are confirmed already. The switch saves as soon as you flip it.

Turn it off once your first account exists, unless you want an open install.

### Sign-in providers

**Sign-in providers** let people sign in with an OpenID Connect provider, such as Okta, Microsoft Entra ID, Google Workspace, Keycloak, or Authentik. Email-and-password sign-in stays available alongside it.

To add one:

1. Click **Add provider**.
2. Copy the **Redirect URI** shown in the form and register it as a redirect or callback address in the provider's app settings. Note the client ID and secret the provider gives you.
3. Fill in the form:

   | Field | What to enter |
   |---|---|
   | **Name** | Shown on the sign-in button, as **Sign in with …** |
   | **Issuer URL** | The provider's base address, the one that serves `/.well-known/openid-configuration` |
   | **Client ID** and **Client secret** | From the provider. When editing, leave the secret empty to keep the current one. |
   | **Scopes** | Defaults to `openid email profile` |
   | **Show on the sign-in page** | Whether the button appears |
   | **Create accounts for new people** | When on, someone who signs in with the provider for the first time gets an account, as an organization Member with no workspace access. When off, only people who already have an account can sign in this way. |
   | **Trust email addresses from this provider** | Link sign-ins to existing accounts by email even when the provider doesn't mark the email as verified. Turn this on only for a provider your organization controls. |

4. Click **Save**, then **Check discovery** to confirm wag.run can reach the provider.

The list shows how many people have linked their account to each provider. Deleting a provider doesn't delete those accounts: people can still sign in with their password.

#### Redirect URI

The redirect URI is `APP_URL/auth/oidc/<name>/callback`, where `<name>` is the provider's name in lowercase with spaces turned into hyphens. A provider named **Authentik** on `https://wag.example.com` uses `https://wag.example.com/auth/oidc/authentik/callback`, so you can register it with the provider before adding it here. It's fixed when you first save the provider, so renaming the provider later doesn't change it.

The address must match `APP_URL` exactly, including `https`. If wag.run sits behind a proxy, see [HTTPS](self-hosting.md#https).

#### Authentik

1. In Authentik, go to **Applications → Applications** and create an application with a provider (**Create with provider**). Give it a name and slug, such as `wag-run`.
2. Choose **OAuth2/OpenID Provider**, with an authorization flow such as `default-provider-authorization-implicit-consent`.
3. Set **Client type** to **Confidential** and add the redirect URI under **Redirect URIs/Origins**, with **Strict** matching. Copy the **Client ID** and **Client Secret**.
4. Leave the default `openid`, `email`, and `profile` scope mappings selected, and finish the wizard.
5. Open the new provider and copy its **OpenID Configuration Issuer**, such as `https://authentik.example.com/application/o/wag-run/`. That's the **Issuer URL** in wag.run.

To limit who can sign in, bind users, groups, or policies to the application in Authentik.

#### Authelia

1. Generate a client secret and its digest:

   ```bash
   docker run --rm authelia/authelia:latest authelia crypto hash generate pbkdf2 --variant sha512 --random --random.length 72 --random.charset rfc3986
   ```

   Keep the **Random Password** for wag.run and put the **Digest** in Authelia's configuration.

2. Add a client to Authelia's `configuration.yml`, then restart Authelia:

   ```yaml
   identity_providers:
     oidc:
       clients:
         - client_id: 'wag-run'
           client_name: 'wag.run'
           client_secret: '$pbkdf2-sha512$...' # the digest from step 1
           public: false
           authorization_policy: 'two_factor'
           require_pkce: true
           pkce_challenge_method: 'S256'
           redirect_uris:
             - 'https://wag.example.com/auth/oidc/authelia/callback'
           scopes:
             - 'openid'
             - 'email'
             - 'profile'
           response_types:
             - 'code'
           grant_types:
             - 'authorization_code'
           token_endpoint_auth_method: 'client_secret_basic'
   ```

3. In wag.run, use Authelia's own address, such as `https://auth.example.com`, as the **Issuer URL**, `wag-run` as the **Client ID**, and the random password from step 1 as the **Client secret**.

`authorization_policy` decides whether people need one factor or two in Authelia before they reach wag.run.

#### Keycloak

1. In the Keycloak admin console, pick the realm your users are in and go to **Clients → Create client**.
2. Leave **Client type** as **OpenID Connect** and enter a **Client ID**, such as `wag-run`.
3. Turn **Client authentication** on and, under **Authentication flow**, keep only **Standard flow**.
4. Add the redirect URI under **Valid redirect URIs** and save.
5. On the client's **Credentials** tab, copy the **Client Secret**.
6. In wag.run, use `https://keycloak.example.com/realms/<realm>` as the **Issuer URL**. Keycloak versions before 17 add `/auth` before `/realms`.

Keycloak marks each user's email as verified or not. Until a user's **Email verified** is on, their first sign-in can't link to an existing wag.run account with the same email, unless you turn on **Trust email addresses from this provider**.

#### Microsoft Entra ID

1. In the Microsoft Entra admin center, go to **App registrations → New registration**.
2. Enter a name, choose **Accounts in this organizational directory only**, and add the redirect URI with the **Web** platform. A provider named **Entra ID** uses `https://wag.example.com/auth/oidc/entra-id/callback`. Click **Register**.
3. From the app's **Overview**, copy the **Application (client) ID** and the **Directory (tenant) ID**.
4. Under **Certificates & secrets → Client secrets**, add a secret and copy its **Value**, not its Secret ID. Secrets expire, so note the date and replace it in wag.run before then.
5. In wag.run, use `https://login.microsoftonline.com/<tenant-id>/v2.0` as the **Issuer URL**, with the directory (tenant) ID. Don't use `common` or `organizations` in place of the tenant ID: their issuer doesn't match a single tenant, so **Check discovery** fails.

A few things to know:

- Entra ID doesn't tell wag.run whether an email address is verified. To link sign-ins to existing wag.run accounts, turn on **Trust email addresses from this provider**. Do this only for a single-tenant app in a directory you control.
- The email comes from each user's **Email** property. Users without a mailbox may not have one, and can't sign in until it's set.
- To limit who can sign in, open the app under **Enterprise applications**, turn on **Assignment required** in its **Properties**, and assign users or groups under **Users and groups**.
- People may be asked to consent the first time they sign in. To skip that, click **Grant admin consent** under the app's **API permissions**.

#### Google Workspace

1. In the [Google Cloud console](https://console.cloud.google.com/), create or pick a project in your Google Workspace organization.
2. Set up the OAuth consent screen (**Google Auth Platform**) and choose **Internal** as the audience, so that only accounts in your Workspace can sign in.
3. Under **APIs & Services → Credentials**, choose **Create credentials → OAuth client ID**, of type **Web application**. Add the redirect URI as an **Authorized redirect URI**. A provider named **Google** uses `https://wag.example.com/auth/oidc/google/callback`. Copy the client ID and secret.
4. In wag.run, use `https://accounts.google.com` as the **Issuer URL**.

wag.run doesn't check which Workspace domain an account belongs to, so the **Internal** audience is what keeps other Google accounts out. With **External**, anyone with a Google account could sign in, and get an account if **Create accounts for new people** is on.

This is a separate OAuth client from the one in [Connecting GitHub and Google](self-hosting.md#google), which lets workflows use Google APIs.

#### Other providers

Any provider that supports OpenID Connect discovery and the authorization code flow should work. Set it up as a confidential client (sometimes called a web application) that:

- sends its client secret with HTTP Basic authentication (`client_secret_basic`), which is usually the default;
- accepts PKCE with `S256`, which wag.run always sends; and
- shares the person's `email`, which wag.run reads from the ID token or the userinfo endpoint.

The issuer must use `https`. Plain `http` works only for `localhost`.

#### Troubleshooting

| Message | What to check |
|---|---|
| **Could not reach …** or **… did not return a valid OpenID Connect discovery document** | The **Issuer URL** is wrong, or the wag.run server can't reach it. Open `<issuer>/.well-known/openid-configuration` from the server to check. |
| **The issuer in …'s discovery document does not match the configured issuer** | Copy the `issuer` value from that discovery document exactly. A trailing slash doesn't matter. |
| An error about the redirect URI on the provider's page | The redirect URI registered with the provider doesn't match the one shown in wag.run, often because of `http` and `https`, or a different `APP_URL`. |
| **… did not accept the sign-in** | The client secret is wrong, or the provider expects a different client authentication method. |
| **… has not verified this email address** | The provider doesn't mark the email as verified. Verify it in the provider, or turn on **Trust email addresses from this provider** if you control who can set email addresses there. |
| **There is no account for this sign-in** | **Create accounts for new people** is off. Invite the person first, or turn it on. |

## Email

**Org settings → Email** sets the SMTP server wag.run sends through: for invitations, approval and task emails, notification emails, and **Send email** nodes. Workspaces can add their own SMTP server for **Send email** nodes under [Credentials](workspaces.md#credentials).

1. Turn on **Send through my SMTP server**.
2. Enter the **Host**, **Port**, **Encryption** (**STARTTLS**, usually port 587, or **SSL/TLS**, usually port 465), **Username**, **Password**, **From address**, and **From name**.
3. Click **Save settings**, then **Send test email**. The test goes to your own address, using the saved settings.

When **Send through my SMTP server** is off, email uses the server's own mail configuration. On a fresh install, that writes email to the log instead of sending it. For a Docker install, you can also set email in `.env`; see [Self-hosting](self-hosting.md#settings).

## Masking

**Org settings → Masking** lists what runs mask in their logs, the API, and MCP, in every workspace. See [Secrets in logs](data-and-placeholders.md#secrets-in-logs) for how masking works.

- **Built-in rules**, collapsed until you open them, are on for every workspace and can't be removed. They come in two kinds:
    - **Names**, such as **Authorization**, `token`, and `*_secret`. The value of any key with one of these names is masked wherever it appears: in headers, bodies, queries, or forms. A name can be a false alarm, such as `next_page_token`, so a node can let one value through (below).
    - **Values that look like secrets**, such as a JWT, `Bearer …`, or a `ghp_` key. A value with that shape is masked whatever its key is called, and can't be let through: the value itself is the credential.
- **Your organization's names** are names to mask on top of the built-in ones, such as `X-Tenant-Key`, and work the same way. Type one and click **Add name**, or click × on one to remove it. Each change saves right away. A name uses letters, numbers, dashes, underscores, and dots. Matching ignores case, dashes, and underscores, and a `*` at the start or end stands for any prefix or suffix, as in `*_key`. Names only: patterns aren't supported.

Nothing here can be turned off for a whole workflow. To let one value that a name matched through, add that value's path to **Never mask** on the node's [**Masking**](editor.md#masking) tab. That works for built-in names and your organization's names alike.

## General settings

**Org settings → General** holds organization-wide settings. Workspaces and workflows can override the defaults.

| Setting | What it does |
|---|---|
| **Organization name** | Your organization's name |
| **Let people delete their own accounts** | When on, people can delete their own account from their profile settings. When off, the **Delete account** button is hidden and the action is refused. On by default. |
| **Default execution log retention** | How many days run logs are kept, unless a workspace or workflow sets its own. 30 days by default. Turn on **Keep execution logs forever** to never remove them. |
| **Maximum chain depth** | How many runs deep a chain of workflows starting other workflows can go. A run past it is cancelled, which stops workflows that start each other in a loop. 10 by default. |
| **Maximum runs per minute** | How many runs one workflow can start in a minute, unless the workflow sets its own limit. Runs past it are cancelled, which slows down loops through other apps, such as a workflow that fires its own trigger. 60 by default. |

## Audit log

**Workspace → Audit log** is also where organization admins read the whole organization's log. Open it from the **Audit log** link in **Org settings**, or pick **Org-wide** in the dropdown at the top of a workspace's audit log, to see every audited change across the organization, newest first: workflows, data store entries, secrets, and credentials in every workspace, secret reveals, workspace and membership changes, invitations, sign-ins, user changes, sign-in provider changes, and organization and email settings. Filter it by action. See [Audit log](workspaces.md#audit-log).
