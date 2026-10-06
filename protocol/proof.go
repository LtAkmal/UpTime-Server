package protocol

import (
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
)

// ProofFormat identifies the proof document format.
const ProofFormat = "uptime-server-proof/v1"

// Proof is the public document a collector publishes for one node and time range.
// Everything needed to check it is inside: public keys, signed payloads, hashes and
// receipt times. See docs/verification.md.
type Proof struct {
	Format      string        `json:"format"`
	GeneratedAt int64         `json:"generated_at"`
	Collector   CollectorInfo `json:"collector"`
	Node        ProofNode     `json:"node"`
	Keys        []ProofKey    `json:"keys"`
	Range       ProofRange    `json:"range"`
	Anchor      *ProofEvent   `json:"anchor"` // last event before the range (nil: none exists)
	Events      []ProofEvent  `json:"events"`
	Tail        *ProofEvent   `json:"tail"` // first event after the range (nil: none yet)
	Page        ProofPage     `json:"page"`
	Summary     ProofSummary  `json:"summary"`
}

// CollectorInfo is what the collector says about itself (self-reported).
type CollectorInfo struct {
	Software   string `json:"software"`
	Version    string `json:"version"`
	Commit     string `json:"commit"`
	Repository string `json:"repository"`
}

// ProofNode is the public description of the monitored node.
type ProofNode struct {
	ID                  string `json:"id"`
	Name                string `json:"name"`
	MonitoringStartedAt int64  `json:"monitoring_started_at"` // first accepted event (0: none)
	Params              Params `json:"params"`
}

// ProofKey is a registered agent public key.
type ProofKey struct {
	Fingerprint  string `json:"fingerprint"`
	PublicKey    string `json:"public_key"` // base64 raw 32 bytes
	Algorithm    string `json:"algorithm"`
	RegisteredAt int64  `json:"registered_at"`
	RevokedAt    *int64 `json:"revoked_at"` // inclusive: valid for receipts <= RevokedAt
}

// ProofRange is the requested window [from, to) in Unix ms.
type ProofRange struct {
	From int64 `json:"from"`
	To   int64 `json:"to"`
}

// ProofEvent is one stored event.
type ProofEvent struct {
	ID          int64  `json:"id"`
	ReceivedAt  int64  `json:"received_at"`
	Payload     string `json:"payload"`
	Signature   string `json:"signature"` // base64
	PayloadHash string `json:"payload_hash"`
	PrevHash    string `json:"prev_hash"`
	EventHash   string `json:"event_hash"`
}

// ProofPage describes paging: Complete is false when more events follow at Next.
type ProofPage struct {
	Complete bool   `json:"complete"`
	Next     string `json:"next"`
}

// ProofSummary is the collector's uptime result for the range, which the verifier
// recomputes from the events.
type ProofSummary struct {
	Events     int64      `json:"events"`
	CoveredMS  int64      `json:"covered_ms"`
	DowntimeMS int64      `json:"downtime_ms"`
	Outages    []Interval `json:"outages"`
}

// Finding is the first problem that makes a proof invalid.
type Finding struct {
	EventID int64  `json:"event_id"`
	Seq     int64  `json:"seq"`
	Problem string `json:"problem"`
}

// Report is the result of VerifyProof.
type Report struct {
	Valid            bool     `json:"valid"`
	EventsChecked    int      `json:"events_checked"`
	FirstFailure     *Finding `json:"first_failure,omitempty"`
	FromGenesis      bool     `json:"from_genesis"`
	SummaryChecked   bool     `json:"summary_checked"`
	Recomputed       Window   `json:"recomputed"`
	RecomputedEvents int64    `json:"recomputed_events"`
	Warnings         []string `json:"warnings,omitempty"`
}

type keyInfo struct {
	pub     ed25519.PublicKey
	from    int64
	revoked *int64
}

// VerifyEvent checks one event on its own: canonical form, schema, node id, known
// key valid at receipt time, signature, payload hash, prev link and event hash.
func VerifyEvent(e ProofEvent, nodeID string, keys map[string]keyInfo, skewMS int64) (Payload, error) {
	payload, err := ParsePayload([]byte(e.Payload))
	if err != nil {
		return Payload{}, fmt.Errorf("invalid payload: %w", err)
	}
	if payload.Node != nodeID {
		return payload, errors.New("payload belongs to a different node")
	}
	key, ok := keys[payload.Key]
	if !ok {
		return payload, errors.New("signed by an unknown key")
	}
	if e.ReceivedAt < key.from || (key.revoked != nil && e.ReceivedAt > *key.revoked) {
		return payload, errors.New("key was not valid at receipt time")
	}
	sig, err := base64.StdEncoding.DecodeString(e.Signature)
	if err != nil || !VerifySignature(key.pub, []byte(e.Payload), sig) {
		return payload, errors.New("invalid signature")
	}
	if PayloadHash([]byte(e.Payload)) != e.PayloadHash {
		return payload, errors.New("payload hash mismatch")
	}
	if payload.Prev != e.PrevHash {
		return payload, errors.New("stored previous hash differs from the signed previous hash")
	}
	h, err := EventHash(e.PrevHash, e.PayloadHash)
	if err != nil || h != e.EventHash {
		return payload, errors.New("event hash mismatch")
	}
	if skewMS > 0 && abs(payload.TS-e.ReceivedAt) > skewMS {
		return payload, errors.New("agent timestamp outside the accepted window of the receipt time")
	}

	return payload, nil
}

