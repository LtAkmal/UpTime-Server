package protocol

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"flag"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

var update = flag.Bool("update", false, "rewrite testdata/vectors")

const minute = int64(60_000)

// chain builds a signed event chain the way an agent and collector would.
type chain struct {
	t      *testing.T
	priv   ed25519.PrivateKey
	node   string
	seq    int64
	head   string
	events []ProofEvent
	nextID int64
}

func newChain(t *testing.T, priv ed25519.PrivateKey) *chain {
	return &chain{t: t, priv: priv, node: "nd-test-01", head: GenesisHash, nextID: 1}
}

// add appends an event received at receivedMS (agent clock equal to receipt).
func (c *chain) add(eventType string, receivedMS, monoMS int64, boot string) ProofEvent {
	c.seq++
	p := Payload{
		Proto: 1, Type: eventType, Node: c.node,
		Key: Fingerprint(c.priv.Public().(ed25519.PublicKey)), Seq: c.seq, TS: receivedMS,
		MonoMS: monoMS, Boot: boot, Run: strings.Repeat("cd", 16), Status: StatusOK,
		Prev: c.head, AgentVersion: "0.1.0", AgentCommit: "unknown", AgentBuild: "unknown",
		AgentSHA256: strings.Repeat("e", 64),
	}
	canonical, err := p.Canonical()
	if err != nil {
		c.t.Fatal(err)
	}
	ph := PayloadHash(canonical)
	eh, _ := EventHash(c.head, ph)
	e := ProofEvent{
		ID: c.nextID, ReceivedAt: receivedMS, Payload: string(canonical),
		Signature:   base64.StdEncoding.EncodeToString(Sign(c.priv, canonical)),
		PayloadHash: ph, PrevHash: c.head, EventHash: eh,
	}
	c.nextID++
	c.head = eh
	c.events = append(c.events, e)

	return e
}

var params = Params{IntervalMS: 30_000, TimeoutMS: 90_000, ToleranceMS: 30_000, SkewMS: 120_000}

const t0 = int64(1_790_000_000_000)

var bootA, bootB = strings.Repeat("a1", 16), strings.Repeat("b2", 16)

// scenario: heartbeats every 30 s for 10 min, a 5-minute silence, a reboot, more
// heartbeats. Range = [t0, t0 + 30 min).
func scenario(t *testing.T) (*chain, *Proof) {
	c := newChain(t, testKey(7))
	at := t0
	mono := int64(3_600_000)
	c.add(TypeBoot, at, mono, bootA)
	for i := 0; i < 20; i++ {
		at += 30_000
		mono += 30_000
		c.add(TypeHeartbeat, at, mono, bootA)
	}
	// Silence of 5 minutes, then the agent is back (no reboot): no-heartbeat outage.
	at += 5 * minute
	mono += 5 * minute
	c.add(TypeHeartbeat, at, mono, bootA)
	// Graceful stop, reboot; the machine booted 40 s before the boot event.
	at += 10_000
	c.add(TypeStop, at, mono+10_000, bootA)
	at += 70_000
	c.add(TypeBoot, at, 40_000, bootB)
	for i := 0; i < 10; i++ {
		at += 30_000
		c.add(TypeHeartbeat, at, 40_000+int64(i+1)*30_000, bootB)
	}

	pub := testKey(7).Public().(ed25519.PublicKey)
	proof := &Proof{
		Format:      ProofFormat,
		GeneratedAt: t0 + 30*minute,
		Collector:   CollectorInfo{Software: "UpTime-Server collector (test)", Version: "0.1.0", Commit: "unknown"},
		Node:        ProofNode{ID: c.node, Name: "Test node", MonitoringStartedAt: t0, Params: params},
		Keys:        []ProofKey{{Fingerprint: Fingerprint(pub), PublicKey: base64.StdEncoding.EncodeToString(pub), Algorithm: "ed25519", RegisteredAt: t0 - minute}},
		Range:       ProofRange{From: t0, To: t0 + 30*minute},
		Events:      c.events,
		Page:        ProofPage{Complete: true},
	}
	points := make([]Point, 0, len(c.events))
	for _, e := range c.events {
		p, _ := ParsePayload([]byte(e.Payload))
		points = append(points, Point{e.ReceivedAt, p.Type, p.MonoMS})
	}
	outages := Outages(points, params, proof.Range.To, true)
	w := Calculate(outages, t0, proof.Range.From, proof.Range.To)
	proof.Summary = ProofSummary{Events: int64(len(c.events)), CoveredMS: w.CoveredMS, DowntimeMS: w.DowntimeMS, Outages: outages}

	return c, proof
}

