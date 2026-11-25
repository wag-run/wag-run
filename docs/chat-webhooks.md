# Chat webhooks

The [Chat message](actions.md#chat-message) action posts to a channel in Slack, Mattermost, Microsoft Teams, Discord, or Google Chat through an **incoming webhook**: a secret address the chat app gives you that posts to one channel. This page shows how to get one from each app and save it in wag.run.

## How it works

1. In the chat app, add an incoming webhook to the channel and copy its URL.
2. In wag.run, open the workspace's **Credentials** page, choose **Add credential**, and pick the **Chat webhook** type.
3. Give it a **Description**, such as `Slack #releases`, choose the **Service**, and paste the URL into **Webhook URL**.
4. In a workflow, add a **Chat message** node, pick the setting under **Chat webhook**, and write the **Message**. Placeholders work, such as `Released {{ trigger.tag }}`.

Each webhook posts to one channel. To post to several channels, add a webhook and a setting for each.

Anyone who has a webhook URL can post to its channel, so wag.run keeps it encrypted like a secret, and only workspace admins can see it. If a URL leaks, delete the webhook in the chat app, create a new one, and paste it into the setting.

| Service | Choose this **Service** |
|---|---|
| Slack | **Slack (or Mattermost)** |
| Mattermost | **Slack (or Mattermost)** |
| Microsoft Teams | **Microsoft Teams (Workflows webhook)** |
| Discord | **Discord** |
| Google Chat | **Google Chat** |

The service decides the shape of the request. With the wrong service, the chat app usually refuses it and the step fails with its answer, though a Teams workflow may accept the request and then post nothing.

## Slack

Incoming webhooks belong to a Slack app. You need permission to install apps in the workspace, or a Slack admin's approval.

1. Go to [api.slack.com/apps](https://api.slack.com/apps) and choose **Create New App → From scratch**. Name it something people will recognize as the sender, such as `wag.run`, and pick the workspace.
2. Under **Features → Incoming Webhooks**, turn on **Activate Incoming Webhooks**.
3. Choose **Add New Webhook to Workspace** (or **Add New Webhook**), pick the channel, and choose **Allow**.
4. Copy the URL under **Webhook URLs for Your Workspace**. It starts with `https://hooks.slack.com/services/`.

The same app can have one webhook per channel. To post to a private channel, add the app to the channel first.

Messages use Slack's formatting: `*bold*`, `_italic_`, `` `code` ``, and links written as `<https://example.com|the text>`.

## Mattermost

Mattermost accepts the same request as Slack.

1. In Mattermost, open the product menu and choose **Integrations → Incoming Webhooks → Add Incoming Webhook**.
2. Give it a title, pick the channel, and save.
3. Copy the URL. It looks like `https://mattermost.example.com/hooks/…`.

If **Integrations** is missing, a system admin needs to turn on incoming webhooks under **System Console → Integrations → Integration Management**.

Messages use Markdown: `**bold**`, `_italic_`, and `[the text](https://example.com)`.

If your Mattermost server is on a private network, a workspace admin must add its address to the workspace's [internal-address allowlist](workspaces.md#workspace-settings). Otherwise the step fails because the address is internal.

## Microsoft Teams

Teams' original incoming webhooks (Office 365 connectors) are being retired. wag.run posts to a webhook made with Teams' **Workflows** app instead, and sends the message as an Adaptive Card, which is what that webhook expects.

1. In Teams, open the channel, choose **⋯ → Workflows**, and pick the template **Send webhook alerts to a channel** (also called **Post to a channel when a webhook request is received**).
2. Name the workflow, sign in if asked, and confirm the team and channel.
3. Choose **Add workflow**, then copy the URL Teams shows. You can find it again later in the Workflows app, on the workflow's **When a Teams webhook request is received** step, as the **HTTP POST URL**.

Keep the template's steps as they are: it loops over the cards in the request and posts each one, which is how wag.run's message arrives. If you build the workflow yourself instead, set **Who can trigger the flow** on the webhook step to **Anyone**. wag.run doesn't sign in to Microsoft.

The workflow posts as **Workflows** on behalf of the person who created it, and runs under their Microsoft 365 account. If that person leaves, the workflow stops, so consider creating it with a shared account. Workflows can't post to private channels as the flow bot.

Messages support a little Markdown: `**bold**`, `_italic_`, lists, and `[the text](https://example.com)`.

## Discord

You need the **Manage Webhooks** permission on the server.

1. Open **Server Settings → Integrations → Webhooks** and choose **New Webhook**.
2. Name it, such as `wag.run`, pick the channel, and optionally set an avatar.
3. Choose **Copy Webhook URL**. It starts with `https://discord.com/api/webhooks/`.

Discord limits a message to 2,000 characters, so a longer one fails. Messages use Markdown: `**bold**`, `*italic*`, `` `code` ``, and `[the text](https://example.com)`.

## Google Chat

Webhooks need a Google Workspace account. They can't be added from the Chat mobile app.

1. In Google Chat on the web, open the space, click the arrow next to its name, and choose **Apps & integrations**.
2. Choose **Add webhooks**, give it a name and an optional avatar URL, and choose **Save**.
3. Copy the URL. It starts with `https://chat.googleapis.com/v1/spaces/`.

If **Add webhooks** is missing, your Google Workspace administrator has turned off incoming webhooks for your organization.

Messages use Google Chat's formatting: `*bold*`, `_italic_`, `~strikethrough~`, `` `code` ``, and links written as `<https://example.com|the text>`.

## Testing it

Add a **Chat message** node after a **Manual** trigger and use **Test draft**. The step's output shows `status`, the HTTP status the chat app answered with.

If the step fails, its error includes the chat app's answer. Common causes:

| Answer | Likely cause |
|---|---|
| `404` or `410` | The webhook was deleted, or the URL was copied incompletely |
| `400` | The **Service** doesn't match the app the URL belongs to, or the message is too long |
| `401` or `403` | Slack: the app was removed from the workspace or channel. Teams: the workflow isn't set so that **Anyone** can trigger it |
| `429` | Too many messages. The step is retried if the node has [retries](runs.md#retries-and-error-paths). |
| Internal address | The chat server is on a private network and isn't on the workspace's internal-address allowlist |
