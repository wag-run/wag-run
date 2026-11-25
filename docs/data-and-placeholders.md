# Data and placeholders

Each node can use data from the trigger, from the nodes that ran before it, and from the workspace. You bring that data into a node's settings with **placeholders**: expressions between double braces.

```text
Hi {{ trigger.values.name }}, your order of {{ length(trigger.values.items) }} items is on its way.
```

## The data picker

Fields that accept placeholders have an **Insert data** button. It lists everything the node can use, grouped by where it comes from:

- **Trigger:** the trigger's outputs, such as manual inputs, form values, or the webhook body.
- **Each earlier node**, by name, with its outputs.
- **Loop:** inside a For loop, **Current item**, **Item index**, and **Item count**.
- **Error:** on a node connected to an **on error** output, **Error message** and **Attempt**.
- **Variables** and **Secrets** of the workspace.
- **Execution:** the run's ID, workflow name, trigger type, and start time.

Pick a value to insert its placeholder at the cursor. If the cursor is already inside a placeholder, such as between the parentheses of `{{ upper() }}`, the value goes in without braces, as part of that expression. The picker only lists nodes that come before this one on a path to it, because those are the only ones whose output can exist when it runs. A node marked **Won't run**, because no trigger leads to it, isn't listed either.

Below the data, **Functions** lists the [helper functions](#helper-functions) by category, each with how it's called and what it does. Picking one inserts the call with the cursor between its parentheses; text you had selected becomes its argument.

To see what an output really contains, run the workflow once (a [test run](runs.md#test-runs) will do) and open the step on the run page.

## Highlighting and preview

In fields that accept placeholders, each `{{ }}` placeholder is highlighted, with function names and quoted text in their own colours. A placeholder that is never closed is underlined in red.

While such a field has focus, a **Preview** below it shows what the field would become, and its type. It uses the data of the workflow's latest run: what this node saw if it ran, otherwise what that run's trigger and earlier nodes produced. With no runs yet, data reads as empty, but functions and literals still preview. Secrets show masked. If an expression can't be worked out, the preview shows the error the run would fail with.

## What placeholders can read

| Name | What it holds |
|---|---|
| `trigger` | What the trigger received. See [Triggers](triggers.md) for each trigger's data. |
| `nodes.<id>.output` | The output of an earlier node. The id is shown in the node's settings, under **Reference its output as**. |
| `vars.<key>` | A workspace [variable](workspaces.md#variables) |
| `secrets.<key>` | A workspace [secret](workspaces.md#secrets) |
| `execution.id`, `execution.workflow`, `execution.trigger_type`, `execution.started_at` | About the run itself |
| `item`, `loop.index`, `loop.count` | Inside a [For loop](actions.md#for-loop): the current item, its position from 0, and the number of items |
| `error.message`, `error.attempt` | On a node reached through an **on error** connection: what went wrong, and on which attempt |

Use dots to reach inside objects, and square brackets for list positions or keys with unusual characters:

```text
{{ nodes.fetch.output.body.customer.email }}
{{ nodes.fetch.output.body.items[0].sku }}
{{ trigger.headers['x-request-id'] }}
```

A path to data that doesn't exist, such as a field the response didn't include or a node on a branch that didn't run, reads as empty instead of failing the run. Use `default()` to supply a fallback: `{{ default(trigger.note, "No note") }}`.

## Text, or a value

How a field uses placeholders depends on what else is in it:

- **Only one placeholder**, with nothing around it: the field gets the value itself, with its type. `{{ nodes.fetch.output.body.items }}` is a list, and `{{ trigger.price * trigger.quantity }}` is a number. Fields that need a list, such as the For loop's **Items**, need this form.
- **Placeholders inside text**: each is turned into text. Empty values become nothing, true and false become words, and lists and objects become JSON.

## Expressions

Inside the braces you can do more than read a value.

| Kind | Examples |
|---|---|
| Arithmetic | `trigger.price * 1.2`, `trigger.total - trigger.discount`, `loop.index + 1` |
| Comparisons | `==`, `!=`, `<`, `<=`, `>`, `>=` |
| Logic | `and`, `or`, `not` |
| Lists | `'vip' in trigger.values.tags`, `trigger.status not in ['cancelled', 'refunded']` |
| Text | `trigger.first ~ ' ' ~ trigger.last` joins text. `trigger.email matches '/@example\\.com$/'` tests a regular expression. |
| Choosing | `trigger.values.amount > 100 ? 'large' : 'small'` |
| Fallbacks | `trigger.values.nickname ?? trigger.values.name` |
| Literals | `'text'` or `"text"`, `42`, `true`, `false`, `null`, `[1, 2, 3]`, `{'a': 1}` |

Conditions, such as those of **Conditional logic** and **Filter**, must be a single placeholder that is true or false:

```text
{{ trigger.values.amount > 100 and 'vip' in trigger.values.tags }}
```

If an expression can't be worked out, for example because of a typo, the step fails with an "Expression error" message that says what went wrong.

## Helper functions

Call these inside placeholders, such as `{{ upper(trigger.code) }}`.

### Text

| Function | What it does | Example | Result |
|---|---|---|---|
| `lower(text)` | Lowercase | `lower('ABC')` | `abc` |
| `upper(text)` | Uppercase | `upper('abc')` | `ABC` |
| `capitalize(text)` | First letter uppercase, the rest lowercase | `capitalize('hELLO')` | `Hello` |
| `title(text)` | Each word capitalized | `title('jane doe')` | `Jane Doe` |
| `trim(text)` | Removes spaces at both ends | `trim('  hi ')` | `hi` |
| `replace(text, search, with)` | Replaces every match | `replace('a-b-c', '-', '/')` | `a/b/c` |
| `split(text, separator, limit)` | Splits into a list. The separator defaults to a comma. | `split('a,b,c')` | `['a', 'b', 'c']` |
| `slug(text)` | Makes a URL-friendly slug | `slug('Hello World!')` | `hello-world` |
| `format(pattern, values…)` | Fills a `sprintf` pattern | `format('%05d', 42)` | `00042` |
| `escape(text)` | Escapes HTML, for HTML emails | `escape('<b>')` | `&lt;b&gt;` |
| `strip_tags(text)` | Removes HTML tags | `strip_tags('<p>Hi</p>')` | `Hi` |
| `url_encode(value)` | Encodes for a URL. An object becomes a query string. | `url_encode('a b')` | `a%20b` |

### Numbers

| Function | What it does | Example | Result |
|---|---|---|---|
| `int(value)`, `float(value)` | Converts to a whole or decimal number | `int('42')` | `42` |
| `round(number, decimals)` | Rounds | `round(3.14159, 2)` | `3.14` |
| `abs(number)` | Absolute value | `abs(-5)` | `5` |
| `min(a, b, …)`, `max(a, b, …)` | Smallest or largest | `max(3, 7)` | `7` |
| `number_format(number, decimals, point, thousands)` | Formats for display | `number_format(1234.5, 2)` | `1,234.50` |

### Lists and objects

| Function | What it does | Example | Result |
|---|---|---|---|
| `length(value)` | Number of items, or characters in text | `length([1, 2, 3])` | `3` |
| `first(list)`, `last(list)` | First or last item | `first(['a', 'b'])` | `a` |
| `slice(list, start, length)` | Part of a list or text | `slice([1, 2, 3, 4], 1, 2)` | `[2, 3]` |
| `join(list, glue)` | Joins into text | `join(['a', 'b'], ', ')` | `a, b` |
| `column(list, key)` | One field from each object in a list | `column(nodes.fetch.output.body.items, 'id')` | `[1, 2, 3]` |
| `sum(list)` | Adds up the numbers | `sum(column(trigger.values.items, 'price'))` | `59.97` |
| `sort(list)`, `reverse(list)`, `unique(list)` | Sorts, reverses, or removes duplicates | `unique([1, 1, 2])` | `[1, 2]` |
| `merge(a, b)` | Combines two lists or objects | `merge([1], [2])` | `[1, 2]` |
| `keys(object)`, `values(object)` | An object's keys or values | `keys({'a': 1})` | `['a']` |
| `empty(value)` | True for empty text, lists, and nothing | `empty([])` | `true` |
| `default(value, fallback)` | The fallback when the value is empty | `default(null, 'n/a')` | `n/a` |

### Dates

| Function | What it does | Example |
|---|---|---|
| `now()` | The current time, as ISO 8601 | `2026-11-22T14:05:00+00:00` |
| `date(value, format, timezone)` | Formats a date or timestamp. With no value, the current time. The format defaults to `Y-m-d H:i:s`. | `date(trigger.created_at, 'j M Y', 'Europe/Paris')` |
| `add_days(value, days)` | A date some days later, or earlier with a negative number | `add_days(now(), 7)` |
| `timestamp(value)` | Seconds since 1970. With no value, now. | `timestamp('2026-01-01')` |

Date formats use [PHP's format letters](https://www.php.net/manual/en/datetime.format.php), such as `Y-m-d` for `2026-11-22` or `D, j M` for `Sun, 22 Nov`.

### Encoding

| Function | What it does |
|---|---|
| `json_encode(value)` | Turns a value into JSON text |
| `json_decode(text)` | Reads JSON text into a value |
| `string(value)` | Turns a value into text. Lists and objects become JSON. |
| `base64_encode(text)`, `base64_decode(text)` | Base64 |
| `sha256(text)` | SHA-256 hash, in hex |

## Secrets in logs

Secret values never appear in run logs. Wherever a secret's value would show in a step's input or output, the run page shows it masked. Even so, avoid sending secrets anywhere they could be read back, such as into an email body.
