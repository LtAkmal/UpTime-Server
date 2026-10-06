# Uptime formula

All times are UTC and stored as Unix milliseconds. Intervals are half-open `[start, end)`.
The collector (`UptimeFormula.php`) and the verifier (`protocol/uptime.go`) implement
exactly this, and the shared test vectors check that they agree.

## Parameters (per node, fixed once monitoring starts)

| Parameter | Default | Meaning |
| --- | --- | --- |
| interval | 30 s | how often the agent sends a heartbeat |
| timeout | 90 s | a heartbeat older than this is late ("monitoring delayed") |
| tolerance | 30 s | additional grace before downtime is counted |
| skew | ±120 s | accepted difference between agent clock and receipt time |

## Confirmed downtime

For every pair of consecutive accepted events `prev` and `next` (by receipt time `r`):

1. **No heartbeat:** if `r(next) > r(prev) + timeout + tolerance`, then
   `[r(prev) + timeout + tolerance, r(next))` is downtime.
2. **Reboot:** if `next` is a signed `boot` event, the machine booted at
   `boot = r(next) − mono_ms(next)`. If `boot > r(prev)`, then `[r(prev), boot)` is
   downtime, even if the agent came back quickly. This is conservative: the machine may
   have been up for part of that time, but a reboot can never look like continuous uptime.
   A graceful `stop` event just before the reboot makes the interval shorter and more
   accurate.

Overlapping intervals from the same gap are merged. After the last event, if
`now > r(last) + timeout + tolerance`, then `[r(last) + timeout + tolerance, now)` is
ongoing downtime (an active incident).

## Uptime of a window

```
start    = max(window start, monitoring start)        # first accepted event
covered  = window end − start                          # 0 if start ≥ window end
downtime = confirmed downtime clipped to [start, window end)
uptime % = (covered − downtime) / covered × 100        # "No data" when covered = 0
coverage = covered / (window end − window start) × 100
```

- Time before monitoring began is never counted as uptime or downtime.
- A window that monitoring covers only partly is shown with its coverage, for example
  "100% · monitored 12% of window". A window without coverage shows "No data", never 100%.
- Displayed percentages are rounded **down** to three decimals, so 99.9996% is never
  shown as 100%.

Windows on the status page: last 24 hours, last 7 days, last 30 days and all time
(since the first accepted event). The 30-day bars are UTC days.

## States

| State | Condition |
| --- | --- |
| Online | last valid heartbeat ≤ timeout ago, agent status `ok` |
| Degraded | last valid heartbeat ≤ timeout ago, agent status `degraded` |
| Monitoring delayed | timeout < age ≤ timeout + tolerance, or the last event was `stop` |
| Offline | age > timeout + tolerance |
| Verification failed | the last integrity verification of the node's chain failed |
| Monitoring disabled | disabled or archived by an administrator |
| Not enough data | no accepted event yet |

Overall: *Operational* (all online), *Degraded* (any degraded, delayed or unverified),
*Partial outage* (some offline), *Major outage* (all offline), *Monitoring unavailable*
(the collector's scheduled checks have not run for 10 minutes), *Monitoring not started*.

## Example

Heartbeats every 30 s; the last one before a silence arrives at 10:00:00 and the next at
10:05:00. Downtime is `[10:02:00, 10:05:00)` = 3 minutes (timeout 90 s + tolerance 30 s).
Over a fully monitored 24-hour window: (86 400 − 180) / 86 400 × 100 = 99.791%.
