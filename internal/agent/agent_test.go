package agent

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/LtAkmal/UpTime-Server/protocol"
)

const testToken = "test-token-0123456789abcdef"

// fakeCollector implements the agent API with the same acceptance rules as the real
// collector (signature, idempotency, sequence, chain head).
type fakeCollector struct {
	mu            sync.Mutex
	pub           ed25519.PublicKey
	tokenUsed     bool
	head          string
	lastSeq       int64
	accepted      []protocol.Payload
	hashes        []string
	unavailable   int // respond 503 to this many event requests
	dropReply     int // accept, but reply 500, this many times
	failAfterDrop int // after a dropped reply, answer 503 this many times
	attempts      int
}

func (f *fakeCollector) handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /api/uptime/agent/enroll", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		var req EnrollRequest
		_ = json.NewDecoder(r.Body).Decode(&req)
		if req.Token != testToken || f.tokenUsed {
			respond(w, 403, map[string]any{"error": "invalid_token"})
			return
		}
		raw, _ := base64.StdEncoding.DecodeString(req.PublicKey)
		f.pub, f.tokenUsed = raw, true
		f.lastSeq = 0
		respond(w, 201, EnrollResponse{NodeID: "nd-test-01", Fingerprint: protocol.Fingerprint(raw), Head: f.head, IntervalS: 30})
	})
	mux.HandleFunc("GET /api/uptime/agent/nodes/nd-test-01/head", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		respond(w, 200, HeadResponse{Head: f.head, LastSeq: f.lastSeq})
	})
	mux.HandleFunc("POST /api/uptime/agent/events", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.attempts++
		if f.unavailable > 0 {
			f.unavailable--
			respond(w, 503, map[string]any{"error": "unavailable"})
			return
		}
		var req EventRequest
		_ = json.NewDecoder(r.Body).Decode(&req)
		p, err := protocol.ParsePayload([]byte(req.Payload))
		sig, _ := base64.StdEncoding.DecodeString(req.Signature)
		if err != nil || !protocol.VerifySignature(f.pub, []byte(req.Payload), sig) {
			respond(w, 403, map[string]any{"error": "invalid_signature"})
			return
		}
		hash, _ := protocol.EventHash(p.Prev, protocol.PayloadHash([]byte(req.Payload)))
		if len(f.hashes) > 0 && hash == f.hashes[len(f.hashes)-1] {
			respond(w, 200, EventResponse{Status: "duplicate", EventHash: hash, Seq: p.Seq})
			return
		}
		if p.Seq <= f.lastSeq {
			respond(w, 409, map[string]any{"error": "stale_sequence", "last_seq": f.lastSeq})
			return
		}
		if p.Prev != f.head {
			respond(w, 409, map[string]any{"error": "prev_mismatch", "head": f.head, "last_seq": f.lastSeq})
			return
		}
		f.head, f.lastSeq = hash, p.Seq
		f.accepted = append(f.accepted, p)
		f.hashes = append(f.hashes, hash)
		if f.dropReply > 0 {
			f.dropReply--
			f.unavailable = f.failAfterDrop
			respond(w, 500, map[string]any{"error": "reply lost"})
			return
		}
		respond(w, 201, EventResponse{Status: "accepted", EventHash: hash, Seq: p.Seq})
	})

	return mux
}

