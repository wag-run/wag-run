# Approvals, tasks and the Inbox

Some workflows need a person to make a decision or provide information before they can carry on. **Approval** and **Task** nodes, under **People** in the palette, pause the run and send a request to the people you name. The run waits, with the status **Waiting**, until they respond in their **Inbox**.

## Who can receive requests

Approvers and assignees are chosen from the people in the organization, and must have an active account.

To open and answer a request, a person also needs a role in the workflow's workspace. **Guest** is enough, and is the right role for people who only answer requests: Guests see the approvals and tasks assigned to them, and none of the workspace's workflows, runs or settings. See [Members and roles](workspaces.md#members-and-roles).

## Approval

Pauses the run until enough of the approvers approve, or until it's rejected. The run then continues down the node's **approved** or **rejected** output.

| Setting | What it does |
|---|---|
| **Approvers** | Who is asked. Each gets their own request. |
| **Approvals needed** | How many of them must approve. Defaults to 1. |
| **Title** | What the request is called in the Inbox, such as `Refund {{ number_format(trigger.values.amount, 2) }} for {{ trigger.values.customer }}` |
| **Details** | More information for the approvers |
| **Timeout (hours)** | How long they have to respond. Defaults to 48. |
| **Also email the approvers** | On by default. They always get the request in their Inbox, and the email links to it. |

How the decision is made:

- As soon as the number of approvals reaches **Approvals needed**, the approval is **approved**.
- As soon as so many have rejected that the number needed can no longer be reached, it's **rejected**.
- Either way, the requests still open are withdrawn, so nobody answers a question that's already settled.

For example, with three approvers and **Approvals needed** set to 2, two approvals approve it, and two rejections reject it.

Outputs:

| Output | What it holds |
|---|---|
| `decision` | `approved` or `rejected` |
| `comment` | The comment left with the deciding response |
| `responded_by`, `responded_by_name` | Who gave the deciding response |
| `approvals`, `rejections` | How many approved and how many rejected |
| `responses` | Every answer given, each with the person's user ID, name, decision and comment |

## Task

Pauses the run until someone fills in a form, then continues with their answers.

| Setting | What it does |
|---|---|
| **Assignee** | The person who does the task |
| **Title** | What the task is called in the Inbox, such as `Set up a laptop for {{ trigger.values.name }}` |
| **Instructions** | What to do |
| **Form fields** | What to ask for. Each field has a **Key**, a **Label**, a **Type** (**Text**, **Long text**, **Number**, **Email**, **Date**, **Checkbox** or **Choice**) and whether it's **Required**. A **Choice** field lists its options, one per line as `value=Label`. |
| **Timeout (hours)** | How long they have. Defaults to 48. |
| **Also email the assignee** | On by default |

A task with no form fields works as a to-do: the assignee clicks **Submit** when it's done.

Outputs:

| Output | What it holds |
|---|---|
| `values.<key>` | Each answer, such as `{{ nodes.laptop.output.values.serial }}` |
| `responded_by`, `responded_by_name` | Who submitted it |

## When nobody responds

If the timeout passes with no decision, the request expires and the step **fails**. Without anything to catch it, the run fails too.

To handle a timeout yourself, turn on **Show "on error" path** in the node's settings and connect the **on error** output, for example to a **Send notification** that tells an admin the request was missed. The demo **Refund approval** workflow does this.

## The Inbox

The **Inbox** is in the account menu at the bottom of the sidebar. A red dot on your avatar, and a count next to **Inbox**, show how many items are unread. It collects, across all your workspaces:

- **Approvals and tasks** assigned to you
- **Failed runs** of workflows you own
- **Messages** that workflows send you with a **Send notification** node

Items are newest first. Ones you've opened are greyed out, and **Mark all read** clears the count. Approvals and tasks carry a badge with where they stand:

| Badge | Meaning |
|---|---|
| **Action needed** | Waiting for your answer |
| **Approved** or **Rejected** | The approval was decided |
| **Done** | The task was submitted |
| **Expired** | Nobody answered before the timeout |
| **Withdrawn** | No longer needed, because the decision was already made or the run ended, for example because it was cancelled |

## Responding to a request

Open an approval or task from your Inbox, or from the link in its email.

- **An approval** shows its details, who else was asked, how many approvals it has so far, and when it must be answered by. Add an optional comment, then click **Approve** or **Reject**.
- **A task** shows its instructions and form. Fill it in and click **Submit**.

The run carries on as soon as your answer settles it. Once a request has been answered, expired or withdrawn, it can't be answered again, and the page shows who answered and when.

Only the person a request was sent to can answer it.

## Waiting on you in a workspace

The workspace **Overview** also lists the approvals and tasks waiting on you in that workspace, under **Your approvals and tasks**.
