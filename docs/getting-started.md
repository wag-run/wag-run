# Getting started

This guide walks you through signing in, finding your way around, and building your first workflow: a large-order alert that checks an amount and notifies you when it is over a limit. It takes about ten minutes.

You need an account on a wag.run install and the **Editor** or **Admin** role in a workspace. If you can't see **Workflows** in the sidebar, ask a workspace admin to change your role.

## 1. Sign in

Open your wag.run address and sign in with your email and password, a passkey, or a sign-in provider button such as **Sign in with Okta** if your install has one set up.

- **Invited by email?** Open the invitation link to create your account and join. Invitations expire, so ask for a new one if yours no longer works.
- **First person on a new install?** Register an account. The first account becomes the organization admin, and its email address counts as confirmed. To set up an install, see [Self-hosting](self-hosting.md).

Once you're in, open **Preferences** from the account menu at the bottom of the sidebar to add a passkey or turn on two-factor authentication.

## 2. Find your way around

The sidebar shows the current workspace at the top. Use it to switch workspaces. Below it:

| Item | What it's for |
|---|---|
| **Overview** | The workspace's workflows and the approvals and tasks waiting on you |
| **Workflows** | Create, import, and open workflows |
| **Runs** | Every run in the workspace, newest first, filterable by status |
| **Data store**, **Secrets**, **Credentials** | Shared values and sign-in details your workflows use |
| **Members**, **Settings**, **Audit log** | Workspace administration (Admins) |

On a wide screen, drag the sidebar's right edge to make it wider or narrower. Drag it all the way in to shrink it to icons, and drag it back out, or double-click the edge, to open it again. wag.run remembers the width in your browser.

Organization admins also see **Org settings** in the account menu, for workspaces, users, sign-in, email, and organization-wide defaults.

Your **Inbox** is in the account menu at the bottom of the sidebar. It collects approvals and tasks assigned to you, failed runs of workflows you own, and messages from workflows, across all your workspaces. A red dot on your avatar means something in it is unread.

> **Tip:** If your workspace has the demo workflows (**Refund approval**, **Order status updates**, **New hire onboarding**, **Daily post digest**, and **Expense report**), open one to see a finished workflow before building your own.

## 3. Create a workflow

1. Go to **Workflows** and click **New workflow**.
2. Name it `Large order alert` and click **Create and open**.

The editor opens with three areas:

- **Node palette** on the left. Click a node to add it after the selected one or drag it onto the canvas.
- **Canvas** in the middle, where you arrange and connect nodes.
- **Settings panel** on the right, which shows the selected node's settings, or the workflow's settings when nothing is selected.

Your changes save to a draft as you go. The toolbar shows **Draft saved** with the time.

## 4. Set up the trigger

Every workflow starts with a trigger. A new workflow already has one: the **Run manually** node on the canvas, a Manual trigger that starts the run by hand.

1. Click the **Run manually** node to select it.
2. In the settings panel, find **Inputs to ask for** and click **Add** twice to add two inputs:

   | Key | Label | Type | Required |
   |---|---|---|---|
   | `customer` | Customer | Text | Yes |
   | `amount` | Amount | Number | Yes |

Whoever runs the workflow fills in these inputs. Later nodes can read them as `trigger.values.customer` and `trigger.values.amount`.

## 5. Add a condition

1. With the trigger selected, click **Conditional logic** in the palette. It's added and connected after the trigger.
2. Set **Condition** to:

   ```text
   {{ trigger.values.amount > 500 }}
   ```

The node now has two outputs, **true** and **false**. A condition must be one `{{ }}` placeholder that is true or false, and publishing is refused if it isn't.

## 6. Send a notification

1. Drag **Send notification** from the palette onto the canvas.
2. Drag from the condition's **true** output to the new node's input to connect them.
3. In its settings:
   - **Recipients**: choose yourself.
   - **Title**: `Large order from {{ trigger.values.customer }}`
   - **Message**: `{{ trigger.values.customer }} placed an order for {{ number_format(trigger.values.amount, 2) }}.`

Instead of typing placeholders, you can click **Insert data** on a field and pick a value from the trigger or an earlier node.

Leave the **false** output unconnected. When the amount is 500 or less, the run takes that output and finishes there.

## 7. Test the draft

Try the workflow before publishing it.

1. Click **Test draft** in the toolbar. (Once the workflow is published, **Test draft** moves into the menu next to **Run**.)
2. Enter a customer and an amount over 500, then click **Start test run**.

A test run does everything a real run does, including sending emails and calling other services, so be careful with workflows that contact people outside your team.

You land on the run page. The canvas shows how far the run got, and you can click any step to see its input, output, and any error. You should also have a new item in your Inbox.

Run another test with an amount under 500. This time the run stops after the condition and no notification is sent.

If something is wrong, the editor lists what to fix under **Fix these before testing the draft**.

## 8. Publish and run

1. Back in the editor, click **Publish**. The workflow becomes version 1, and the toolbar shows **Published v1**.
2. Click **Run**, which now appears in the toolbar, fill in the inputs, and click **Start run**.

This time the run uses the published version. You can also start it from **Run manually** on the **Workflows** list.

From now on, your edits go into a new draft. The published version keeps running unchanged until you publish again. If you change your mind, use **Discard draft** to go back to the published version.

## 9. Follow your runs

**Runs** in the sidebar lists every run in the workspace, with its trigger, status, start time, and duration. Open one to see it on the canvas:

- Each step shows its status. Click it for its input, output, and error, and each attempt if it was retried.
- A waiting run shows what it's waiting for, such as **Resumes** with a time for a Wait node, or **Times out** for an approval.
- Editors and Admins can **Cancel run** while it's running or waiting.

Run logs are kept for the workspace's retention period, then removed.

## 10. Try an approval

To see people in the loop, add an approval before the notification:

1. Right-click the connection from **true** to **Send notification** and choose **Delete connection**.
2. Select the condition node and click **Approval** in the palette. It's added and connected to the **true** output.
3. Set **Approvers** to yourself, **Approvals needed** to `1`, and **Title** to `Approve order for {{ trigger.values.customer }}`.
4. Connect the approval's **approved** output to **Send notification**.
5. Test the draft with an amount over 500.

The run's status changes to **Waiting**. Open **Inbox** from the account menu, open the request, and click **Approve**. The run continues and sends the notification. Click **Reject** instead, and it continues down the **rejected** output.

## Keyboard shortcuts in the editor

| Action | Shortcut |
|---|---|
| Undo | ⌘Z or Ctrl+Z |
| Redo | ⇧⌘Z or Ctrl+Y |
| Copy, cut, paste | ⌘C, ⌘X, ⌘V |
| Delete selected | ⌫ |

Right-click a node or the canvas for the same actions.

## Next steps

- Read the [overview](README.md) for the concepts behind workspaces, versions, runs, and placeholders.
- Learn the rest of [the editor](editor.md), then browse the [triggers](triggers.md) and [actions](actions.md) you can build with.
- Open the demo workflows to see webhooks, forms, schedules, loops, tasks, variables, and error handling working together.