func respond(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

type harness struct {
	t      *testing.T
	fake   *fakeCollector
	server *httptest.Server
	cfg    *Config
	dir    string
	logs   *bytes.Buffer
	clock  time.Time
	bootID string
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	fake := &fakeCollector{head: protocol.GenesisHash}
	server := httptest.NewServer(fake.handler())
	t.Cleanup(server.Close)
	dir := t.TempDir()
	stateDir := filepath.Join(dir, "state")
	h := &harness{t: t, fake: fake, server: server, dir: dir, logs: &bytes.Buffer{}, clock: time.UnixMilli(1_790_000_000_000), bootID: "11111111-2222-3333-4444-555555555555"}
	h.cfg = &Config{CollectorURL: server.URL, StateDir: stateDir, Interval: 30 * time.Second, RequestTimeout: 5 * time.Second, AllowInsecureHTTP: true}
	h.writeProc(3_600_000)

	return h
}

func (h *harness) writeProc(uptimeMS int64) {
	_ = os.WriteFile(filepath.Join(h.dir, "boot_id"), []byte(h.bootID+"\n"), 0o644)
	_ = os.WriteFile(filepath.Join(h.dir, "uptime"), []byte(fmt.Sprintf("%.2f 100.00\n", float64(uptimeMS)/1000)), 0o644)
}

func (h *harness) agent() *Agent {
	h.t.Helper()
	a, err := New(h.cfg, log.New(h.logs, "", 0))
	if err != nil {
		h.t.Fatal(err)
	}
	a.Sys = SystemInfo{BootIDPath: filepath.Join(h.dir, "boot_id"), UptimePath: filepath.Join(h.dir, "uptime")}
	a.Now = func() time.Time { return h.clock }
	a.MaxPendingAge = 60 * time.Second

	return a
}

func (h *harness) enroll() {
	h.t.Helper()
	if _, err := Enroll(h.cfg, testToken, false); err != nil {
		h.t.Fatal(err)
	}
}

// run runs the agent until it has slept `beats` times.
func (h *harness) run(a *Agent, beats int) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	n := 0
	a.Sleep = func(c context.Context, d time.Duration) bool {
		h.clock = h.clock.Add(d)
		if d == h.cfg.Interval {
			n++
			if n >= beats {
				cancel()
				return false
			}
		}
		return c.Err() == nil
	}
	if err := a.Run(ctx); err != nil {
		h.t.Fatal(err)
	}
}

func types(ps []protocol.Payload) string {
	var out []string
	for _, p := range ps {
		out = append(out, p.Type)
	}
	return strings.Join(out, ",")
}

func TestEnrollGeneratesAPrivateKeyOnlyTheOwnerCanRead(t *testing.T) {
	h := newHarness(t)
	h.enroll()
	st, err := os.Stat(filepath.Join(h.cfg.StateDir, keyFile))
	if err != nil {
		t.Fatal(err)
	}
	if st.Mode().Perm() != 0o600 {
		t.Fatalf("key mode %v, want 0600", st.Mode().Perm())
	}
	dir, _ := os.Stat(h.cfg.StateDir)
	if dir.Mode().Perm() != 0o700 {
		t.Fatalf("state dir mode %v, want 0700", dir.Mode().Perm())
	}
	// The collector only ever saw the public key.
	key, _ := LoadKey(h.cfg.StateDir)
	if !bytes.Equal(h.fake.pub, key.Public().(ed25519.PublicKey)) {
		t.Fatal("collector registered a different public key")
	}
	// The token is one-time.
	if _, err := Enroll(h.cfg, testToken, false); err == nil {
		t.Fatal("token accepted twice")
	}
	// A key readable by others is refused.
	_ = os.Chmod(filepath.Join(h.cfg.StateDir, keyFile), 0o640)
	if _, err := LoadKey(h.cfg.StateDir); err == nil {
		t.Fatal("key with loose permissions accepted")
	}
}

func TestRunSendsSignedChainedEventsBootHeartbeatsAndStop(t *testing.T) {
	h := newHarness(t)
	h.enroll()
	h.run(h.agent(), 3)

	if got := types(h.fake.accepted); got != "boot,heartbeat,heartbeat,stop" {
		t.Fatalf("events %s", got)
	}
	prev := protocol.GenesisHash
	for i, p := range h.fake.accepted {
		if p.Prev != prev || p.Seq != int64(i+1) {
			t.Fatalf("event %d not chained: prev %s seq %d", i, p.Prev, p.Seq)
		}
		prev = h.fake.hashes[i]
		if p.AgentSHA256 == "" || p.AgentVersion == "" || p.MonoMS != 3_600_000 {
			t.Fatalf("metadata missing in %+v", p)
		}
	}
	if last := h.fake.accepted[3]; last.Status != protocol.StatusStopping {
		t.Fatalf("stop status %s", last.Status)
	}
}