// VerifyProof checks every event and the chain links of a (merged) proof, then
// recomputes the uptime summary. It never trusts the collector's hashes or results.
func VerifyProof(p *Proof) Report {
	r := Report{}
	fail := func(id, seq int64, problem string) Report {
		r.Valid = false
		r.FirstFailure = &Finding{EventID: id, Seq: seq, Problem: problem}

		return r
	}
	if p.Format != ProofFormat {
		return fail(0, 0, "unsupported proof format")
	}

	keys := map[string]keyInfo{}
	for _, k := range p.Keys {
		raw, err := base64.StdEncoding.DecodeString(k.PublicKey)
		if err != nil || len(raw) != ed25519.PublicKeySize || k.Algorithm != "ed25519" {
			return fail(0, 0, "invalid public key "+k.Fingerprint)
		}
		if Fingerprint(raw) != k.Fingerprint {
			return fail(0, 0, "public key does not match its fingerprint "+k.Fingerprint)
		}
		keys[k.Fingerprint] = keyInfo{pub: raw, from: k.RegisteredAt, revoked: k.RevokedAt}
	}

	skew := p.Node.Params.SkewMS
	lastSeq := map[string]int64{}
	var points []Point
	var prevReceived int64
	var lastBoot string
	var lastMono int64

	expectPrev := GenesisHash
	if p.Anchor != nil {
		payload, err := VerifyEvent(*p.Anchor, p.Node.ID, keys, skew)
		if err != nil {
			return fail(p.Anchor.ID, payload.Seq, "anchor: "+err.Error())
		}
		if p.Anchor.ReceivedAt >= p.Range.From {
			return fail(p.Anchor.ID, payload.Seq, "anchor is not before the range")
		}
		expectPrev = p.Anchor.EventHash
		lastSeq[payload.Key] = payload.Seq
		prevReceived = p.Anchor.ReceivedAt
		lastBoot, lastMono = payload.Boot, payload.MonoMS
		points = append(points, Point{p.Anchor.ReceivedAt, payload.Type, payload.MonoMS})
	} else {
		r.FromGenesis = true
	}

	check := func(e ProofEvent, inRange bool) *Report {
		payload, err := VerifyEvent(e, p.Node.ID, keys, skew)
		if err != nil {
			rep := fail(e.ID, payload.Seq, err.Error())
			return &rep
		}
		if e.PrevHash != expectPrev {
			rep := fail(e.ID, payload.Seq, "chain broken: previous hash does not match the preceding event (an event is missing, reordered or modified)")
			return &rep
		}
		if s, ok := lastSeq[payload.Key]; ok && payload.Seq <= s {
			rep := fail(e.ID, payload.Seq, "sequence number did not increase")
			return &rep
		}
		if e.ReceivedAt < prevReceived {
			rep := fail(e.ID, payload.Seq, "receipt time went backwards")
			return &rep
		}
		if inRange && (e.ReceivedAt < p.Range.From || e.ReceivedAt >= p.Range.To) {
			rep := fail(e.ID, payload.Seq, "event outside the requested range")
			return &rep
		}
		if s, ok := lastSeq[payload.Key]; ok && payload.Seq > s+1 {
			r.Warnings = append(r.Warnings, fmt.Sprintf("event %d: sequence gap (%d → %d); unsent heartbeats are allowed", e.ID, s, payload.Seq))
		}
		if lastBoot != "" {
			if payload.Boot == lastBoot && payload.MonoMS < lastMono {
				r.Warnings = append(r.Warnings, fmt.Sprintf("event %d: uptime decreased without a reboot", e.ID))
			}
			if payload.Boot != lastBoot && payload.Type != TypeBoot {
				r.Warnings = append(r.Warnings, fmt.Sprintf("event %d: new boot session without a boot event", e.ID))
			}
		}
		expectPrev = e.EventHash
		lastSeq[payload.Key] = payload.Seq
		prevReceived = e.ReceivedAt
		lastBoot, lastMono = payload.Boot, payload.MonoMS
		points = append(points, Point{e.ReceivedAt, payload.Type, payload.MonoMS})
		r.EventsChecked++

		return nil
	}

	for i, e := range p.Events {
		if i == 0 && p.Anchor == nil && p.Node.MonitoringStartedAt != 0 && e.ReceivedAt != p.Node.MonitoringStartedAt {
			return fail(e.ID, 0, "first event does not match the published monitoring start")
		}
		if rep := check(e, true); rep != nil {
			return *rep
		}
	}
	if p.Tail != nil {
		if p.Tail.ReceivedAt < p.Range.To {
			return fail(p.Tail.ID, 0, "tail is not after the range")
		}
		if rep := check(*p.Tail, false); rep != nil {
			return *rep
		}
	}

	r.Valid = true
	r.RecomputedEvents = int64(len(p.Events))
	if p.Page.Complete {
		outages := Outages(points, p.Node.Params, p.Range.To, p.Tail == nil)
		r.Recomputed = Calculate(outages, p.Node.MonitoringStartedAt, p.Range.From, p.Range.To)
		r.SummaryChecked = true
		if r.Recomputed.CoveredMS != p.Summary.CoveredMS || r.Recomputed.DowntimeMS != p.Summary.DowntimeMS || r.RecomputedEvents != p.Summary.Events {
			r.Valid = false
			r.FirstFailure = &Finding{Problem: fmt.Sprintf(
				"published summary does not match the events (published covered %d ms, downtime %d ms, %d events; recomputed %d ms, %d ms, %d events)",
				p.Summary.CoveredMS, p.Summary.DowntimeMS, p.Summary.Events, r.Recomputed.CoveredMS, r.Recomputed.DowntimeMS, r.RecomputedEvents)}
		}
	} else {
		r.Warnings = append(r.Warnings, "proof is incomplete (more pages exist): the uptime summary was not recomputed")
	}

	return r
}

func abs(v int64) int64 {
	if v < 0 {
		return -v
	}

	return v
}
