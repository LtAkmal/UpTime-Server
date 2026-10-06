# Independent external monitors (roadmap)

**Status: not implemented.** The status page shows "Independent monitors so far: 0" and
the notice "Current deployment is not independently operated by a third-party monitor."
Do not remove that notice until real independent monitors exist.

## Why the current deployment is not independent

The agent proves that *the node's key* signed each heartbeat. The collector, the node,
the keys and the database are all controlled by one operator, and in the initial
deployment the collector and a node may share a machine. Such an operator could, in
principle, suppress or fabricate records using its own keys and code. A customer can
detect inconsistencies, but cannot get an outside opinion.

## Model

An external monitor is a separate party (a different provider, network and operator)
that:

1. periodically probes a node's public service and/or the collector's public API from
   its own network;
2. signs each observation with its own Ed25519 key:
   `{node, target, observed_at, result: up|down|error, latency_ms, collector_head}`,
   including the node's chain head it saw on `/api/uptime/agent/nodes/{id}/head` (or the
   last event hash in a proof), which pins the collector's chain at that time;
3. publishes its observations itself (its own endpoint or an append-only log) **and/or**
   submits them to the collector.

The collector would store witness observations in a separate append-only table, list
each monitor (name, operator, public key fingerprint, network) on the status page, and
show per node how many independent witnesses agree with the agent's record. The proof
format would gain a `witnesses` section; `uptime-verify` would check witness signatures
and compare witnessed chain heads with the proof (a rewritten chain would not contain a
witnessed head).

## Adding one later

1. Run a small prober (any language) on infrastructure the hosting operator does not
   control.
2. Generate its key there; publish the fingerprint from the monitor's own website.
3. Register the monitor's public key in the collector (new admin section).
4. Probe every 60 s; sign and publish observations; optionally submit them.

Recommended: **at least two** independent monitors in different networks and regions,
so that one monitor's network problems are not reported as node downtime and no single
third party has to be trusted.

No placeholder code for this exists in the repository on purpose: an interface without
an implementation would suggest a capability that is not there.