func TestRestartIsStartAndRebootIsBoot(t *testing.T) {
	h := newHarness(t)
	h.enroll()
	h.run(h.agent(), 1)
	h.run(h.agent(), 1) // same boot id: agent restart
	h.bootID = "99999999-2222-3333-4444-555555555555"
	h.writeProc(20_000)
	h.run(h.agent(), 1) // new boot id: reboot

	if got := types(h.fake.accepted); got != "boot,stop,start,stop,boot,stop" {
		t.Fatalf("events %s", got)
	}
	if h.fake.accepted[4].Boot == h.fake.accepted[2].Boot || h.fake.accepted[4].MonoMS != 20_000 {
		t.Fatal("reboot not reported with a new boot session and uptime")
	}
	if h.fake.accepted[2].Run == h.fake.accepted[0].Run {
		t.Fatal("run id reused across agent processes")
	}
}

func TestLostReplyIsRecoveredFromTheChainHead(t *testing.T) {
	h := newHarness(t)
	h.enroll()
	a := h.agent()
	a.Sleep = func(context.Context, time.Duration) bool { h.clock = h.clock.Add(30 * time.Second); return true }
	if err := a.Send(context.Background(), protocol.TypeBoot); err != nil {
		t.Fatal(err)
	}
	// A lost reply is recovered by resending the same signed bytes (idempotent).
	h.fake.dropReply = 1
	if err := a.Send(context.Background(), protocol.TypeHeartbeat); err != nil {
		t.Fatal(err)
	}
	if len(h.fake.accepted) != 2 {
		t.Fatalf("duplicate stored: %s", types(h.fake.accepted))
	}
	// Accepted, reply lost, then the collector is unreachable until the agent gives up.
	// The next event must follow the accepted one, not fork from the older head.
	h.fake.dropReply, h.fake.failAfterDrop = 1, 1000
	if err := a.Send(context.Background(), protocol.TypeHeartbeat); err == nil {
		t.Fatal("expected the lost reply to surface as an error")
	}
	h.fake.unavailable = 0
	if err := a.Send(context.Background(), protocol.TypeHeartbeat); err != nil {
		t.Fatal(err)
	}
	if len(h.fake.accepted) != 4 || h.fake.accepted[3].Prev != h.fake.hashes[2] {
		t.Fatalf("chain not continued after the lost reply: %s", types(h.fake.accepted))
	}
	if !strings.Contains(h.logs.String(), "re-signing event") {
		t.Fatal("re-signing was not logged")
	}
}

func TestForeignChainHeadIsNotFollowed(t *testing.T) {
	h := newHarness(t)
	h.enroll()
	a := h.agent()
	if err := a.Send(context.Background(), protocol.TypeBoot); err != nil {
		t.Fatal(err)
	}
	h.fake.head = strings.Repeat("f", 64) // e.g. a cloned agent with the same key
	err := a.Send(context.Background(), protocol.TypeHeartbeat)
	if err == nil || !strings.Contains(err.Error(), "not signed by this agent") {
		t.Fatalf("foreign head followed: %v", err)
	}
}

func TestCollectorFailureIsRetriedWithBoundedBackoff(t *testing.T) {
	h := newHarness(t)
	h.enroll()
	a := h.agent()
	a.Sleep = func(_ context.Context, d time.Duration) bool { h.clock = h.clock.Add(d); return true }

	h.fake.unavailable = 2
	if err := a.Send(context.Background(), protocol.TypeBoot); err != nil {
		t.Fatalf("transient failure not retried: %v", err)
	}
	if h.fake.attempts != 3 {
		t.Fatalf("attempts %d, want 3", h.fake.attempts)
	}

	h.fake.unavailable = 1000
	h.fake.attempts = 0
	start := h.clock
	if err := a.Send(context.Background(), protocol.TypeHeartbeat); err == nil {
		t.Fatal("expected failure when the collector stays down")
	}
	if h.clock.Sub(start) > a.MaxPendingAge || h.fake.attempts > 10 {
		t.Fatalf("retry not bounded: %v elapsed, %d attempts", h.clock.Sub(start), h.fake.attempts)
	}
	// Recovery afterwards continues the chain (with a sequence gap, which is allowed).
	h.fake.unavailable = 0
	if err := a.Send(context.Background(), protocol.TypeHeartbeat); err != nil {
		t.Fatal(err)
	}
	if got := h.fake.accepted[1]; got.Seq != 3 || got.Prev != h.fake.hashes[0] {
		t.Fatalf("unexpected recovery event seq %d", got.Seq)
	}
}

