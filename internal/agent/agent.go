package agent

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
	"log"
	"strings"
	"time"

	"github.com/LtAkmal/UpTime-Server/internal/buildinfo"
	"github.com/LtAkmal/UpTime-Server/protocol"
)

// Agent sends signed events for one enrolled node.
type Agent struct {
	Config *Config
	Client *Client
	Sys    SystemInfo
	Logger *log.Logger
	// Now and Sleep are replaceable for tests.
	Now   func() time.Time
	Sleep func(context.Context, time.Duration) bool

	key        ed25519.PrivateKey
	enrollment *Enrollment
	state      *State
	run        string
	sha256     string
	// MaxPendingAge bounds how long one signed event is retried before it is
	// discarded (it would fall outside the collector's timestamp window).
	MaxPendingAge time.Duration
}

// New loads the key, enrollment and state for cfg.
func New(cfg *Config, logger *log.Logger) (*Agent, error) {
	if err := ensureStateDir(cfg.StateDir); err != nil {
		return nil, err
	}
	key, err := LoadKey(cfg.StateDir)
	if err != nil {
		return nil, fmt.Errorf("agent key: %w (run \"uptime-agent enroll\" first)", err)
	}
	enrollment, err := loadEnrollment(cfg.StateDir)
	if err != nil {
		return nil, fmt.Errorf("enrollment: %w (run \"uptime-agent enroll\" first)", err)
	}
	if enrollment.Fingerprint != PublicFingerprint(key) {
		return nil, errors.New("the agent key does not match the enrolled key; enroll again")
	}
	state, err := loadState(cfg.StateDir)
	if err != nil {
		return nil, fmt.Errorf("state: %w", err)
	}
	client, err := NewClient(cfg)
	if err != nil {
		return nil, err
	}
	sum, err := buildinfo.ExecutableSHA256()
	if err != nil {
		return nil, fmt.Errorf("cannot hash the agent executable: %w", err)
	}

	return &Agent{
		Config:        cfg,
		Client:        client,
		Sys:           DefaultSystemInfo,
		Logger:        logger,
		Now:           time.Now,
		Sleep:         sleepContext,
		key:           key,
		enrollment:    enrollment,
		state:         state,
		run:           randomHex(16),
		sha256:        sum,
		MaxPendingAge: 60 * time.Second,
	}, nil
}

