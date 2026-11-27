# Actions

Actions are the nodes that do the work after a trigger. They're grouped in the palette as **Flow**, **Data**, **Integrations**, **Storage**, **GitHub**, **Google**, **Investbrain**, **wag.run**, **AI**, **Messaging**, and **People**.

Each node's outputs are available to the nodes after it as `nodes.<id>.output`, where `<id>` is shown in the node's settings under **Reference its output as**. For example, the status code of an HTTP call with the id `fetch` is `{{ nodes.fetch.output.status }}`. See [Data and placeholders](data-and-placeholders.md).

Every action also has **Retries**, **Backoff (s)**, and **Show "on error" path** in its settings. See [Retries and error paths](runs.md#retries-and-error-paths).

## Flow

### Conditional logic

Sends the run down **true** or **false**, depending on a condition.

| Setting | What it does |
|---|---|
| **Condition** | One `{{ }}` placeholder that is true or false, such as `{{ nodes.fetch.output.status == 200 and trigger.values.amount > 100 }}` |

Output: `result`, true or false.

Publishing is refused if the condition isn't a single placeholder. Plain text like `trigger.values.amount > 100`, without the braces, would otherwise always count as true.

### Switch

Sends the run down one of several outputs, by comparing a value with a list of cases.

| Setting | What it does |
|---|---|
| **Value** | What to compare, such as `{{ trigger.plan }}` |
| **Cases** | Up to 20 values to compare against. Each case gets its own output, **case 1**, **case 2**, and so on. |
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

Outputs, on **done**:

- `results`: a list with one entry per iteration. Each entry holds every node that ran in that iteration, keyed by node id, in the [same shape as `nodes.<id>`](data-and-placeholders.md#nodes-inside-results).
- `count`: how many iterations ran.

For a loop `each` whose body has a node `line`:

| Placeholder | What it holds |
|---|---|
| `{{ nodes.each.output.results[0].line.output.value }}` | What `line` produced in the first iteration |
| `{{ column(column(column(nodes.each.output.results, 'line'), 'output'), 'value') }}` | What `line` produced in every iteration, as a list |
| `{{ nodes.each.output.count }}` | How many iterations ran |

A node on a path that an iteration didn't take is missing from that iteration's entry. A loop inside the loop appears in each entry with its own `results`.

On the run page, use the arrows on a loop's steps to step through the iterations.

### Merge

Waits for parallel branches to finish, then continues once.

It waits for every branch that can still reach it. A branch that was never taken, such as the unused side of a condition, isn't waited for.

Any other node can wait the same way: turn on **Wait for all incoming branches** in its [settings](editor.md#configuring-a-node). Use **Merge** when you also want the branches' outputs gathered in one place.

Output: `branches`, the last node on each branch, keyed by node id, in the [same shape as `nodes.<id>`](data-and-placeholders.md#nodes-inside-results). For a branch that ends in a node `fetch`, `{{ nodes.join.output.branches.fetch.output.body }}` is that node's body.

### Wait

Pauses the run.

| Setting | What it does |
|---|---|
| **Wait** | **For a duration**, or **Until a time** |
| **Amount** and **Unit** | For a duration: how long, in **Seconds**, **Minutes**, **Hours**, or **Days**. Defaults to 5 minutes. |
| **Until** | For a time: a date and time, such as `2026-12-01 09:00` or `{{ trigger.send_at }}`. Times without a time zone are read as UTC. |

A time that has already passed doesn't wait. While it waits, the run is **Waiting**, and the run page shows when it resumes.

Output: `waited_until`.

### Trigger other workflow

Starts another workflow in the same workspace.

| Setting | What it does |
|---|---|
| **Workflow** | The workflow to start. It must be published, and can't be this workflow. |
| **Mode** | **Wait for it to finish**, or **Start it and move on** |
| **Input** | Keys and values passed to the other workflow, which it reads as `trigger.values.<key>`, the same as a manual trigger's inputs or a form's answers. If the other workflow's first trigger is a Manual or Form trigger with **Required** inputs, give each one, or the other run and this step fail. |

Outputs:

- **Wait for it to finish**: `results`, the last node on each path of the other workflow, keyed by node id, in the [same shape as `nodes.<id>`](data-and-placeholders.md#nodes-inside-results). For a workflow that ends in a node `reply`, `{{ nodes.call.output.results.reply.output.value }}` is that node's value. If the other run fails or is cancelled, this step fails.
- **Start it and move on**: `execution_id` and `status` of the run it started.

The other run shows **Another workflow** as its trigger and links back to this run. Chains of workflows starting workflows stop at the organization's **Maximum chain depth** (10 by default), so workflows that start each other can't loop forever. The same goes for an **HTTP call** to one of this install's own webhook URLs: the run it starts counts as one level deeper in the chain.

### Terminate

Ends the run here.

| Setting | What it does |
|---|---|
| **End as** | **Complete**, **Failed**, or **Cancelled** |
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

Outputs: `items` (the ones that match), `count` (how many match), and `removed` (the ones that don't).

### Create file

Makes a file from text, or from base64, such as an encoded image an API sent back. Use it to give a node that takes a file, such as [Upload to S3](#upload-to-s3), a [Send email](#send-email) attachment, or an [HTTP call](#http-call) upload, something to send. See [Files](files.md).

| Setting | What it does |
|---|---|
| **File name** | What the file is called, such as `report-{{ date(now(), 'Y-m-d') }}.csv` |
| **Content** | The text, or the base64 text. A list or object is saved as JSON. |
| **Content is** | **Text**, or **Base64**, which is decoded into the file's bytes |
| **Content type** | Optional, such as `image/png`. Left empty, it's guessed from the file name. |

Output: `file`.

### Update data store

Changes an entry of the workspace's [data store](workspaces.md#data-store), which keeps typed values between runs: the time of the last fetch, tickets already handled, a count of failures in a row. A later run, or another workflow, can read what this one saved.

There's no node to read the store. Read an entry with a placeholder, `{{ store.cursor }}`, or one item of a collection with `{{ store.seen_tickets[trigger.body.id] }}`. A placeholder reads the value as it is when its step runs, so a step sees what earlier steps of the same run wrote.

| Setting | What it does |
|---|---|
| **Entry** | The entry to change. Add entries on the workspace's **Data store** page first: this node never creates one. |
| **Item key** | For a collection, which item to change, such as `{{ trigger.body.id }}`. An item that isn't there yet is added. |
| **Operation** | **Set**, **Append**, or **Increment**. Only the ones that fit the entry's type are offered. |
| **Value** | For Set and Append. Text, or a single placeholder to keep its type, so `{{ nodes.fetch.output.body }}` saves an object or an array. |
| **Amount** | For Increment. 1 by default; a negative number subtracts. |

| Operation | Works on | What it does |
|---|---|---|
| **Set** | Every type | Replaces the value. |
| **Append** | String, Array | Adds text to the end of a String, or one item to the end of an Array. Appending an array adds it as a single item: `[1, 2]` plus `[3, 4]` is `[1, 2, [3, 4]]`. |
| **Increment** | Number | Adds the amount. |

In a collection, Append and Increment on an item that isn't there yet start from empty text, an empty array, or 0, so a Number collection counts per item key with no setup.

Outputs: `entry`, `item_key`, `operation`, `before` (the value before this step, or empty for a new item), and `after`.

The value has to fit the entry's type. [Exact conversions](workspaces.md#values-and-types) happen for you, such as the text `42` to a Number. Anything else fails the step, and so does:

- An entry that doesn't exist, or one that is **Read-only**. The editor shows both in the node's settings, and they block publishing.
- A collection with no **Item key**.
- A value over 64 KB, including one that grew past it by appending, or a new value when the workspace already holds 100,000.

A few things to know:

- Each change is a single step, so two runs, or two parallel branches of one run, can't lose each other's Append or Increment. With **Set**, the last one to finish wins. This was [checked with real workers](#how-parallel-changes-were-checked).
- A **step that is retried** after a failure runs again from its start. An Increment or Append adds again if its first attempt saved before the failure, so don't rely on an exact count where one extra matters.
- **Test runs** read and write the real store. Make an entry **Read-only** to keep tests from changing it.
- Values show in the run's step inputs and outputs, and on the Data store page to everyone in the workspace. Don't save secrets in them.

### Update variable

Changes one of the workflow's [variables](editor.md#variables) for the rest of the run. Variables are typed values that last for one run, declared under **Variables** in the workflow settings.

There's no node to read a variable. Read it with a placeholder, `{{ vars.total }}`, which gives its value as it is when the step runs, so a step sees what earlier steps of the same run wrote.

| Setting | What it does |
|---|---|
| **Variable** | The variable to change |
| **Operation** | **Set**, **Append**, or **Increment**. Only the ones that fit the variable's type are offered. They work as for [Update data store](#update-data-store). |
| **Value** | For Set and Append. Text, or a single placeholder to keep its type. |
| **Amount** | For Increment. 1 by default; a negative number subtracts. |

Outputs: `variable`, `operation`, `before`, and `after`.

The value has to fit the variable's type. [Exact conversions](workspaces.md#values-and-types) happen for you. Anything else fails the step, and so does a value over 64 KB.

How variables behave across a run:

- **Parallel branches.** Append and Increment from branches that run at the same time all apply: two branches that each add 1 add 2. With **Set**, the branch that finishes last wins, and which one that is isn't predictable, so don't Set the same variable from two branches.
- **Loops.** A variable belongs to the run, not to a loop item. One changed inside a [For loop](#for-loop) keeps its value for the next item and after the loop, which makes a variable the way to total or collect across items.
- **Retries.** A change is saved together with its step, so a step that fails and is retried never applies its change twice.
- **Other workflows.** A workflow started by [Trigger other workflow](#trigger-other-workflow) has its own variables and doesn't see the caller's. Pass values through its inputs and read its output.
- **Secrets.** Values show in the run's steps and on the run page, and a secret put in a variable is saved masked, so it can't be read back.

#### How parallel changes were checked

Parallel branches run on separate workers, so two of them can change the same value at the same moment. To check that no change is lost, a workflow with ten parallel branches was run 200 times with 2, 4, and 8 workers, on PostgreSQL and on SQLite. Its branches Increment and Append to variables, and Increment a data store entry and a collection item.

| Database | Workers | Result |
|---|---|---|
| PostgreSQL 17 | 2, 4, and 8 | Every run ended with the right values. No step failed or was retried. |
| SQLite | 2 and 8 | The same. |
| PostgreSQL 17, with the locking removed on purpose | 8 | 68 of 200 runs lost an Increment and 29 lost an Append, so the test does catch a lost change. |

MySQL and MariaDB weren't part of this check. They use the same row locks, but if you run on one, treat parallel changes to the same value as untested.

## Integrations

### HTTP call

Makes an HTTP request.

| Setting | What it does |
|---|---|
| **Method** | `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, or `OPTIONS` |
| **URL** | The address, with placeholders if needed, such as `https://api.example.com/orders/{{ trigger.order_id }}` |
| **Authentication** | Optional. An **HTTP Basic** or **API token** setting from the workspace's [credentials](workspaces.md#credentials). |
| **Query parameters** and **Headers** | Keys and values to send |
| **Body** | **None**, **JSON**, **Form fields**, **Raw text**, or **A file** |
| **File** | For **A file**: one placeholder for a file, such as `{{ nodes.make.output.file }}`. It's sent as the body, with the file's content type unless you set a `Content-Type` header. |
| **JSON or raw body** | For **JSON**, a JSON editor: it says whether the JSON is valid as you type, and **Format** indents it. Placeholders count as values, so `{"id": {{ trigger.id }}}` is valid. |
| **Timeout (s)** | How long to wait for an answer. Defaults to 30. |
| **Save the response as a file** | Off by default. For downloads such as images, zips, and backups, and for any body over 1 MB. The response comes back as `file`. |
| **Follow redirects** | Follow up to 5 redirects. On by default. The authentication and headers are sent only to the host in the URL, and never over plain HTTP when the URL is HTTPS, so a redirect elsewhere doesn't get your token or password. Query parameters go with the first request only; a redirect's address is used as it is. |
| **Fail on an error status (4xx or 5xx)** | On by default. Turn it off to handle error responses yourself, for example with a condition on `status`. |
| **Allow insecure connections** | Off by default. Skips checking the server's TLS certificate, for an internal server with a self-signed certificate or one that doesn't match its address. See the warning below. |

Outputs: `status` (the status code), `ok` (true for a 2xx status), `headers`, `body`, which is decoded JSON when the response is JSON, or else text, and `file`, the response as a [file](files.md), when it was saved as one or isn't text. A body over 1 MB is cut short unless the response is saved as a file.

With **Form fields**, a value that is a file, such as `{{ nodes.make.output.file }}`, is uploaded as a file (multipart).

HTTP calls can't reach private, loopback, or link-local addresses, such as `10.0.0.5` or `localhost`, unless a workspace admin lists them in the [internal-address allowlist](workspaces.md#workspace-settings).

> **Warning:** With **Allow insecure connections** on, the connection is still encrypted, but wag.run no longer checks who it's talking to. Anyone between wag.run and the server could read or change the request and the response, including any token or password sent with it. Use it only for internal servers you control. Where you can, give the server a valid certificate instead.

## Storage

### Upload to S3

Writes a file to a bucket on Amazon S3 or an S3-compatible service, such as MinIO, Cloudflare R2, Backblaze B2, or Wasabi. A file already at the same key is replaced.

| Setting | What it does |
|---|---|
| **Storage** | An **S3-compatible storage** setting from the workspace's [credentials](workspaces.md#credentials). It holds the region, endpoint, and access keys, and works for every bucket those keys can write to. |
| **Bucket** | The bucket's name, such as `backups` |
| **Key** | The file's path in the bucket, such as `backups/config-{{ date(now(), 'Y-m-d') }}.xml` |
| **Content** | Text, such as `{{ nodes.fetch.output.body }}`, or a file from an earlier node, such as `{{ nodes.fetch.output.file }}` from an HTTP call that saves its response as a file. Files are uploaded byte for byte. A list or object is saved as JSON. |
| **Content type** | Optional, such as `application/xml`. Left empty, a file keeps its own type, and text gets one guessed from the key's extension. |
| **Storage class** | Optional. **Bucket default** unless you choose **Standard**, **Intelligent-Tiering**, **Standard-Infrequent Access**, **One Zone-Infrequent Access**, **Glacier Instant Retrieval**, **Glacier Flexible Retrieval**, or **Glacier Deep Archive**. The colder classes cost less to keep but more, or longer, to read back. Other S3-compatible services may accept only some of these. |

Outputs: `bucket`, `key`, `url` (the file's address), and `size` (in bytes).

Like HTTP calls, an upload can't reach a storage endpoint on a private, loopback, or link-local address, such as a MinIO server at `10.0.0.5`, unless a workspace admin lists it in the [internal-address allowlist](workspaces.md#workspace-settings).

If the storage service refuses the upload, the step fails with its status and message. Server errors, rate limits, and connection problems are retried; other refusals, such as wrong keys or a missing bucket, are not. A bucket name that isn't 3 to 63 lowercase letters, numbers, dots, and hyphens fails the step before anything is sent.

## GitHub

These nodes work on a GitHub repository as a GitHub account, personal access token, or GitHub App. Each has a **GitHub connection**: a GitHub account, which you can connect right from the node with **Connect GitHub** (see [Connecting an account](workspaces.md#connecting-an-account)), or a personal access token or GitHub App (see [Other ways to connect GitHub](workspaces.md#other-ways-to-connect-github)), and a **Repository**, as `owner/name` or the repository's URL. A placeholder works in **Repository**, such as `{{ trigger.repository }}` after a [GitHub trigger](triggers.md#github).

If GitHub refuses the request, the step fails with GitHub's message. Only server errors and rate limits are retried.

### Create issue

Opens an issue. **Title** is required; **Body**, **Labels**, and **Assignees** (usernames) are optional, with labels and assignees separated by commas.

Outputs: `number`, `url`, and `id`.

### Comment on issue or PR

Adds a **Comment** to the issue or pull request with the given **number**.

Outputs: `id` and `url`.

### Create release

Publishes a release for **Tag**. If the tag doesn't exist yet, GitHub creates it at **Target**, a branch or commit (the default branch if empty). **Name** defaults to the tag. Turn on **Generate release notes** to have GitHub add notes from the pull requests since the last release, or **Save as a draft** or **Pre-release** as needed.

Outputs: `id`, `tag`, and `url`.

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

## Investbrain

These nodes work with your portfolios in [Investbrain](https://investbra.in) through its REST API. Each has an **API token**: an **Investbrain API token** setting from the workspace's [credentials](workspaces.md#credentials). The nodes act as the person who made the token, so they see every portfolio that person can, and change the ones they have full access to. The setting reaches Investbrain Cloud unless you give it the **Address** of a self-hosted Investbrain.

A **Portfolio** is the portfolio's ID, or its address in Investbrain, such as `https://investbra.in/portfolio/9b1f6c2e-…`. A **Symbol** is a ticker symbol, such as `AAPL`.

If Investbrain refuses the request, the step fails with its message. Only server errors and rate limits are retried. Investbrain allows 60 requests a minute for each person, and a list of more than 100 items takes a request for each 100.

### List portfolios

Lists your portfolios, each with its holdings. Use [List transactions](#list-transactions) for their transactions.

Outputs: `portfolios`, each with its `id`, `title`, `notes`, `wishlist`, `owner`, and `holdings`, and `count`.

### List holdings

Lists the holdings in every portfolio, or in **Portfolio**, optionally of one **Symbol**. Investbrain keeps a holding after its last share is sold; turn on **Include sold holdings** to list those too.

Outputs: `holdings` and `count`. Each holding has its `symbol`, `portfolio_id`, `quantity`, `currency`, `average_cost_basis`, `total_cost_basis`, `total_market_value`, `market_gain_dollars`, `market_gain_percent`, `realized_gain_dollars`, and `dividends_earned`.

### Get holding

Gets the holding of **Symbol** in **Portfolio**, with the same fields as each holding that [List holdings](#list-holdings) gives, as its outputs.

### List transactions

Lists transactions, newest first, in every portfolio or in **Portfolio**, optionally of one **Symbol** or only **Buys** or **Sales**. **At most** sets how many, 100 by default and up to 1,000.

Outputs: `transactions` and `count`. Each transaction has its `id`, `symbol`, `portfolio_id`, `transaction_type` (`BUY` or `SELL`), `quantity`, `currency`, `cost_basis`, `sale_price`, `date`, and whether Investbrain added it for a stock `split` or a `reinvested_dividend`.

### Add transaction

Records a **Buy** or a **Sale** of **Shares** of **Symbol** in **Portfolio**. **Price per share** is what each share cost for a buy, or fetched for a sale, in **Currency** (`USD` by default). **Date** is today if you leave it empty. Investbrain checks the transaction, for example that a sale doesn't sell more shares than the portfolio held on its date.

Outputs: the new transaction's `id`, `symbol`, `transaction_type`, `quantity`, `cost_basis`, `sale_price`, `currency`, `date`, and `portfolio_id`.

### Get quote

Gets **Symbol**'s price and fundamentals from the market data Investbrain keeps. The symbol doesn't need to be in a portfolio.

Outputs: `symbol`, `name`, `market_value` (the price), `fifty_two_week_low`, `fifty_two_week_high`, `dividend_yield`, `last_dividend_amount`, `last_dividend_date`, `market_cap`, `trailing_pe`, `forward_pe`, `book_value`, and `updated_at`.

### Export backup

Exports the backup workbook that Investbrain's **Import / Export** page makes, with everything the token's person can see, as a file. Investbrain builds the workbook in the background, so the step starts the export, then checks on it every 5 seconds, up to 12 times. Once the workbook is ready, the step downloads it. To keep a copy somewhere else, pass `{{ nodes.<id>.output.file }}` to [Upload to S3](#upload-to-s3) or attach it to a [Send email](#send-email).

If the workbook isn't ready after a minute, or Investbrain couldn't build it, the step fails. Give the step [retries](runs.md#retries-and-error-paths) for a large backup: a retry picks up the export that's still being built instead of starting another. Investbrain keeps only your latest export, allows 3 exports a minute, and deletes an export after 24 hours.

Outputs: `file`, the `.xlsx` workbook; `export_id`; `created_at`; and `expires_at`, when Investbrain deletes the export.

## wag.run

These nodes read from wag.run's own [REST API](api.md), so a workflow can work with workspaces and workflows, such as backing every workflow up. Each has an **API token**: a **wag.run API token** setting from the workspace's [credentials](workspaces.md#credentials), which holds a [personal access token](api.md#personal-access-tokens) with the `workflows:read` scope. The nodes act as the person who made the token, so they see the workspaces that person belongs to. The setting reaches this install unless you give it the **Address** of another one.

If the API refuses the request, the step fails with its message. Only server errors and rate limits are retried.

### List workspaces

Lists the workspaces the token's person belongs to.

Outputs: `workspaces`, each with its `slug`, `name`, and `url`, and `count`.

### List workflows

Lists the workflows in **Workspace**, given by its slug, such as `{{ item.slug }}` inside a [For loop](#for-loop) over workspaces. Turn on **Include definitions** to get each workflow's `definition` too: its draft, in the format workflows [export and import](editor.md#export-and-import) in.

Outputs: `workflows`, each with its `id`, `name`, `description`, `published_version`, `inputs`, and `url`; `count`; and `workspace`, the slug you gave, so steps inside a loop over the workflows can still tell which workspace they came from.

### Get workflow

Gets the workflow with **Workflow id** in **Workspace**, with its definition.

Outputs: `workflow` and `workspace`.

### Example: backing up every workflow

This saves every workflow to an S3 bucket each week, one file per workflow, in a folder named for the day, such as `2026-11-27 wag-run workflows`. Each file can be [imported](editor.md#export-and-import) as it is.

1. A [Schedule](triggers.md#schedule) trigger on the cron schedule `0 0 * * 5`: Fridays at midnight.
2. **List workspaces**.
3. A **For loop** over `{{ nodes.workspaces.output.workspaces }}`.
4. On its **each item** output, **List workflows** with **Workspace** `{{ item.slug }}` and **Include definitions** on.
5. A second **For loop** over `{{ nodes.workflows.output.workflows }}`.
6. On its **each item** output, [Upload to S3](#upload-to-s3) with **Content** `{{ item.definition }}` and this **Key**:

```text
{{ date(execution.started_at, 'Y-m-d') }} wag-run workflows/{{ nodes.workflows.output.workspace }}/{{ slug(item.name) }}-{{ item.id }}.workflow.json
```

The folder is named for the day the run started, so a run that crosses midnight still fills one folder.

## AI

### LLM call

Sends a prompt to a large language model and returns its answer.

| Setting | What it does |
|---|---|
| **AI provider key** | An **AI provider** setting from the workspace's [credentials](workspaces.md#credentials) |
| **Model** | The provider's model name, such as `claude-sonnet-5-5` |
| **Instructions** | Optional. A system prompt that sets how the model should behave. |
| **Prompt** | What to ask, with placeholders for data, such as `Summarize this ticket: {{ trigger.body.description }}` |
| **Images and documents** | Optional. One placeholder for a [file](files.md) or a list of files for the model to read: images, PDFs, or text files. Any other kind of file fails the step. |
| **Response** | **Free text**, or **Structured data** |
| **Structured fields** | For structured data: the fields the model must return. For each, a **Key**, a **Type** (**Text**, **Number**, **Whole number**, **Yes/no**, or **List of text**), a **Description** to guide the model, and whether it's **Required**. |
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
| **Attachments** | Optional. One placeholder for a [file](files.md) or a list of files, such as `{{ nodes.fetch.output.file }}`. |
| **SMTP server** | Optional. An **SMTP server** setting from the workspace's [credentials](workspaces.md#credentials). Leave it empty to send through the organization's [email settings](administration.md#email). |

Outputs: `to` (the addresses it was sent to), `subject`, and `message_id`.

### Send notification

Sends a message to people in wag.run. Each person receives it in their [Inbox](people.md#the-inbox), by email, or both, as they choose under **Preferences → Notifications**. To email someone no matter what they chose, or someone without an account, use **Send email**.

| Setting | What it does |
|---|---|
| **Recipients** | People with an account |
| **Title** | |
| **Message** | |
| **Link** | Optional. Where opening the item goes. Defaults to this run. |

Outputs: `recipients` (the user IDs notified) and `count`.

### Chat message

Posts a message to a Slack, Microsoft Teams, Discord, or Google Chat channel, through an incoming webhook.

| Setting | What it does |
|---|---|
| **Chat webhook** | A **Chat webhook** setting from the workspace's [credentials](workspaces.md#credentials) |
| **Message** | The text to post |

Outputs: `service` and `status` (the HTTP status the chat service answered with).

To get a webhook address, add an incoming webhook to the channel in the chat app, then save it in wag.run as a chat webhook. [Chat webhooks](chat-webhooks.md) has the steps for each app.

## People

### Approval

Pauses the run until enough of the chosen approvers approve, or it's rejected. It continues down **approved** or **rejected**.

### Task

Pauses the run until someone fills in a form, then continues with their answers.

Both are covered in [Approvals, tasks, and the Inbox](people.md).