func TestPrivateKeyAndTokenNeverAppearInLogsOrState(t *testing.T) {
	h := newHarness(t)
	h.enroll()
	h.run(h.agent(), 2)
	key, _ := LoadKey(h.cfg.StateDir)
	der, _ := x509.MarshalPKCS8PrivateKey(key)
	secrets := []string{
		base64.StdEncoding.EncodeToString(der),
		base64.StdEncoding.EncodeToString(key.Seed()),
		base64.StdEncoding.EncodeToString(key),
		"PRIVATE KEY",
		testToken,
	}
	files := []string{stateFile, enrollmentFile}
	for _, s := range secrets {
		if strings.Contains(h.logs.String(), s) {
			t.Fatalf("log contains secret material %q", s[:8])
		}
		for _, f := range files {
			data, _ := os.ReadFile(filepath.Join(h.cfg.StateDir, f))
			if strings.Contains(string(data), s) {
				t.Fatalf("%s contains secret material", f)
			}
		}
	}
	for _, p := range h.fake.accepted {
		if strings.Contains(p.Node+p.AgentVersion, h.server.URL) {
			t.Fatal("payload contains the collector address")
		}
	}
}

func TestConfigValidation(t *testing.T) {
	dir := t.TempDir()
	write := func(body string, mode os.FileMode) string {
		p := filepath.Join(dir, "agent.conf")
		_ = os.WriteFile(p, []byte(body), mode)
		_ = os.Chmod(p, mode)
		return p
	}
	if _, err := LoadConfig(write("collector_url = http://collector.invalid\n", 0o640)); err == nil || !strings.Contains(err.Error(), "allow_insecure_http") {
		t.Fatalf("plain http accepted without opt-in: %v", err)
	}
	cfg, err := LoadConfig(write("# dev\ncollector_url = http://collector.invalid/\nallow_insecure_http = true\ninterval = 15s\ncheck_tcp = wings 127.0.0.1:8080\ncheck_disk = / 95\n", 0o640))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.CollectorURL != "http://collector.invalid" || cfg.Interval != 15*time.Second || len(cfg.TCPChecks) != 1 || len(cfg.DiskChecks) != 1 {
		t.Fatalf("unexpected config %+v", cfg)
	}
	if len(cfg.InsecureWarnings()) == 0 {
		t.Fatal("no insecure-development warning")
	}
	for _, bad := range []string{
		"collector_url = https://user:pass@collector.invalid\n",
		"collector_url = https://collector.invalid\ninterval = 1s\n",
		"collector_url = https://collector.invalid\nprivate_key = x\n",
		"collector_url = https://collector.invalid\nstate_dir = relative\n",
	} {
		if _, err := LoadConfig(write(bad, 0o640)); err == nil {
			t.Errorf("invalid config accepted: %q", bad)
		}
	}
	if _, err := LoadConfig(write("collector_url = https://collector.invalid\n", 0o666)); err == nil {
		t.Fatal("world-writable config accepted")
	}
}

func TestSystemInfo(t *testing.T) {
	dir := t.TempDir()
	_ = os.WriteFile(filepath.Join(dir, "uptime"), []byte("12345.67 999.00\n"), 0o644)
	_ = os.WriteFile(filepath.Join(dir, "boot_id"), []byte("abcd\n"), 0o644)
	s := SystemInfo{BootIDPath: filepath.Join(dir, "boot_id"), UptimePath: filepath.Join(dir, "uptime")}
	if ms, ok := s.MonotonicMS(); !ok || ms != 12_345_670 {
		t.Fatalf("uptime %d %v", ms, ok)
	}
	b1, ok := s.BootSession()
	if !ok || len(b1) != 32 || strings.Contains(b1, "abcd") {
		t.Fatalf("boot session %q", b1)
	}
	missing := SystemInfo{BootIDPath: filepath.Join(dir, "none"), UptimePath: filepath.Join(dir, "none")}
	if _, ok := missing.BootSession(); ok {
		t.Fatal("missing boot id reported as available")
	}
	if _, ok := missing.MonotonicMS(); ok {
		t.Fatal("missing uptime reported as available")
	}
}