func clone(p *Proof) *Proof {
	data, _ := json.Marshal(p)
	var out Proof
	_ = json.Unmarshal(data, &out)

	return &out
}

func TestValidChainPasses(t *testing.T) {
	_, proof := scenario(t)
	r := VerifyProof(proof)
	if !r.Valid {
		t.Fatalf("valid chain rejected: %+v", r.FirstFailure)
	}
	if !r.FromGenesis || !r.SummaryChecked || r.EventsChecked != len(proof.Events) {
		t.Fatalf("unexpected report %+v", r)
	}
}

func TestTamperingIsDetectedAtTheFirstBrokenEvent(t *testing.T) {
	_, proof := scenario(t)
	cases := map[string]struct {
		mutate func(p *Proof)
		id     int64
		want   string
	}{
		"modified payload": {func(p *Proof) {
			p.Events[5].Payload = strings.Replace(p.Events[5].Payload, `"status":"ok"`, `"status":"degraded"`, 1)
		}, 6, "invalid signature"},
		"deleted middle event": {func(p *Proof) {
			p.Events = append(p.Events[:7:7], p.Events[8:]...)
			p.Summary.Events--
		}, 9, "chain broken"},
		"changed previous hash": {func(p *Proof) { p.Events[3].PrevHash = strings.Repeat("1", 64) }, 4, "previous hash"},
		"changed payload hash":  {func(p *Proof) { p.Events[2].PayloadHash = strings.Repeat("2", 64) }, 3, "payload hash"},
		"changed event hash":    {func(p *Proof) { p.Events[2].EventHash = strings.Repeat("3", 64) }, 3, "event hash mismatch"},
		"reordered events": {func(p *Proof) {
			p.Events[4], p.Events[5] = p.Events[5], p.Events[4]
		}, 6, "chain broken"},
		"wrong node": {func(p *Proof) { p.Node.ID = "nd-other" }, 1, "different node"},
		"unknown key": {func(p *Proof) {
			pub := testKey(9).Public().(ed25519.PublicKey)
			p.Keys = []ProofKey{{Fingerprint: Fingerprint(pub), PublicKey: base64.StdEncoding.EncodeToString(pub), Algorithm: "ed25519"}}
		}, 1, "unknown key"},
		"key revoked before event": {func(p *Proof) { r := t0 + minute; p.Keys[0].RevokedAt = &r }, 4, "not valid"},
		"changed receipt time":     {func(p *Proof) { p.Events[10].ReceivedAt += 10 * minute }, 11, "outside the accepted window"},
		"inflated uptime":          {func(p *Proof) { p.Summary.DowntimeMS = 0 }, 0, "summary does not match"},
	}
	for name, tc := range cases {
		p := clone(proof)
		tc.mutate(p)
		r := VerifyProof(p)
		if r.Valid {
			t.Errorf("%s: tampered proof accepted", name)
			continue
		}
		if r.FirstFailure.EventID != tc.id || !strings.Contains(r.FirstFailure.Problem, tc.want) {
			t.Errorf("%s: got failure at %d %q, want %d containing %q", name, r.FirstFailure.EventID, r.FirstFailure.Problem, tc.id, tc.want)
		}
	}
}

func TestReplayedOrDuplicateSequenceIsRejected(t *testing.T) {
	c, proof := scenario(t)
	// An agent that signs the same sequence twice (replay/equivocation) breaks the
	// sequence rule even when the chain links are recomputed.
	c.seq = 5
	c.add(TypeHeartbeat, proof.Range.To-1000, 400_000, bootB)
	p := clone(proof)
	p.Events = c.events
	p.Summary.Events = int64(len(c.events))
	r := VerifyProof(p)
	if r.Valid || !strings.Contains(r.FirstFailure.Problem, "sequence") {
		t.Fatalf("duplicate sequence not detected: %+v", r.FirstFailure)
	}
}

