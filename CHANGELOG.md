# Changelog

## 0.2.0

- New command `perf:requests`: it measures the requests that the host names in `page-performance.requests`, with
  any method, headers and a JSON body, and prints the same report as `perf:pages`.
- New config keys `requests` and `fixture`, and the interface `Olgun\PagePerformance\RequestFixture`: the host makes
  the rows that a request needs, and a request names their values as `{fixture.key}`.
- Each run of a request is in a database transaction that is rolled back. An outbound HTTP call is refused, and its
  row fails with `outbound call refused`. Mail goes to an `array` mailer and a queued job to a `null` queue.
- `perf:requests` judges the budgets of `page-performance.budgets`, keyed by the request name.
- `perf:pages` sends its pages as before, and its report does not change.
