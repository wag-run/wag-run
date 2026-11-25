# The workflow editor

You build workflows in the editor, which opens when you open a workflow from **Workflows**. You need the **Editor** or **Admin** role to change a workflow. **Viewers** can open the editor but see it read-only.

## The layout

- **Node palette** on the left, grouped into **Triggers**, **Flow**, **Data**, **Integrations**, **GitHub**, **Google**, **AI**, **Messaging**, and **People**. Click a group to open it, and the back arrow to go up a level. Triggers on another app's events are under **Triggers › Other apps**, such as **Triggers › Other apps › GitHub**. Searching lists every match with the group it's in.
- **Canvas** in the middle. Scroll or drag the background to move around, and use the minimap in the corner to jump to a part of a large workflow. You can hide the minimap.
- **Settings panel** on the right. It shows the selected node's settings, or the workflow's settings when nothing is selected. Hide it with the panel button in the toolbar to make more room for the canvas.
- **Toolbar** along the top, with undo and redo, **Runs**, **Versions**, **Export**, **Run** or **Test draft**, and **Publish**.

## Adding nodes

- **Click** a node in the palette to add it after the selected node, already connected. With nothing selected, it's added to the right of the existing nodes.
- **Drag** a node from the palette to drop it exactly where you want it, unconnected.

Every workflow needs at least one trigger. A workflow can have more than one, for example a **Webhook** for another system and a **Manual** trigger for testing. Whichever one fires, the run starts from it.

## Connecting nodes

Drag from a node's output on its right edge to another node's input on its left edge.

- **Several outputs.** Some nodes have more than one output, such as **true** and **false** on **Conditional logic**. Only the connections from the output the node takes are followed.
- **Parallel branches.** Connect one output to several nodes to run them at the same time. Use **Merge** to wait for all of them before carrying on.
- **Branches that meet.** A node with several incoming connections runs once for each one that arrives, as soon as it arrives. To run it once, after all of them, turn on **Wait for all incoming branches** in its settings, or put a **Merge** in front of it.
- **No cycles.** A connection can't lead back to an earlier node. To repeat steps, use a **For loop**.
- **Triggers have no inputs.** Nothing can connect into a trigger.
- **Unconnected nodes don't run.** A run starts at a trigger and follows the connections from it. A node no trigger leads to is drawn faded with a dashed border and marked **Won't run**, even if it connects into a node that does run. Its outgoing connections are drawn dashed too. Leave a node like that to set it aside, or connect something that runs into it to bring it back.

To remove a connection, right-click it and choose **Delete connection**.

## Configuring a node

Select a node to see its settings in the settings panel:

- **Name**: what the node is called on the canvas and on the run page.
- **Reference its output as**: how later nodes refer to this node's output in placeholders, such as `nodes.http_request.output`. It's set when you add the node and doesn't change when you rename it.
- **The node's own settings.** Fields marked as accepting placeholders have an **Insert data** button for picking values from earlier nodes. See [Data and placeholders](data-and-placeholders.md).
- **Retries** and **Backoff (s)**: how many times to try again if the node fails, and how long to wait before the first retry. Each retry waits twice as long as the one before.
- **Show "on error" path**: adds an **on error** output. If the node still fails after its retries, the nodes connected to that output run instead of the run failing.
- **Wait for all incoming branches**: for a node with several incoming connections. It runs once, after every branch leading into it has finished, instead of once for each connection. A branch the run doesn't take, such as the unused side of a condition, isn't waited for.
- **Outputs**: what the node produces, which later nodes can use.

Changes save to the draft as you type.

## Selecting, copying, and deleting

Click a node to select it. Right-click a node for **Copy**, **Cut**, **Delete**, and **Node settings**. When several nodes are selected, right-click the selection to copy, cut, or delete them together. Right-click the canvas for **Paste here**, **Undo**, **Redo**, **Add sticky note**, and **Workflow settings**. Pasted nodes keep the connections between them. You're asked to confirm before nodes are deleted, and their connections are deleted with them.

| Action | Shortcut |
|---|---|
| Undo | ⌘Z or Ctrl+Z |
| Redo | ⇧⌘Z or Ctrl+Y |
| Copy | ⌘C |
| Cut | ⌘X |
| Paste | ⌘V |
| Delete selected | ⌫ |

## Sticky notes

Sticky notes explain a workflow to whoever reads it next. They never run and can't be connected.

Add one by right-clicking the canvas and choosing **Add sticky note**. Select it to edit:

- **Text**: supports basic Markdown, such as `**bold**`, `_italic_`, lists, and links.
- **Color**: **Yellow**, **Blue**, **Green**, **Pink**, **Gray**, or a hex code such as `#fde68a`.
- **Opacity**: how see-through the note is.

## Drafts and publishing

Every change you make goes into the workflow's **draft**. The toolbar shows when the draft was last saved, and **Unpublished draft** when it differs from the published version.

The draft doesn't run on its own. Webhooks, forms, schedules, and **Run** always use the **published version**. To try a draft without publishing, use **Test draft** (see [Runs](runs.md#test-runs)).

When you click **Publish**, the editor checks the workflow first. If something needs fixing, such as a required setting left empty or a condition without `{{ }}`, it lists the problems under **Fix these before publishing**. Otherwise the draft becomes a new numbered version, and new runs use it straight away. Runs already going stay on the version they started with.

To throw away your changes, click **Discard draft**. The draft goes back to the published version. You can undo a discard until you leave the editor.

## Versions and rolling back

**Versions** lists every published version, newest first, with who published it and when. The one new runs use is marked **Live**.

To go back to an old version, click **Roll back** next to it. This publishes a copy of that version as a new version, so history is never rewritten. Your current draft is replaced.

## Workflow settings

Click an empty part of the canvas, or right-click it and choose **Workflow settings**, to see the workflow's settings in the panel:

- **Name** and **Description**: shown on the **Workflows** list.
- **Concurrent runs**: what happens when the workflow is started while a run is already going:
  - **Allow concurrent runs**: both run (the default).
  - **One at a time, skip new runs**: the new run is recorded as **Skipped** and doesn't start.
  - **One at a time, new run cancels the previous**: the running one is cancelled and the new one starts.
- **Override log retention**: keep this workflow's run logs for a different number of days than the workspace default.
- **Override run limit**: let this workflow start a different number of runs a minute than the organization default (60). See [Runs per minute](runs.md#runs-per-minute).
- **Trigger URLs**: the addresses of the workflow's webhook and form triggers. They appear once the workflow is published. GitHub triggers show here too, with when they last checked or whether their webhook is registered.

Click **Save settings** to apply them. Workflow settings take effect immediately and aren't part of the draft.

## Export and import

Click **Export** in the toolbar, or **Export JSON** on the **Workflows** list, to download the workflow as a `.workflow.json` file. The file contains the nodes and connections. It contains no secret values: only the names of the secrets the workflow uses, and the IDs of its credentials.

To bring a workflow in, click **Import** on the **Workflows** list and choose the file. It's created as a new workflow with an unpublished draft. Any secrets it refers to must exist in the workspace, under the same names, before it can run. Credentials only carry over when you import into the workspace the workflow came from; elsewhere, nodes that used one are left empty, so choose the setting again in each before publishing.

## Deleting a workflow

On the **Workflows** list, open a workflow's menu and choose **Delete**. This deletes the workflow, all its versions, and its run history, and can't be undone.
