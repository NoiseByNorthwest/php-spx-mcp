# php-spx-mcp

An [MCP](https://modelcontextprotocol.io) server that exposes [php-spx](https://github.com/NoiseByNorthwest/php-spx)
profiling reports to LLM agents.

It gives an assistant read access to those profiles so it can list them, read a
report's metadata, and walk its call graph directly from the conversation,
instead of through the SPX web UI.

> [!IMPORTANT]
> Only the **SPX v0.5** report format is supported.

## How it works

php-spx writes one report per profiled request/command to a data directory: a
`<key>.json` metadata sidecar plus a compressed body (`<key>.txt.gz` or
`<key>.txt.zst`). This server reads that directory and serves the reports over
MCP's stdio transport. It reads the reports; it does not modify them.

## Requirements

- PHP >= 8.3
- `ext-zlib` (to read `.txt.gz` reports)
- `ext-zstd` *(optional, only to read `.txt.zst` reports)*
- A directory of php-spx reports to read

## Installation

```bash
git clone https://github.com/NoiseByNorthwest/php-spx-mcp.git
cd php-spx-mcp
composer install --no-dev
```

## Configuration

The server reads reports from the directory given by the `SPX_DATA_DIR`
environment variable, defaulting to `/tmp/spx`. Point it at the directory where
php-spx stores its reports.

Register the server with your MCP client (stdio transport). For example:

```json
{
  "mcpServers": {
    "php-spx": {
      "command": "php",
      "args": ["/absolute/path/to/php-spx-mcp/bin/server.php"],
      "env": {
        "SPX_DATA_DIR": "/tmp/spx"
      }
    }
  }
}
```

## Tools

### `find_reports`

Find reports with server-side filters, returning their essential metadata so a
follow-up `get_report_metadata` call is usually unnecessary.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `query` | string | n/a | Matched case-insensitively against the request URI and CLI command line. Plain text matches as a substring; `*`, `?`, `[]` enable wildcards. |
| `within_last_seconds` | int >= 0 | n/a | Only reports whose execution **started** within the last N seconds. |
| `since_timestamp` | int | n/a | Only reports whose execution started at or after this Unix time (seconds). |
| `min_wall_time_ms` | int >= 0 | n/a | Only reports whose recorded wall time is at least this value. |
| `limit` | int 1-200 | 50 | Maximum number of reports to return, most recent first. |

Returns a list of `{ key, timestamp, descriptor, wall_time_ms }`, most recent
first. `descriptor` is the request URI or CLI command line, depending on the
report.

### `get_report_metadata`

Get the full metadata for a report (enabled metrics, URL/command, duration,
memory, etc.).

| Parameter | Type | Description |
|---|---|---|
| `report_key` | string | The report key, as returned by `find_reports`. |

### `get_aggregated_call_graph`

Get the aggregated, pruned call graph for a report.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `report_key` | string | n/a | The report key. |
| `metric` | enum | `wt` | Metric to aggregate: `wt`, `ct`, `zm`, `zmmu`, `zmc`, `ze`, `zr`, `zo`, `zgc`, `zgr`, `io`, `ior`, `iow`. |
| `pruning_relative_threshold` | number 0-1 | 0.005 | Nodes below this fraction of the (sub)tree's total metric are dropped. |
| `root_stack` | string[] | `[]` | Optional call path to **zoom into**, from the outermost frame inward (see below). |

Children are sorted by value descending, and every non-root node carries its
source location (`file`, `lineNumber`).

#### Zooming with `root_stack`

The full graph can be large. To focus on a subtree, pass `root_stack`, the path
of calls from the root down to the call you want to re-root on. Each entry is a
function name as shown in the graph, optionally prefixed with `<lineNumber>:` to
disambiguate homonyms:

```json
[
  "/usr/local/bin/composer",
  "1:phar:///usr/local/bin/composer/bin/composer",
  "423:Composer\\Autoload\\ClassLoader::loadClass"
]
```

The result is re-rooted on the call the path lands on, and pruning then applies
relative to that subtree's own value, so `pruning_relative_threshold: 0.01`
after zooming means "drop calls below 1% of the focused call".

## Dev

### Install dependencies

```shell
composer install 
```

### Apply CS Fixer fixes

```shell
composer cs-fix
```

### QA

```shell
composer phpcs && composer cs-check && composer phpstan && ./vendor/bin/phpunit
```

## License

MIT
