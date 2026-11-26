# Files

Runs can receive, make, and pass on files: an image posted to a webhook, a PDF someone uploads in a form, a backup an HTTP call downloads. A file travels through a run as a small reference, and nodes that take files, such as **Upload to S3** and **Send email**, read the bytes from it.

A file reference has a `name`, a `mime` (its type, such as `image/png`), and a `size` in bytes. For a file in `nodes.fetch.output.file`, `{{ nodes.fetch.output.file.name }}` is its name.

## Where files come from

| Source | Where the file is |
|---|---|
| A [Webhook](triggers.md#webhook) request with uploaded files | `trigger.files`, by field name |
| A webhook request whose body isn't text, such as an image posted as it is | `trigger.body`, as a file named `body` with an extension for its type |
| A **File upload** input or field on a [Manual trigger](triggers.md#manual) or a [Form](triggers.md#form) | `trigger.values.<key>` |
| A **File upload** field on a [Task](people.md) | `nodes.<id>.output.values.<key>` |
| [HTTP call](actions.md#http-call) with **Save the response as a file** on, or a response that isn't text | `nodes.<id>.output.file` |
| [Create file](actions.md#create-file) | `nodes.<id>.output.file` |
| [Export backup](actions.md#export-backup) from Investbrain | `nodes.<id>.output.file` |

## What nodes do with files

Give these settings a file, or a list of files, as the whole value, such as `{{ nodes.fetch.output.file }}`:

| Node | Setting | What happens |
|---|---|---|
| [Upload to S3](actions.md#upload-to-s3) | **Content** | The file is uploaded byte for byte |
| [Send email](actions.md#send-email) | **Attachments** | The files are attached |
| [HTTP call](actions.md#http-call) | **Body** set to **A file**, or a **Form fields** value | The file is sent as the body, or uploaded as part of a multipart form |
| [LLM call](actions.md#llm-call) | **Images and documents** | The model reads images, PDFs, and text files |

A file can't be part of text. `Report: {{ nodes.fetch.output.file }}` fails; use the file as the whole value, or read it with [`text()` or `base64()`](data-and-placeholders.md#files).

## Reading a file's contents

`{{ text(nodes.fetch.output.file) }}` is the file's text, and `{{ base64(nodes.fetch.output.file) }}` its bytes as base64. They read the file into the run's data, so they work only on files up to 1 MB, and `text()` fails on a file that isn't text.

To go the other way, [Create file](actions.md#create-file) turns text or base64 into a file.

## On the run page

Files in a run's trigger input, or in a step's input or output, are listed with a download link, their type, and their size. Downloads are always sent as attachments, never shown in the page.

## Size and how long files are kept

A file can be up to 50 MB. Self-hosters can lower this with `WORKFLOW_FILES_MAX_MB` (see [Self-hosting](self-hosting.md#settings)). A file is deleted with its run's logs, after the [retention period](runs.md#how-long-runs-are-kept).
