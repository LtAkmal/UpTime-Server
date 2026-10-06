package protocol

import "sort"

// Params are a node's monitoring settings, in milliseconds.
type Params struct {
	IntervalMS  int64 `json:"interval_ms"`  // how often the agent sends a heartbeat
	TimeoutMS   int64 `json:"timeout_ms"`   // a heartbeat older than this is late
	ToleranceMS int64 `json:"tolerance_ms"` // extra grace before downtime is counted
	SkewMS      int64 `json:"skew_ms"`      // accepted |agent clock - receipt time|
}

// Point is what the uptime formula needs from one accepted event.
type Point struct {
	ReceivedMS int64  // collector receipt time (Unix ms, UTC)
	Type       string // event type
	MonoMS     int64  // agent-reported time since boot
}

// Outage causes.
const (
	CauseNoHeartbeat = "no_heartbeat" // no valid heartbeat within timeout + tolerance
	CauseReboot      = "reboot"       // the machine restarted (from the signed boot event)
)

// Interval is a half-open time range [StartMS, EndMS) of confirmed downtime.
type Interval struct {
	StartMS int64  `json:"start"`
	EndMS   int64  `json:"end"`
	Cause   string `json:"cause"`
}

// DurationMS returns the interval length.
func (i Interval) DurationMS() int64 { return i.EndMS - i.StartMS }

// GapOutages returns the confirmed downtime between two consecutive accepted events:
//
//   - no heartbeat: [prev + timeout + tolerance, next) when next arrived later than that;
//   - reboot: when next is a boot event, the machine was not running between the last
//     event before the reboot and the boot instant (next receipt - next mono_ms), so
//     [prev, boot instant) is downtime even if the agent came back quickly.
//
// Overlapping results are merged.
func GapOutages(prev, next Point, p Params) []Interval {
	var out []Interval
	grace := prev.ReceivedMS + p.TimeoutMS + p.ToleranceMS
	if next.Type == TypeBoot {
		bootAt := next.ReceivedMS - next.MonoMS
		if bootAt > next.ReceivedMS {
			bootAt = next.ReceivedMS
		}
		if bootAt > prev.ReceivedMS {
			out = append(out, Interval{prev.ReceivedMS, bootAt, CauseReboot})
		}
	}
	if next.ReceivedMS > grace {
		timeout := Interval{grace, next.ReceivedMS, CauseNoHeartbeat}
		if len(out) == 1 && out[0].EndMS >= timeout.StartMS {
			out[0].EndMS = max(out[0].EndMS, timeout.EndMS)
		} else {
			out = append(out, timeout)
		}
	}

	return out
}

// OpenOutage returns the ongoing downtime after the last accepted event, if the
// node has been silent for longer than timeout + tolerance at endMS.
func OpenOutage(last Point, p Params, endMS int64) *Interval {
	grace := last.ReceivedMS + p.TimeoutMS + p.ToleranceMS
	if endMS <= grace {
		return nil
	}

	return &Interval{grace, endMS, CauseNoHeartbeat}
}

// Outages computes all confirmed downtime for events sorted by receipt time. When
// open is true the silence after the last event (up to endMS) is included.
func Outages(points []Point, p Params, endMS int64, open bool) []Interval {
	var out []Interval
	for i := 1; i < len(points); i++ {
		out = append(out, GapOutages(points[i-1], points[i], p)...)
	}
	if open && len(points) > 0 {
		if o := OpenOutage(points[len(points)-1], p, endMS); o != nil {
			out = append(out, *o)
		}
	}
	sort.Slice(out, func(a, b int) bool { return out[a].StartMS < out[b].StartMS })

	return out
}

// Window is the uptime of one reporting window.
type Window struct {
	FromMS          int64    `json:"from"`
	ToMS            int64    `json:"to"`
	CoveredMS       int64    `json:"covered_ms"`  // part of the window after monitoring began
	DowntimeMS      int64    `json:"downtime_ms"` // confirmed downtime inside the covered part
	UptimePercent   *float64 `json:"uptime_percent"`
	CoveragePercent float64  `json:"coverage_percent"`
}

// Calculate applies the published formula to a window [fromMS, toMS):
//
//	covered  = toMS - max(fromMS, monitoringStart)
//	uptime % = (covered - downtime inside covered) / covered × 100
//
// Time before monitoring began is never counted (neither up nor down). With no
// coverage the uptime is nil ("no data"), never 100%.
func Calculate(outages []Interval, monitoringStartMS, fromMS, toMS int64) Window {
	w := Window{FromMS: fromMS, ToMS: toMS}
	if monitoringStartMS <= 0 || toMS <= fromMS {
		return w
	}
	start := max(fromMS, monitoringStartMS)
	if start >= toMS {
		return w
	}
	w.CoveredMS = toMS - start
	for _, o := range outages {
		s, e := max(o.StartMS, start), min(o.EndMS, toMS)
		if e > s {
			w.DowntimeMS += e - s
		}
	}
	if w.DowntimeMS > w.CoveredMS {
		w.DowntimeMS = w.CoveredMS
	}
	pct := float64(w.CoveredMS-w.DowntimeMS) / float64(w.CoveredMS) * 100
	w.UptimePercent = &pct
	w.CoveragePercent = float64(w.CoveredMS) / float64(toMS-fromMS) * 100

	return w
}
