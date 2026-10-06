package protocol

import (
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"strings"
	"testing"
)

// testKey is a deterministic key for tests only (RFC 8032-style test seed).
func testKey(b byte) ed25519.PrivateKey {
	return ed25519.NewKeyFromSeed(bytes.Repeat([]byte{b}, 32))
}

func samplePayload(priv ed25519.PrivateKey) Payload {
	return Payload{
		Proto:        1,
		Type:         TypeHeartbeat,
		Node:         "nd-test-01",
		Key:          Fingerprint(priv.Public().(ed25519.PublicKey)),
		Seq:          42,
		TS:           1790000000000,
		MonoMS:       123456,
		Boot:         strings.Repeat("ab", 16),
		Run:          strings.Repeat("cd", 16),
		Status:       StatusOK,
		ChecksFailed: 0,
		Prev:         GenesisHash,
		AgentVersion: "0.1.0",
		AgentCommit:  "unknown",
		AgentBuild:   "unknown",
		AgentSHA256:  strings.Repeat("e", 64),
	}
}

func TestCanonicalEncodingIsSortedAndCompact(t *testing.T) {
	got, err := Encode(map[string]any{"b": "x", "a": int64(5), "c_1": 0})
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != `{"a":5,"b":"x","c_1":0}` {
		t.Fatalf("unexpected encoding %s", got)
	}
}

func TestCanonicalDecodeRejectsNonCanonicalInput(t *testing.T) {
	for _, in := range []string{
		`{"b":1,"a":2}`,              // unsorted
		`{"a":1, "b":2}`,             // whitespace
		`{"a":01}`,                   // leading zero
		`{"a":-1}`,                   // negative
		`{"a":1.5}`,                  // float
		`{"a":"x\"y"}`,               // escape
		`{"a":"é"}`,                  // escape sequence
		`{"a":{"b":1}}`,              // nested
		`{"a":1,"a":1}`,              // duplicate
		`{"a":1}x`,                   // trailing data
		`{"A":1}`,                    // upper-case key
		`{"a":9007199254740992}`,     // > 2^53-1
		`{}`,                         // empty
		"{\"a\":\"tab\there\"}",      // control character
		`{"a":"` + "\xc3\xa9" + `"}`, // non-ASCII
	} {
		if _, err := Decode([]byte(in)); err == nil {
			t.Errorf("accepted non-canonical input %q", in)
		}
	}
	if _, err := Decode([]byte(`{"a":9007199254740991,"b":"ok ~"}`)); err != nil {
		t.Fatalf("rejected canonical input: %v", err)
	}
}

func TestSignatureVerifiesAndDetectsAnyChange(t *testing.T) {
	priv := testKey(7)
	pub := priv.Public().(ed25519.PublicKey)
	p := samplePayload(priv)
	canonical, err := p.Canonical()
	if err != nil {
		t.Fatal(err)
	}
	sig := Sign(priv, canonical)
	if !VerifySignature(pub, canonical, sig) {
		t.Fatal("valid signature rejected")
	}

	for name, mutate := range map[string]func(*Payload){
		"timestamp": func(p *Payload) { p.TS++ },
		"sequence":  func(p *Payload) { p.Seq++ },
		"status":    func(p *Payload) { p.Status = StatusDegraded },
		"prev":      func(p *Payload) { p.Prev = strings.Repeat("1", 64) },
	} {
		changed := p
		mutate(&changed)
		other, _ := changed.Canonical()
		if VerifySignature(pub, other, sig) {
			t.Errorf("signature still valid after changing %s", name)
		}
	}
	if VerifySignature(testKey(8).Public().(ed25519.PublicKey), canonical, sig) {
		t.Fatal("signature accepted with the wrong public key")
	}
	// The context prefix is part of the message: a bare signature over the payload is invalid.
	if VerifySignature(pub, canonical, ed25519.Sign(priv, canonical)) {
		t.Fatal("signature without the protocol context accepted")
	}
}

func TestCanonicalisationAndFingerprintAreStable(t *testing.T) {
	priv := testKey(7)
	p := samplePayload(priv)
	a, _ := p.Canonical()
	b, _ := p.Canonical()
	if !bytes.Equal(a, b) {
		t.Fatal("canonical bytes differ between runs")
	}
	parsed, err := ParsePayload(a)
	if err != nil || parsed != p {
		t.Fatalf("round trip failed: %v", err)
	}
	// Ed25519 is deterministic: same key and payload, same signature.
	if !bytes.Equal(Sign(priv, a), Sign(priv, b)) {
		t.Fatal("signature not deterministic")
	}
	fp := Fingerprint(priv.Public().(ed25519.PublicKey))
	if fp != Fingerprint(priv.Public().(ed25519.PublicKey)) || len(fp) != 64 {
		t.Fatal("fingerprint unstable")
	}
}

func TestParsePayloadRejectsSchemaViolations(t *testing.T) {
	priv := testKey(7)
	base := samplePayload(priv)
	for name, mutate := range map[string]func(*Payload){
		"proto":   func(p *Payload) { p.Proto = 2 },
		"type":    func(p *Payload) { p.Type = "restart" },
		"node":    func(p *Payload) { p.Node = "Node 1" },
		"seq":     func(p *Payload) { p.Seq = 0 },
		"status":  func(p *Payload) { p.Status = "fine" },
		"key":     func(p *Payload) { p.Key = "abc" },
		"version": func(p *Payload) { p.AgentVersion = "1 0" },
	} {
		p := base
		mutate(&p)
		fields := p.Fields()
		data, err := Encode(fields)
		if err != nil {
			continue // not even encodable: fine
		}
		if _, err := ParsePayload(data); err == nil {
			t.Errorf("schema violation %s accepted", name)
		}
	}
	// Extra or missing fields are rejected.
	fields := base.Fields()
	fields["hostname"] = "secret-host"
	data, _ := Encode(fields)
	if _, err := ParsePayload(data); err == nil {
		t.Fatal("unknown field accepted")
	}
}

func TestEventHashCoversPrevAndPayload(t *testing.T) {
	ph := PayloadHash([]byte("x"))
	a, _ := EventHash(GenesisHash, ph)
	b, _ := EventHash(strings.Repeat("1", 64), ph)
	c, _ := EventHash(GenesisHash, PayloadHash([]byte("y")))
	if a == b || a == c {
		t.Fatal("event hash does not depend on both inputs")
	}
	if _, err := EventHash("zz", ph); err == nil {
		t.Fatal("invalid prev accepted")
	}
}

func TestPublicKeyEncodingMatchesFingerprintTool(t *testing.T) {
	pub := testKey(7).Public().(ed25519.PublicKey)
	raw, _ := base64.StdEncoding.DecodeString(base64.StdEncoding.EncodeToString(pub))
	if Fingerprint(raw) != Fingerprint(pub) {
		t.Fatal("base64 round trip changed the fingerprint")
	}
}