func sleepContext(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

// Run sends a boot or start event, then heartbeats every interval until ctx is
// cancelled, then a best-effort stop event.
func (a *Agent) Run(ctx context.Context) error {
	for _, w := range a.Config.InsecureWarnings() {
		a.Logger.Print(w)
	}
	a.Logger.Printf("uptime-agent %s (commit %s) for node %s, key %s", buildinfo.Version, buildinfo.Commit, a.enrollment.NodeID, short(a.enrollment.Fingerprint))

	if err := a.syncPosition(); err != nil {
		return err
	}
	boot, kernelBoot := a.Sys.BootSession()
	if !kernelBoot {
		a.Logger.Print("kernel boot id unavailable: every agent start is reported as a new boot session")
	}
	first := protocol.TypeStart
	if a.state.Boot != boot {
		first = protocol.TypeBoot
	}
	// An event signed by a previous run is never resent: its timestamp is stale.
	// Its hash stays in Recent, so a late acceptance is still recognised.
	if a.state.Pending != nil {
		a.state.Pending = nil
		if err := saveState(a.Config.StateDir, a.state); err != nil {
			return err
		}
	}

	eventType := first
	for {
		if err := a.Send(ctx, eventType); err != nil {
			if ctx.Err() != nil {
				break
			}
			a.Logger.Printf("event not delivered: %v", err)
		} else {
			eventType = protocol.TypeHeartbeat
		}
		if !a.Sleep(ctx, a.Config.Interval) {
			break
		}
	}

	stopCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := a.Send(stopCtx, protocol.TypeStop); err != nil {
		a.Logger.Printf("stop event not delivered: %v", err)
	}

	return nil
}

// syncPosition makes sure the state belongs to the current enrollment. After a new
// enrollment or a lost state file, the chain head and last sequence come from the
// collector's public head endpoint.
func (a *Agent) syncPosition() error {
	if a.state.NodeID == a.enrollment.NodeID && a.state.Key == a.enrollment.Fingerprint && a.state.Head != "" {
		return nil
	}
	head, err := a.Client.Head(a.enrollment.NodeID, a.enrollment.Fingerprint)
	if err != nil {
		return fmt.Errorf("cannot read the chain head from the collector: %w", err)
	}
	if head.Head != protocol.GenesisHash && len(head.Head) != 64 {
		return errors.New("collector returned an invalid chain head")
	}
	a.state = &State{NodeID: a.enrollment.NodeID, Key: a.enrollment.Fingerprint, Seq: head.LastSeq, Head: head.Head}
	a.state.remember(head.Head)
	a.Logger.Printf("chain position synchronised from the collector (sequence %d)", head.LastSeq)

	return saveState(a.Config.StateDir, a.state)
}

// Build creates, signs and persists the next event without sending it.
func (a *Agent) Build(eventType string) (*Pending, error) {
	boot, _ := a.Sys.BootSession()
	mono, _ := a.Sys.MonotonicMS()
	status := protocol.StatusOK
	failed := RunChecks(a.Config)
	if failed > 0 {
		status = protocol.StatusDegraded
		a.Logger.Printf("degraded: failed checks: %s", strings.Join(FailedCheckLabels(a.Config), ", "))
	}
	if eventType == protocol.TypeStop {
		status = protocol.StatusStopping
	}
	payload := protocol.Payload{
		Proto:        protocol.Version,
		Type:         eventType,
		Node:         a.enrollment.NodeID,
		Key:          a.enrollment.Fingerprint,
		Seq:          a.state.Seq + 1,
		TS:           a.Now().UnixMilli(),
		MonoMS:       mono,
		Boot:         boot,
		Run:          a.run,
		Status:       status,
		ChecksFailed: failed,
		Prev:         a.state.Head,
		AgentVersion: buildinfo.Version,
		AgentCommit:  buildinfo.Commit,
		AgentBuild:   buildinfo.BuildTime,
		AgentSHA256:  a.sha256,
	}
	canonical, err := payload.Canonical()
	if err != nil {
		return nil, fmt.Errorf("cannot build event: %w", err)
	}
	payloadHash := protocol.PayloadHash(canonical)
	eventHash, err := protocol.EventHash(payload.Prev, payloadHash)
	if err != nil {
		return nil, err
	}
	pending := &Pending{
		Payload:   string(canonical),
		Signature: base64.StdEncoding.EncodeToString(protocol.Sign(a.key, canonical)),
		EventHash: eventHash,
		CreatedMS: payload.TS,
	}
	// Persist before sending: a crash can never reuse this sequence number.
	a.state.Seq = payload.Seq
	a.state.Boot = boot
	a.state.Pending = pending
	a.state.remember(eventHash)
	if err := saveState(a.Config.StateDir, a.state); err != nil {
		return nil, fmt.Errorf("cannot persist state: %w", err)
	}

	return pending, nil
}

// Send builds and delivers one event, retrying transient failures with bounded
// backoff while the event is still fresh, and re-signing when the collector reports
// that the chain moved (e.g. an earlier event was accepted but the reply was lost).
func (a *Agent) Send(ctx context.Context, eventType string) error {
	for rebuild := 0; rebuild < 3; rebuild++ {
		pending, err := a.Build(eventType)
		if err != nil {
			return err
		}
		retry, err := a.deliver(ctx, pending)
		if !retry {
			return err
		}
		a.Logger.Printf("re-signing event: %v", err)
	}

	return errors.New("collector kept rejecting the chain position")
}

// deliver posts a pending event. retry=true means "rebuild and try again now".
func (a *Agent) deliver(ctx context.Context, p *Pending) (retry bool, err error) {
	backoff := time.Second
	for {
		resp, err := a.Client.SendEvent(EventRequest{Payload: p.Payload, Signature: p.Signature})
		if err == nil {
			if resp.EventHash != p.EventHash {
				return false, errors.New("collector confirmed a different event hash")
			}
			a.state.Head = p.EventHash
			a.state.Pending = nil
			a.state.Accepted = a.Now().UnixMilli()

			return false, saveState(a.Config.StateDir, a.state)
		}

		var apiErr *APIError
		if errors.As(err, &apiErr) {
			switch apiErr.Code {
			case "prev_mismatch":
				// Only follow a head this agent signed itself: a head it never signed
				// means another agent uses this key or the collector data is not ours.
				if !a.state.signed(apiErr.Head) {
					a.state.Pending = nil
					_ = saveState(a.Config.StateDir, a.state)
					return false, errors.New("collector chain head was not signed by this agent; check for a cloned agent key or rotate the key")
				}
				a.state.Head = apiErr.Head
				a.state.Seq = max(a.state.Seq, apiErr.LastSeq)
				a.state.Pending = nil
				if err := saveState(a.Config.StateDir, a.state); err != nil {
					return false, err
				}
				return true, apiErr
			case "stale_sequence":
				a.state.Seq = max(a.state.Seq, apiErr.LastSeq)
				a.state.Pending = nil
				if err := saveState(a.Config.StateDir, a.state); err != nil {
					return false, err
				}
				return true, apiErr
			}
			if !apiErr.Temporary() {
				a.state.Pending = nil
				_ = saveState(a.Config.StateDir, a.state)
				return false, apiErr
			}
		}

		// Network error, 429 or 5xx: retry the same signed bytes while still fresh.
		if time.Duration(a.Now().UnixMilli()-p.CreatedMS)*time.Millisecond+backoff > a.MaxPendingAge {
			a.state.Pending = nil
			_ = saveState(a.Config.StateDir, a.state)
			return false, fmt.Errorf("giving up on this event: %w", err)
		}
		if !a.Sleep(ctx, backoff) {
			return false, ctx.Err()
		}
		backoff = min(backoff*2, 15*time.Second)
	}
}

// Status describes the local agent state without secrets.
func (a *Agent) Status() string {
	accepted := "never"
	if a.state.Accepted > 0 {
		accepted = time.UnixMilli(a.state.Accepted).UTC().Format(time.RFC3339)
	}

	return fmt.Sprintf("node:              %s\nkey fingerprint:   %s\nsequence:          %d\nchain head:        %s\nlast accepted:     %s\n",
		a.enrollment.NodeID, a.enrollment.Fingerprint, a.state.Seq, a.state.Head, accepted)
}

func short(fp string) string {
	if len(fp) > 16 {
		return fp[:16] + "…"
	}

	return fp
}
