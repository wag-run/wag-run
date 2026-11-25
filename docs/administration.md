# Organization administration

Organization admins look after the whole wag.run install: who has an account, which workspaces exist, how people sign in, and how email is sent. They also act as **Admin** in every workspace.

The first account registered on a new install becomes an organization admin. Admins can make others admins too. These pages are in the **Organization** section of the sidebar, which only admins see.

## Workspaces

**Organization → Workspaces** lists every workspace with how many members, variables, secrets, and credentials it has.

- **New workspace** creates one. Add people to it from its **Members** page. See [Members and roles](workspaces.md#members-and-roles).
- **Rename** changes its name.
- **Delete** removes it with all its workflows, run logs, variables, secrets, and credentials. This can't be undone.

## Users

**Organization → Users** lists everyone with an account. Members get their access from their workspace roles. Organization admins have access to everything.

- **Invite by email**: send someone an invitation to create an account, and choose their **Organization role**. Invitations expire after 7 days and are listed under **Pending invitations** until then, where you can **Revoke** them. To invite someone straight into a workspace, invite them from that workspace's **Members** page instead.
- **Promote to organization admin** or **Demote to member**: change someone's organization role. The last organization admin can't be demoted.
- **Deactivate**: sign someone out and stop them signing in, without deleting their account or history. **Reactivate** lets them back in. You can't deactivate yourself or the last organization admin.
- **Generate password-reset link**: create a link that lets someone set a new password, for when email isn't set up or didn't arrive. Give it to them yourself. It works once and expires after 60 minutes.

A deactivated person can't be chosen as an approver, assignee, or notification recipient, and workflows they own no longer notify them of failed runs.

## Organization settings

**Organization → Settings** holds organization-wide settings. Workspaces and workflows can override the defaults.

| Setting | What it does |
|---|---|
| **Organization name** | Your organization's name |
| **Allow anyone to register** | When on, anyone who can reach the sign-in page can create an account. When off, people join only by invitation. New accounts join as organization Members with no workspace access either way, until someone adds them to a workspace. People who register themselves have to confirm their email address before they can use the app, so set up [email](#email) first. Invited people and people who sign in through a provider are confirmed already. |
| **Default execution log retention** | How many days run logs are kept, unless a workspace or workflow sets its own. 30 days by default. Turn on **Keep execution logs forever** to never remove them. |
| **Maximum chain depth** | How many runs deep a chain of workflows starting other workflows can go. A run past it is cancelled, which stops workflows that start each other in a loop. 10 by default. |
| **Maximum runs per minute** | How many runs one workflow can start in a minute, unless the workflow sets its own limit. Runs past it are cancelled, which slows down loops through other apps, such as a workflow that fires its own trigger. 60 by default. |

Turn off **Allow anyone to register** once your first account exists, unless you want an open install.

## Sign-in providers

**Organization → Sign-in providers** lets people sign in with an OpenID Connect provider, such as Okta, Microsoft Entra ID, Google Workspace, Keycloak, or Authentik. Email-and-password sign-in stays available alongside it.

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

## Email

**Organization → Email** sets the SMTP server wag.run sends through: for invitations, approval and task emails, notification emails, and **Send email** nodes. Workspaces can add their own SMTP server for **Send email** nodes under [Credentials](workspaces.md#credentials).

1. Turn on **Send through my SMTP server**.
2. Enter the **Host**, **Port**, **Encryption** (**STARTTLS**, usually port 587, or **SSL/TLS**, usually port 465), **Username**, **Password**, **From address**, and **From name**.
3. Click **Save settings**, then **Send test email**. The test goes to your own address, using the saved settings.

When **Send through my SMTP server** is off, email uses the server's own mail configuration. On a fresh install, that writes email to the log instead of sending it. For a Docker install, you can also set email in `.env`; see [Self-hosting](self-hosting.md#settings).

## Audit log

**Workspace → Audit log** is also where organization admins read the whole organization's log. Pick **Organization** in the dropdown at the top to see every audited change across the organization, newest first: workflows, variables, secrets and credentials in every workspace, secret reveals, workspace and membership changes, invitations, sign-ins, user changes, sign-in provider changes, and organization and email settings. Filter it by action. See [Audit log](workspaces.md#audit-log).