func TestRangeProofWithAnchorAndTail(t *testing.T) {
	_, full := scenario(t)
	p := clone(full)
	p.Range = ProofRange{From: full.Events[10].ReceivedAt, To: full.Events[25].ReceivedAt}
	anchor := full.Events[9]
	tail := full.Events[25]
	p.Anchor, p.Tail = &anchor, &tail
	p.Events = full.Events[10:25]
	var points []Point
	for _, e := range full.Events[9:26] {
		pl, _ := ParsePayload([]byte(e.Payload))
		points = append(points, Point{e.ReceivedAt, pl.Type, pl.MonoMS})
	}
	w := Calculate(Outages(points, params, p.Range.To, false), t0, p.Range.From, p.Range.To)
	p.Summary = ProofSummary{Events: 15, CoveredMS: w.CoveredMS, DowntimeMS: w.DowntimeMS}
	r := VerifyProof(p)
	if !r.Valid || r.FromGenesis {
		t.Fatalf("range proof rejected: %+v", r.FirstFailure)
	}
	// Without the anchor the first event does not link to genesis.
	p.Anchor = nil
	if VerifyProof(p).Valid {
		t.Fatal("partial range without anchor accepted")
	}
}

func TestUptimeFormula(t *testing.T) {
	_, proof := scenario(t)
	s := proof.Summary
	// Outage 1: 5-minute silence after a heartbeat at t0+600s:
	//   [t0+600s+120s, t0+600s+300s) = 180 s.
	// Outage 2: reboot. Stop at t0+910s, boot event at t0+980s with mono 40 s, so the
	//   machine booted at t0+940s: [t0+910s, t0+940s) = 30 s (gap 70 s < 120 s grace).
	// Outage 3: silence after the last heartbeat (t0+1280s) until the range end
	//   (t0+1800s): [t0+1400s, t0+1800s) = 400 s.
	if len(s.Outages) != 3 {
		t.Fatalf("expected 3 outages, got %+v", s.Outages)
	}
	if s.Outages[0].DurationMS() != 180_000 || s.Outages[0].Cause != CauseNoHeartbeat {
		t.Errorf("silence outage %+v", s.Outages[0])
	}
	if s.Outages[1].DurationMS() != 30_000 || s.Outages[1].Cause != CauseReboot {
		t.Errorf("reboot outage %+v", s.Outages[1])
	}
	if s.Outages[2].DurationMS() != 400_000 {
		t.Errorf("open outage %+v", s.Outages[2])
	}
	if s.DowntimeMS != 610_000 || s.CoveredMS != 30*minute {
		t.Fatalf("downtime %d covered %d", s.DowntimeMS, s.CoveredMS)
	}
}

func TestMonitoringStartAndNoData(t *testing.T) {
	// Nothing before monitoring began is counted, up or down.
	w := Calculate(nil, t0, t0-24*60*minute, t0+60*minute)
	if w.CoveredMS != 60*minute || *w.UptimePercent != 100 || w.CoveragePercent >= 5 {
		t.Fatalf("pre-monitoring gap counted: %+v", w)
	}
	// No monitoring yet: no data, never 100%.
	if w := Calculate(nil, 0, t0, t0+minute); w.UptimePercent != nil || w.CoveredMS != 0 {
		t.Fatalf("no-data window reported uptime: %+v", w)
	}
	// Window entirely before monitoring began.
	if w := Calculate(nil, t0, t0-2*minute, t0-minute); w.UptimePercent != nil {
		t.Fatalf("window before monitoring reported uptime: %+v", w)
	}
	// Outage partly outside the window is clipped.
	w = Calculate([]Interval{{t0 - minute, t0 + minute, CauseNoHeartbeat}}, t0-10*minute, t0, t0+10*minute)
	if w.DowntimeMS != minute || *w.UptimePercent != 90 {
		t.Fatalf("clipping wrong: %+v", w)
	}
}

