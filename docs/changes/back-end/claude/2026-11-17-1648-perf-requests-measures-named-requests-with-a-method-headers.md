# perf:requests measures named requests with a method, headers and a JSON body

Date: 2026-11-17 16:48 +0300. Agent: claude.

- **What changed:** A second command, perf:requests, sends each request of page-performance.requests (any method, headers, a JSON body) through the same measurer and report as perf:pages. A host fixture (page-performance.fixture, Olgun\PagePerformance\RequestFixture) makes the rows; each run rolls back; an outbound call fails its row; budgets are judged by the request name.
- **The command:** `php artisan perf:requests [--only=<name>] [--repeat=5] [--json]`
- **When to run it:** To measure a POST, an API write or a JSON-RPC call in a local or testing environment.
- **What it must never do:** Never run it in production (it refuses). Never expect a cache write, a file write or a second database connection to roll back.
