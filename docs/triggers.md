# Triggers

A trigger is the node a workflow starts from. It decides how runs begin and what data they start with. That data is available to every later node as `trigger`.

Triggers only fire for the **published** version. Webhook and form addresses, and schedules, are set up when you publish and updated each time you publish again. To try a trigger's data before publishing, use [Test draft](runs.md#test-runs) and give it sample data.

A workflow can have more than one trigger, for example a **Webhook** for another system and a **Manual** trigger for people. Each run starts from the trigger that fired. A run started from another workflow starts from the first trigger.

## Manual

Someone starts the run from inside wag.run: with **Run** in the editor, or **Run manually** on the **Workflows** list. Starting a run needs the **Editor** or **Admin** role.

**Inputs to ask for** lists the fields the person fills in before the run starts. For each input, set:

| Setting | What it does |
|---|---|
| **Key** | The name later nodes use, as in `trigger.values.amount`. Letters, numbers, and underscores. |
| **Label** | What the person sees |
| **Type** | **Text**, **Long text**, **Number**, **Email**, **Date**, **Checkbox**, or **Choice** |
| **Required** | Whether it must be filled in |
| **Choices** | For a **Choice** input, the options, one per line as `value=Label` |

The values are under `trigger.values`, as a form's answers are: an input with the key `amount` is `{{ trigger.values.amount }}`. Numbers arrive as numbers and checkboxes as true or false.

A manual trigger with no inputs starts the run straight away.

