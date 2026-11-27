# Runs

A run is one execution of a workflow, from its trigger to the last step. wag.run records every run, with each step's input, output, and error, so you can see exactly what happened.

## Starting runs

Runs start when a workflow's trigger fires: a webhook call, a schedule, a form submission, another workflow, or someone clicking **Run**. Every run uses the workflow's **published** version and stays on that version until it finishes, even if a newer one is published while it's going.

To run a workflow by hand, it needs a [Manual trigger](triggers.md#manual). Click **Run** in the editor, or **Run manually** on the **Workflows** list, fill in the inputs, and click **Start run**. You need the **Editor** or **Admin** role.

## Test runs

A test run uses the **draft** instead of the published version, so you can try changes before publishing them.

1. In the editor, click **Test draft**. Once the workflow has been published, it's in the menu next to **Run**.
2. Give the trigger something to work with:
   - For a **Manual** trigger, fill in its inputs.
   - For other triggers, enter sample data as JSON, in the shape the trigger would receive. For a webhook, that could be `{"body": {"order_id": 1042}}`. The editor says whether the JSON is valid, and **Format** indents it.
3. Click **Start test run**.

A test run does everything a real run does, **including sending emails, posting messages, and calling other services**. It's marked **Test** in the run list.

Webhooks, forms, and schedules keep using the published version while you test.

## The run list

**Runs** in the sidebar lists every run in the workspace, newest first, with its workflow, status, trigger, start time, and duration. Filter it by workflow or by status. The **Runs** button in the editor opens the list filtered to that workflow.

## The run page

Open a run to see it drawn on the workflow's canvas. While the run is going, the page updates by itself every couple of seconds: each node shows its status, and the connections being followed are animated.

Alongside the canvas, **Steps** lists every step in the order it ran. Click a step, on the list or on the canvas, to see:

- Its **Input**: the settings it ran with, with placeholders filled in.
- Its **Output**, or its **Error**.
- Its **Attempts**, if it was retried.
- For an approval or task, who it was sent to and when it's due.
- For **Trigger other workflow**, a link to the run it started.

Click **Back to all steps** to return to the list.

Also on the page:

- **Trigger input**: what the trigger received.
- **Variables**: the workflow's [variables](editor.md#variables) with their values in this run, kept up to date while the run is going. Each **Update variable** step also shows the value before and after in its output.
- **Run output**: [what the run returns](#what-a-run-returns).
- **Not reached yet** or **Not run**: nodes the run hasn't reached, or didn't take.
- **Started by run …**: for a run started by another workflow, a link to the run that started it.
- **Open workflow**: back to the editor.

### Loops

Nodes inside a [For loop](actions.md#for-loop) run once per item. Select one, then use **Previous iteration** and **Next iteration** to step through the items and see each iteration's input and output.

### Waiting runs

A waiting run shows what it's waiting for: **Resumes** with a time for a **Wait** node, or **Times out** with a time for an approval or task.

## What a run returns

A run's output is what the workflow returns. A [Trigger other workflow](actions.md#trigger-other-workflow) node that waits for the run receives it, the [API and MCP server](api.md) return it as the run's `output`, and the run page shows it as **Run output**.

Unless you choose otherwise, a run returns the last node on each path, keyed by node id, in the [same shape as `nodes.<id>`](data-and-placeholders.md#nodes-inside-results):

- A workflow with one path returns its last node.
- A workflow that splits into several paths returns the last node of each.
- Paths that meet at a **Merge**, or at a node set to **Wait for all incoming branches**, carry on as one path, so the nodes before it aren't returned.
- A path that ends in a **For loop** returns the loop, with its `results`.
- Nodes in the middle of a path aren't returned. Their outputs are still on the run page.
- A path the run didn't take returns nothing.

For example, a workflow with one path that ends in a node `summary` and another that ends in a node `total` returns:

```json
{
  "summary": { "output": { "value": "3 new orders" }, "port": "main" },
  "total": { "output": { "value": 59.97 }, "port": "main" }
}
```

Because this follows the shape of the workflow, adding a node to the end of a path changes what the run returns. To return a value you choose, end the run with a [Terminate](actions.md#terminate) node and set its **Output**. That value becomes the run's output, in place of the last node on each path.

## Statuses

| Status | Meaning |
|---|---|
| **Running** | Steps are being worked on |
| **Waiting** | Paused on a Wait, an approval, a task, or another workflow it started |
| **Complete** | Finished |
| **Failed** | A step failed and nothing handled the error, or a **Terminate** node ended it as failed |
| **Cancelled** | Someone cancelled it, a newer run replaced it, a **Terminate** node ended it as cancelled, it was too many levels deep in a chain of workflows starting workflows, or its workflow had already started too many [runs that minute](#runs-per-minute) |
| **Skipped** | Never started, because the workflow runs one at a time and another run was going |

When a run fails, the owner of the workflow gets an item in their [Inbox](people.md#the-inbox) and an email. They can turn either off under **Preferences → Notifications**.

## Cancelling a run

To stop a run that is running or waiting, click **Cancel run** on its page. You need the **Editor** or **Admin** role. Steps still open are cancelled, any workflows it started are cancelled too, and open approvals and tasks are withdrawn.

## Retries and error paths

Any step can fail: a service doesn't answer, an expression has a typo, an approval times out. Each action node has two ways to deal with failure, in its settings.

### Retries

**Retries** is how many more times to try the step after it fails. It's 0 by default. **Backoff (s)** is how long to wait before the first retry, 30 seconds by default. Each retry waits twice as long as the one before, so with 3 retries and a 30-second backoff, the retries happen after 30 seconds, 1 minute, and 2 minutes.

Retries suit failures that might go away on their own, like a service that's briefly down. Some failures are never retried because trying again can't help, such as a setting that isn't valid.

### The "on error" path

Turn on **Show "on error" path** to give the node an **on error** output. If the step still fails after its retries, the nodes connected to **on error** run instead, and the run carries on as if nothing went wrong.

Nodes on the error path can read what happened:

| Placeholder | What it holds |
|---|---|
| `error.message` | The error |
| `error.attempt` | Which attempt failed last |

For example, connect a **Send notification** to an HTTP call's **on error** output with the message `Couldn't update the order: {{ error.message }}`.

## Running one at a time

By default, a workflow can have any number of runs going at once. To change that, open the [workflow settings](editor.md#workflow-settings) and set **Concurrent runs**:

- **One at a time, skip new runs**: while a run is going, new ones are recorded as **Skipped**. Good for a schedule that mustn't overlap with itself.
- **One at a time, new run cancels the previous**: a new run cancels the one that's going. Good when only the latest data matters.

A run counts as going while it is **Running** or **Waiting**.

## Runs per minute

Each workflow can start up to 60 runs a minute. Runs past the limit are recorded as **Cancelled** with the reason, and don't do anything. This keeps a loop from running away, such as a workflow that creates a GitHub tag and is itself triggered by new tags.

An organization admin sets the default under **Org settings → General → Maximum runs per minute**. To give one workflow a different limit, turn on **Override run limit** in its [workflow settings](editor.md#workflow-settings). Test runs don't count toward the limit.

## How long runs are kept

Run logs are deleted once they're older than the retention period, checked every hour. Completed, failed, cancelled, and skipped runs are all removed the same way. Runs still going are never removed. The period is set at three levels, and the most specific one wins:

1. **The organization's default**, under **Org settings → General**. 30 days unless changed, or keep forever.
2. **The workspace**, under **Settings**, which can use its own period.
3. **The workflow**, under its workflow settings, with **Override log retention**.
