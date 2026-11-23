# Actions

Actions are the nodes that do the work after a trigger. They're grouped in the palette as **Flow**, **Data**, **Integrations**, **GitHub**, **Google**, **AI**, **Messaging** and **People**.

Each node's outputs are available to the nodes after it as `nodes.<id>.output`, where `<id>` is shown in the node's settings under **Reference its output as**. For example, the status code of an HTTP call with the id `fetch` is `{{ nodes.fetch.output.status }}`. See [Data and placeholders](data-and-placeholders.md).

Every action also has **Retries**, **Backoff (s)** and **Show "on error" path** in its settings. See [Retries and error paths](runs.md#retries-and-error-paths).

## Flow

### Conditional logic

Sends the run down **true** or **false**, depending on a condition.

| Setting | What it does |
|---|---|
| **Condition** | One `{{ }}` placeholder that is true or false, such as `{{ nodes.fetch.output.status == 200 and trigger.amount > 100 }}` |

Output: `result`, true or false.

Publishing is refused if the condition isn't a single placeholder. Plain text like `trigger.amount > 100`, without the braces, would otherwise always count as true.

### Switch

Sends the run down one of several outputs, by comparing a value with a list of cases.

| Setting | What it does |
|---|---|
| **Value** | What to compare, such as `{{ trigger.plan }}` |
| **Cases** | Up to 20 values to compare against. Each case gets its own output, **case 1**, **case 2** and so on. |
| **Case insensitive** | Treat `Pro` and `pro` as equal |

The first case equal to the value is taken. If none is, the run goes down **default**.

Outputs: `value` (what was compared), `matched` (the output taken, such as `case_2` or `default`) and `case` (the case it equalled, or empty for default).

### For loop

Runs part of the workflow once for each item in a list, one item at a time.

| Setting | What it does |
|---|---|
| **Items** | One placeholder for a list, such as `{{ nodes.fetch.output.body.items }}` |

Connect the steps to repeat to the **each item** output. When the last item is done, the run continues down **done**. A loop can run at most 10,000 items.

Inside the loop, nodes can read:

| Placeholder | What it holds |
|---|---|
| `item` | The current item |
| `loop.index` | Its position, starting at 0 |
| `loop.count` | How many items there are |

Output, on **done**: `results`, a list with what each iteration produced, keyed by the node that ended it.

On the run page, use the arrows on a loop's steps to step through the iterations.

### Merge

Waits for parallel branches to finish, then continues once.

It waits for every branch that can still reach it. A branch that was never taken, such as the unused side of a condition, isn't waited for.

Output: `branches`, the output of the last node on each branch, keyed by node id.

### Wait

Pauses the run.

| Setting | What it does |
|---|---|
| **Wait** | **For a duration**, or **Until a time** |
| **Amount** and **Unit** | For a duration: how long, in **Seconds**, **Minutes**, **Hours** or **Days**. Defaults to 5 minutes. |
| **Until** | For a time: a date and time, such as `2026-12-01 09:00` or `{{ trigger.send_at }}`. Times without a time zone are read as UTC. |

A time that has already passed doesn't wait. While it waits, the run is **Waiting**, and the run page shows when it resumes.

Output: `waited_until`.

### Trigger other workflow

Starts another workflow in the same workspace.

| Setting | What it does |
|---|---|
| **Workflow** | The workflow to start. It must be published, and can't be this workflow. |
| **Mode** | **Wait for it to finish**, or **Start it and move on** |
| **Input** | Keys and values passed to the other workflow, which it reads as `trigger.<key>` |

Outputs:

- **Wait for it to finish**: `output`, the output of the last node on each path of the other workflow, keyed by node id. If the other run fails or is cancelled, this step fails.
- **Start it and move on**: `execution_id` and `status` of the run it started.

The other run shows **Another workflow** as its trigger and links back to this run. Chains of workflows starting workflows stop at the organization's **Maximum chain depth** (10 by default), so workflows that start each other can't loop forever. The same goes for an **HTTP call** to one of this install's own webhook URLs: the run it starts counts as one level deeper in the chain.

### Terminate

Ends the run here.

| Setting | What it does |
|---|---|
| **End as** | **Complete**, **Failed** or **Cancelled** |
| **Output** | Optional. Becomes the run's output. |

Other branches still going are stopped too.

## Data

### Compose

Builds a value from earlier outputs.

| Setting | What it does |
|---|---|
| **Value** | Text with placeholders, such as `Order {{ trigger.id }} shipped`, or a single placeholder, which keeps its type: `{{ item.price * item.quantity }}` is a number |
| **Parse the result as JSON** | Turn text that holds JSON into a value later nodes can read into |

Output: `value`.

Compose is useful for working something out once and using it in several places, and for building a JSON body for an HTTP call.

### Filter

Keeps the items of a list that match a condition.

| Setting | What it does |
|---|---|
| **List** | One placeholder for a list, such as `{{ nodes.fetch.output.body.items }}` |
| **Condition** | One placeholder checked for each item, such as `{{ item.amount > 100 }}`. `item` is the item and `index` its position. Inside a For loop, the loop's own item is `loop.item`. |

Outputs: `items` (the ones that match), `count` (how many match) and `removed` (the ones that don't).

## Integrations

### HTTP call

Makes an HTTP request.

| Setting | What it does |
|---|---|
| **Method** | `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` or `OPTIONS` |
| **URL** | The address, with placeholders if needed, such as `https://api.example.com/orders/{{ trigger.order_id }}` |
| **Authentication** | Optional. An **HTTP Basic** or **API token** setting from the workspace's [authentication settings](workspaces.md#authentication). |
| **Query parameters** and **Headers** | Keys and values to send |
| **Body** | **None**, **JSON**, **Form fields** or **Raw text** |
| **Timeout (s)** | How long to wait for an answer. Defaults to 30. |
| **Follow redirects** | Follow up to 5 redirects. On by default. |
| **Fail on an error status (4xx or 5xx)** | On by default. Turn it off to handle error responses yourself, for example with a condition on `status`. |
| **Allow insecure connections** | Off by default. Skips checking the server's TLS certificate, for an internal server with a self-signed certificate or one that doesn't match its address. See the warning below. |

Outputs: `status` (the status code), `ok` (true for a 2xx status), `headers`, and `body`, which is decoded JSON when the response is JSON, or else text. Bodies over 1 MB are cut short.

HTTP calls can't reach private, loopback or link-local addresses, such as `10.0.0.5` or `localhost`, unless a workspace admin lists them in the [internal-address allowlist](workspaces.md#workspace-settings).

> **Warning:** With **Allow insecure connections** on, the connection is still encrypted, but wag.run no longer checks who it's talking to. Anyone between wag.run and the server could read or change the request and the response, including any token or password sent with it. Use it only for internal servers you control. Where you can, give the server a valid certificate instead.

### Upload to S3

Writes a file to a bucket on Amazon S3 or an S3-compatible service, such as MinIO, Cloudflare R2, Backblaze B2 or Wasabi. A file already at the same key is replaced.

| Setting | What it does |
|---|---|
| **Storage** | An **S3-compatible storage** setting from the workspace's [authentication settings](workspaces.md#authentication). It holds the bucket, region, endpoint and access keys. |
| **Key** | The file's path in the bucket, such as `backups/config-{{ date(now(), 'Y-m-d') }}.xml` |
| **Content** | Text, such as `{{ nodes.fetch.output.body }}`, or a file from an earlier node, such as `{{ nodes.fetch.output.file }}` from an HTTP call that saves its response as a file. Files are uploaded byte for byte. A list or object is saved as JSON. |
| **Content type** | Optional, such as `application/xml`. Left empty, a file keeps its own type, and text gets one guessed from the key's extension. |
| **Storage class** | Optional. **Bucket default** unless you choose **Standard**, **Intelligent-Tiering**, **Standard-Infrequent Access**, **One Zone-Infrequent Access**, **Glacier Instant Retrieval**, **Glacier Flexible Retrieval** or **Glacier Deep Archive**. The colder classes cost less to keep but more, or longer, to read back. Other S3-compatible services may accept only some of these. |

Outputs: `bucket`, `key`, `url` (the file's address) and `size` (in bytes).

Like HTTP calls, an upload can't reach a storage endpoint on a private, loopback or link-local address, such as a MinIO server at `10.0.0.5`, unless a workspace admin lists it in the [internal-address allowlist](workspaces.md#workspace-settings).

If the storage service refuses the upload, the step fails with its status and message. Server errors, rate limits and connection problems are retried; other refusals, such as wrong keys or a missing bucket, are not.

## GitHub

These nodes work on a GitHub repository as a connected GitHub account. Each has a **GitHub account**, which you can connect right from the node with **Connect GitHub** (see [Connecting an account](workspaces.md#connecting-an-account)), and a **Repository**, as `owner/name` or the repository's URL. A placeholder works in **Repository**, such as `{{ trigger.repository }}` after a [GitHub trigger](triggers.md#github).

If GitHub refuses the request, the step fails with GitHub's message. Only server errors and rate limits are retried.

### Create issue

Opens an issue. **Title** is required; **Body**, **Labels** and **Assignees** (usernames) are optional, with labels and assignees separated by commas.

Outputs: `number`, `url` and `id`.

### Comment on issue or PR

Adds a **Comment** to the issue or pull request with the given **number**.

Outputs: `id` and `url`.

### Create release

Publishes a release for **Tag**. If the tag doesn't exist yet, GitHub creates it at **Target**, a branch or commit (the default branch if empty). **Name** defaults to the tag. Turn on **Generate release notes** to have GitHub add notes from the pull requests since the last release, or **Save as a draft** or **Pre-release** as needed.

Outputs: `id`, `tag` and `url`.

### Run Actions workflow

Starts a GitHub Actions workflow on **Branch or tag**. **Actions workflow** is its file name, such as `deploy.yml`, and it needs a `workflow_dispatch` trigger. **Inputs** are the inputs it declares. GitHub doesn't say which run it started.

Outputs: `workflow` and `ref`.

## Google

### Add row to sheet

Adds a row after the last one in a Google Sheets sheet, as a connected Google account. Connect one right from the node with **Connect Google** (see [Connecting an account](workspaces.md#connecting-an-account)).

| Setting | What it does |
|---|---|
| **Google account** | The connected Google account to use |
| **Spreadsheet** | The spreadsheet's URL or ID |
| **Sheet** | The sheet's name, `Sheet1` by default |
| **Values** | One value per column, starting from the first. Values are entered as if typed, so numbers and dates are recognised. |

Outputs: `range`, the cells it wrote, such as `'Orders'!A5:C5`, and `rows`.

## AI

### LLM call

Sends a prompt to a large language model and returns its answer.

| Setting | What it does |
|---|---|
| **AI provider key** | An **AI provider** setting from the workspace's [authentication settings](workspaces.md#authentication) |
| **Model** | The provider's model name, such as `claude-sonnet-5-5` |
| **Instructions** | Optional. A system prompt that sets how the model should behave. |
| **Prompt** | What to ask, with placeholders for data, such as `Summarize this ticket: {{ trigger.body.description }}` |
| **Response** | **Free text**, or **Structured data** |
| **Structured fields** | For structured data: the fields the model must return. For each, a **Key**, a **Type** (**Text**, **Number**, **Whole number**, **Yes/no** or **List of text**), a **Description** to guide the model, and whether it's **Required**. |
| **Timeout (s)** | Defaults to 120 |

Outputs:

- **Free text**: `text`, the model's answer.
- **Structured data**: `data`, with one value per structured field, such as `{{ nodes.classify.output.data.category }}`.
- Both: `usage.input_tokens` and `usage.output_tokens`.

Structured data is the way to use an LLM's answer in logic: ask for a `category` or an `urgent` yes/no, then branch on it with **Conditional logic** or **Switch**.

Supported providers are Anthropic, OpenAI, Google Gemini, Mistral, Groq, xAI, DeepSeek, OpenRouter, Cohere, Ollama, and any OpenAI-compatible endpoint.

## Messaging

### Send email

| Setting | What it does |
|---|---|
| **To**, **CC**, **BCC** | Addresses, separated by commas, such as `{{ trigger.email }}, ops@example.com` |
| **Reply to** | Optional |
| **Subject** | |
| **Format** | **Plain text** or **HTML** |
| **Body** | |
| **SMTP server** | Optional. An **SMTP server** setting from the workspace's [authentication settings](workspaces.md#authentication). Leave it empty to send through the organization's [email settings](administration.md#email). |

Outputs: `to` (the addresses it was sent to), `subject` and `message_id`.

### Send notification

Sends a message to people's [Inbox](people.md#the-inbox) in wag.run.

| Setting | What it does |
|---|---|
| **Recipients** | People in the organization |
| **Title** | |
| **Message** | |
| **Link** | Optional. Where opening the item goes. Defaults to this run. |
| **Also send by email** | Email it to them as well. Off by default. |

Outputs: `recipients` (the user IDs notified) and `count`.

### Chat message

Posts a message to a Slack, Microsoft Teams, Discord or Google Chat channel, through an incoming webhook.

| Setting | What it does |
|---|---|
| **Chat webhook** | A **Chat webhook** setting from the workspace's [authentication settings](workspaces.md#authentication) |
| **Message** | The text to post |

Outputs: `service` and `status` (the HTTP status the chat service answered with).

To get a webhook address, add an incoming webhook to the channel in the chat app, then save it in wag.run as a chat webhook. [Chat webhooks](chat-webhooks.md) has the steps for each app.

## People

### Approval

Pauses the run until enough of the chosen approvers approve, or it's rejected. It continues down **approved** or **rejected**.

### Task

Pauses the run until someone fills in a form, then continues with their answers.

Both are covered in [Approvals, tasks and the Inbox](people.md).