When another workflow starts this one with [Trigger other workflow](actions.md#trigger-other-workflow) and this is the workflow's first trigger, it must give every **Required** input under its key. A run missing one fails before its first step, with a message naming the missing inputs, and the step that started it fails too.

## Webhook

Another system starts the run by sending an HTTP request to the workflow's own address. After you publish, find it under **Trigger URLs** in the [workflow settings](editor.md#workflow-settings). It looks like:

```text
https://wag.example.com/hooks/9f2c…
```

### Settings

| Setting | What it does |
|---|---|
| **Method** | The HTTP method to accept: **POST** (the default), **GET**, **PUT**, or **Any method** |
| **Verify requests by** | How to check that a request really comes from the sender you expect. See below. |
| **Token or signing secret** | For token and signature checks: a [secret](workspaces.md#secrets) from this workspace |
| **Header** | For token and signature checks: which header holds the token or signature. Defaults to `X-Webhook-Token` or `X-Signature`. |
| **Allowed IPs** | For an IP allowlist: one IP address or CIDR range per line, such as `203.0.113.7` or `10.0.0.0/8` |

### Verifying requests

- **Nothing (the URL is the secret)**: any request to the address starts a run. The address is long and random, but anyone who learns it can use it.
- **Shared token**: the sender includes the secret's value in the header, or as a `token` query parameter.

  ```bash
  curl -X POST https://wag.example.com/hooks/9f2c… \
    -H "X-Webhook-Token: $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"order_id": 1042, "status": "shipped"}'
  ```

- **HMAC-SHA256 signature**: the sender signs the raw request body with the secret and sends the signature, in hex, in the header. A `sha256=` prefix is allowed, as GitHub and others send it.
- **IP allowlist**: only requests from the listed addresses are accepted.

### What the sender gets back

| Response | When |
|---|---|
| `202` with `{"execution_id": "…", "status": "running"}` | The run was accepted. The status is `skipped` instead if the workflow runs one at a time and another run was going. |
| `403` | The request failed verification |
| `404` | The address doesn't exist, or the workflow has no published version |
| `405` | The request used a method the trigger doesn't accept |
| `429` | Too many requests: each caller can send up to 120 webhook requests a minute, and each workflow can start only so many runs a minute (see [Runs per minute](runs.md#runs-per-minute)). A run refused for the workflow's limit is still recorded, as **Cancelled**. |

The webhook answers as soon as the run starts. It doesn't wait for the run to finish or return its output.

### What the run receives

| Placeholder | What it holds |
|---|---|
| `trigger.body` | The request body: decoded JSON, form fields, or else the raw text |
| `trigger.query` | Query string parameters |
| `trigger.headers` | Request headers, by lowercase name. Cookies, the `Authorization` header, and the token or signature headers are left out. |
| `trigger.method` | The HTTP method |
| `trigger.ip` | The caller's IP address |

For the example request above, `{{ trigger.body.order_id }}` is `1042`.

## Schedule

The run starts on a timetable.

| Setting | What it does |
|---|---|
| **Run** | **Every N minutes**, or **On a cron schedule** |
| **Every (minutes)** | For an interval: how many minutes between runs. Defaults to 60. |
| **Cron expression** | For a cron schedule: when to run, such as `0 9 * * 1-5` for 9:00 on weekdays. **Presets** fills in a common schedule. |
| **Time zone** | For a cron schedule: the time zone the expression is read in, chosen from a list grouped by region, such as **Europe → London**. Defaults to `UTC`. |

A cron expression has five parts: minute, hour, day of the month, month, and day of the week. Some examples:

| Expression | Runs |
|---|---|
| `*/15 * * * *` | Every 15 minutes |
| `0 * * * *` | At the start of every hour |
| `30 8 * * *` | At 8:30 every day |
| `0 9 * * 1` | At 9:00 every Monday |
| `0 0 1 * *` | At midnight on the first of each month |

Under the expression, the editor puts the schedule into words, such as **At 09:00 on weekdays**, and lists its next three runs in the chosen time zone. A schedule with both a day of the month and a day of the week runs on either, as cron does: `30 6 5 * 4` reads **At 06:30 on day 5 of the month or on Thursday**. A schedule it can't put into words, such as one with steps in the day fields (`*/2`), still shows its next runs. An expression that isn't valid, or a time zone the server doesn't know, is flagged there and stops the workflow from being published.

`{{ trigger.scheduled_at }}` holds the time the run was due.

If a run is still going when the next one is due, the workflow's **Concurrent runs** setting decides what happens. See [workflow settings](editor.md#workflow-settings).

## Form

People start the run by filling in a web form. After you publish, find the form's address under **Trigger URLs** in the [workflow settings](editor.md#workflow-settings) and share it.

| Setting | What it does |
|---|---|
| **Form title** and **Description** | Shown at the top of the form |
| **Fields** | The questions, set up the same way as a manual trigger's [inputs](#manual) |
| **Who can submit** | **Anyone with the link**, or **Signed-in members of this workspace**. People who aren't signed in are sent to the sign-in page first. |
| **Message after submitting** | What people see once they've sent the form |

What the run receives:

| Placeholder | What it holds |
|---|---|
| `trigger.values.<key>` | Each field's answer, such as `{{ trigger.values.email }}` |
| `trigger.submitted_at` | When the form was sent |
| `trigger.submitted_by` | The user ID of the person who sent it, for a members-only form. Empty for a public form. |

Form answers and a manual trigger's inputs are both under `trigger.values`. When another workflow starts this one with [Trigger other workflow](actions.md#trigger-other-workflow) and the form is the workflow's first trigger, it must give an answer for every **Required** field, the same as for a [manual trigger](#manual).

## New item in a feed or list

Fires for each new item in an RSS or Atom feed, or in a list a JSON API returns. Use it for blogs, changelogs, status pages, and any service that has no webhooks but can list recent items. It's under **Triggers › Integrations** in the palette.

| Setting | What it does |
|---|---|
| **URL** | The address of the feed or list. It can read workspace variables and secrets, as in `https://{{ vars.api_host }}/orders`. |
| **Format** | **RSS or Atom feed**, or **JSON** |
| **Authentication** | Optional: an **HTTP Basic** or **API token** setting from the workspace's [credentials](workspaces.md#credentials) |
| **Headers** | Optional. Values can read workspace variables and secrets, such as `{{ secrets.api_key }}` for a key the source wants in a header. |
| **List** | For JSON: where the items are, such as `data.items`. Leave it empty when the answer is the list itself. |
| **Item ID** | For JSON: the field that identifies an item, such as `id`. It can reach into the item, as in `ref.id`. |
| **Newest item is listed first** | For JSON: on by default. Turn it off if the list starts with the oldest item, so runs start oldest first. |
| **Check every (minutes)** | How often to look, 15 by default |

wag.run fetches the address every **Check every (minutes)** and starts one run for each item it hasn't seen, oldest first. The first check after publishing only notes what's already there, so publishing doesn't start runs for old items. A feed entry is identified by its `guid` or `id`, or its link when it has neither. Entries and JSON items with no ID are skipped. Under **Trigger URLs** in the [workflow settings](editor.md#workflow-settings), the trigger shows when it last checked, and what's wrong if it isn't working.

The address is checked like an [HTTP call's](actions.md#http-call): private addresses are refused unless the workspace allows them. The URL and headers are filled in before a run starts, so they can't read `trigger`, `nodes`, or other run data; publishing says so if they try. A redirect to another host, or from HTTPS to plain HTTP, is followed without the authentication and headers. Answers over 5 MB are refused.

What the run receives depends on the format. For a feed:

| Placeholder | What it holds |
|---|---|
| `trigger.id` | The entry's ID |
| `trigger.title` | Its title |
| `trigger.link` | Its address |
| `trigger.summary` | Its summary or description, as plain text |
| `trigger.author` | Its author |
| `trigger.published_at` | When it was published, such as `2026-11-25T08:30:00+00:00`, when the feed says |

For JSON, `trigger.id` is the item's ID and `trigger.item` is the whole item, so a field is `{{ trigger.item.total }}`.

Items are remembered by ID, so an item whose ID changes starts a run again. Put **Item ID** on a field that never changes, such as a database ID, not a timestamp or a position in the list.

## GitHub

Three triggers start a workflow on a GitHub repository's events: **New tag**, **New release**, and **Actions run failed**. They're under **Triggers › Integrations › GitHub** in the palette. Each needs a **GitHub connection** that can see the repository: a GitHub account, which you can connect right from the node with **Connect GitHub** (see [Connecting an account](workspaces.md#connecting-an-account)), or a personal access token or GitHub App (see [Other ways to connect GitHub](workspaces.md#other-ways-to-connect-github)), and the **Repository**, as `owner/name`.

### Polling or a webhook

**Find out about new events by** decides how wag.run hears about them:

- **Checking every few minutes** (the default): wag.run asks GitHub what's new every **Check every (minutes)**, 5 by default. It works even when GitHub can't reach your install. The first check after publishing only notes what already exists, so publishing doesn't start runs for old tags or releases.
- **A webhook on the repository**: when you publish, wag.run adds a webhook to the repository, and GitHub sends each event as it happens. GitHub must be able to reach your install's `APP_URL`, and the connected account needs admin access to the repository. Changing the trigger, unpublishing it, or deleting the workflow removes the webhook.

Under **Trigger URLs** in the [workflow settings](editor.md#workflow-settings), each GitHub trigger shows when it last checked or whether its webhook is registered, and what's wrong if it isn't working. Runs it starts show **Another app** as their trigger.

### New tag

Fires when a tag is pushed. Polling checks the 100 tags GitHub lists first.

| Placeholder | What it holds |
|---|---|
| `trigger.tag` | The tag's name, such as `v1.2.0` |
| `trigger.sha` | The commit it points to |
| `trigger.repository` | `owner/name` |
| `trigger.url` | The tag on GitHub |

### New release

Fires when a release is published. Drafts don't count until they're published. Turn off **Include pre-releases** to skip pre-releases.

| Placeholder | What it holds |
|---|---|
| `trigger.tag`, `trigger.name` | The release's tag and title |
| `trigger.body` | The release notes |
| `trigger.url` | The release on GitHub |
| `trigger.prerelease` | Whether it's a pre-release |
| `trigger.author` | Who published it |
| `trigger.published_at` | When it was published |
| `trigger.repository` | `owner/name` |

### Actions run failed

Fires when a GitHub Actions run fails. Set **Actions workflow** (its name, such as `CI`, or its file name, such as `ci.yml`) or **Branch** to only hear about some runs.

| Placeholder | What it holds |
|---|---|
| `trigger.id`, `trigger.run_number` | The run's ID and number |
| `trigger.workflow` | The Actions workflow's name |
| `trigger.branch`, `trigger.sha` | The branch and commit it ran on |
| `trigger.event` | What started the run, such as `push` |
| `trigger.actor` | Who started it |
| `trigger.url` | The run on GitHub |
| `trigger.repository` | `owner/name` |

A workflow that a GitHub trigger starts, and that changes the same repository, can start itself again, for example by creating a tag. Each workflow can start only so many [runs a minute](runs.md#runs-per-minute), but check that its actions can't fire its own trigger.

## Investbrain

### New transaction

Fires when a buy or a sale is added to a portfolio in [Investbrain](https://investbra.in). It's under **Triggers › Integrations › Investbrain** in the palette, and needs an **API token**: an **Investbrain API token** setting from the workspace's [credentials](workspaces.md#credentials).

Leave **Portfolio** and **Symbol** empty to hear about every transaction you can see, or set them to only hear about one portfolio (its ID or its address in Investbrain) or one ticker symbol. **Type** narrows it to **Buys** or **Sales**. Investbrain adds transactions of its own for stock splits and reinvested dividends; turn on **Include splits and reinvested dividends** to start runs for those too.

Investbrain doesn't send webhooks, so wag.run checks for new transactions every **Check every (minutes)**, 5 by default. Each check reads the 100 transactions added most recently. The first check after publishing only notes what already exists, so publishing doesn't start runs for old transactions. Importing transactions into Investbrain starts a run for each one, up to 100 a check. Under **Trigger URLs** in the [workflow settings](editor.md#workflow-settings), the trigger shows when it last checked, and what's wrong if it isn't working.

| Placeholder | What it holds |
|---|---|
| `trigger.id` | The transaction's ID |
| `trigger.portfolio_id` | The portfolio it's in |
| `trigger.symbol` | The ticker symbol, such as `AAPL` |
| `trigger.transaction_type` | `BUY` or `SELL` |
| `trigger.quantity` | How many shares |
| `trigger.cost_basis`, `trigger.sale_price` | What each share cost, and for a sale what each fetched |
| `trigger.currency` | The currency of those prices |
| `trigger.date` | The transaction's date, such as `2026-11-25` |
| `trigger.split`, `trigger.reinvested_dividend` | Whether Investbrain added it for a stock split or to reinvest a dividend |
| `trigger.created_at` | When it was added |

A workflow that this trigger starts, and that adds a transaction with [Add transaction](actions.md#add-transaction), can start itself again. Set **Portfolio**, **Symbol**, or **Type** so the transactions it adds don't match.