func TestRebootIsNeverContinuousUptime(t *testing.T) {
	// The agent was back only 20 s after the last heartbeat, well within the grace
	// period, but the boot event shows the machine booted 15 s ago: 5 s of confirmed
	// downtime instead of none.
	prev := Point{ReceivedMS: t0, Type: TypeHeartbeat, MonoMS: 1_000_000}
	next := Point{ReceivedMS: t0 + 20_000, Type: TypeBoot, MonoMS: 15_000}
	got := GapOutages(prev, next, params)
	if len(got) != 1 || got[0] != (Interval{t0, t0 + 5_000, CauseReboot}) {
		t.Fatalf("reboot outage %+v", got)
	}
	// A boot event that claims more uptime than the gap allows adds no reboot downtime.
	next.MonoMS = 60_000
	if got := GapOutages(prev, next, params); len(got) != 0 {
		t.Fatalf("unexpected outage %+v", got)
	}
	// Long reboot: reboot and no-heartbeat outages merge into one.
	next = Point{ReceivedMS: t0 + 10*minute, Type: TypeBoot, MonoMS: 30_000}
	got = GapOutages(prev, next, params)
	if len(got) != 1 || got[0].StartMS != t0 || got[0].EndMS != t0+10*minute {
		t.Fatalf("merged outage %+v", got)
	}
}

func TestMonotonicAnomaliesAreReported(t *testing.T) {
	c := newChain(t, testKey(7))
	c.add(TypeBoot, t0, 500_000, bootA)
	c.add(TypeHeartbeat, t0+30_000, 400_000, bootA) // uptime went down, same boot
	c.add(TypeHeartbeat, t0+60_000, 10_000, bootB)  // new boot without a boot event
	pub := testKey(7).Public().(ed25519.PublicKey)
	p := &Proof{Format: ProofFormat, Node: ProofNode{ID: c.node, MonitoringStartedAt: t0, Params: params},
		Keys:  []ProofKey{{Fingerprint: Fingerprint(pub), PublicKey: base64.StdEncoding.EncodeToString(pub), Algorithm: "ed25519"}},
		Range: ProofRange{From: t0, To: t0 + 61_000}, Events: c.events, Page: ProofPage{Complete: false}}
	r := VerifyProof(p)
	if !r.Valid || len(r.Warnings) < 3 {
		t.Fatalf("anomalies not reported: %+v", r)
	}
	joined := strings.Join(r.Warnings, "|")
	if !strings.Contains(joined, "uptime decreased") || !strings.Contains(joined, "without a boot event") {
		t.Fatalf("warnings %v", r.Warnings)
	}
}

// TestVectors writes (with -update) or checks testdata/vectors/protocol-v1.json,
// which the PHP collector tests verify too, so both implementations agree.
func TestVectors(t *testing.T) {
	_, proof := scenario(t)
	priv := testKey(7)
	single := samplePayload(priv)
	canonical, _ := single.Canonical()
	ph := PayloadHash(canonical)
	eh, _ := EventHash(single.Prev, ph)
	vectors := map[string]any{
		"description": "Public UpTime-Server protocol v1 test vectors (test key only; contains no secrets).",
		"single": map[string]any{
			"public_key":   base64.StdEncoding.EncodeToString(priv.Public().(ed25519.PublicKey)),
			"fingerprint":  Fingerprint(priv.Public().(ed25519.PublicKey)),
			"payload":      string(canonical),
			"signature":    base64.StdEncoding.EncodeToString(Sign(priv, canonical)),
			"payload_hash": ph,
			"event_hash":   eh,
		},
		"proof": proof,
	}
	data, _ := json.MarshalIndent(vectors, "", "  ")
	data = append(data, '\n')
	path := filepath.Join("..", "testdata", "vectors", "protocol-v1.json")
	if *update {
		if err := os.WriteFile(path, data, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	existing, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("missing vectors (run go test ./protocol -update): %v", err)
	}
	if string(existing) != string(data) {
		t.Fatal("testdata/vectors/protocol-v1.json is out of date (run go test ./protocol -update)")
	}
}
