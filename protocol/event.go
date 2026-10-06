package protocol

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"regexp"
)

// Version is the protocol version carried in every payload ("proto").
const Version = 1

// SignatureContext is prefixed to the canonical payload before signing, so an
// UpTime-Server signature can never be mistaken for a signature over anything else.
const SignatureContext = "uptime-server/event/v1\n"

// GenesisHash is the "prev" of the first event of a node's chain.
const GenesisHash = "0000000000000000000000000000000000000000000000000000000000000000"

// Event types.
const (
	TypeBoot      = "boot"      // first event after the machine booted (new boot session)
	TypeStart     = "start"     // the agent (re)started within the same boot session
	TypeHeartbeat = "heartbeat" // periodic liveness proof
	TypeStop      = "stop"      // the agent is stopping gracefully
)

// Agent-reported status values.
const (
	StatusOK       = "ok"
	StatusDegraded = "degraded" // one or more configured local health checks failed
	StatusStopping = "stopping"
)

// Payload is the signed content of one event. Every field is covered by the
// signature; nothing here identifies the host beyond the public node id.
type Payload struct {
	Proto        int64  // protocol version (1)
	Type         string // boot | start | heartbeat | stop
	Node         string // public node id assigned by the collector
	Key          string // hex SHA-256 fingerprint of the signing public key
	Seq          int64  // strictly increasing per key; gaps are allowed
	TS           int64  // agent wall clock, Unix milliseconds (UTC)
	MonoMS       int64  // time since boot in milliseconds (Linux CLOCK_BOOTTIME via /proc/uptime)
	Boot         string // 32 hex: hash of the kernel boot id, changes on every reboot
	Run          string // 32 hex: random id of this agent process
	Status       string // ok | degraded | stopping
	ChecksFailed int64  // number of failed local health checks (no details)
	Prev         string // event hash of the previous event in this node's chain
	AgentVersion string
	AgentCommit  string
	AgentBuild   string
	AgentSHA256  string // SHA-256 of the running agent executable
}

var (
	hex64Pattern   = regexp.MustCompile(`^[0-9a-f]{64}$`)
	hex32Pattern   = regexp.MustCompile(`^[0-9a-f]{32}$`)
	nodePattern    = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{2,39}$`)
	versionPattern = regexp.MustCompile(`^[A-Za-z0-9._+-]{1,64}$`)
	commitPattern  = regexp.MustCompile(`^([0-9a-f]{7,40}|unknown)$`)
	buildPattern   = regexp.MustCompile(`^([0-9TZ:.+-]{10,40}|unknown)$`)
)

// Fields returns the payload as canonical fields.
func (p Payload) Fields() map[string]any {
	return map[string]any{
		"proto":         p.Proto,
		"type":          p.Type,
		"node":          p.Node,
		"key":           p.Key,
		"seq":           p.Seq,
		"ts":            p.TS,
		"mono_ms":       p.MonoMS,
		"boot":          p.Boot,
		"run":           p.Run,
		"status":        p.Status,
		"checks_failed": p.ChecksFailed,
		"prev":          p.Prev,
		"agent_version": p.AgentVersion,
		"agent_commit":  p.AgentCommit,
		"agent_build":   p.AgentBuild,
		"agent_sha256":  p.AgentSHA256,
	}
}

// Canonical validates the payload and returns its canonical bytes.
func (p Payload) Canonical() ([]byte, error) {
	if err := p.Validate(); err != nil {
		return nil, err
	}

	return Encode(p.Fields())
}

// Validate checks every field against the version 1 schema.
func (p Payload) Validate() error {
	switch {
	case p.Proto != Version:
		return errors.New("unsupported protocol version")
	case p.Type != TypeBoot && p.Type != TypeStart && p.Type != TypeHeartbeat && p.Type != TypeStop:
		return errors.New("invalid event type")
	case !nodePattern.MatchString(p.Node):
		return errors.New("invalid node id")
	case !hex64Pattern.MatchString(p.Key):
		return errors.New("invalid key fingerprint")
	case p.Seq < 1 || p.Seq > MaxSafeInteger:
		return errors.New("invalid sequence number")
	case p.TS < 1 || p.TS > MaxSafeInteger:
		return errors.New("invalid timestamp")
	case p.MonoMS < 0 || p.MonoMS > MaxSafeInteger:
		return errors.New("invalid monotonic uptime")
	case !hex32Pattern.MatchString(p.Boot):
		return errors.New("invalid boot session")
	case !hex32Pattern.MatchString(p.Run):
		return errors.New("invalid run id")
	case p.Status != StatusOK && p.Status != StatusDegraded && p.Status != StatusStopping:
		return errors.New("invalid status")
	case p.ChecksFailed < 0 || p.ChecksFailed > 64:
		return errors.New("invalid checks_failed")
	case !hex64Pattern.MatchString(p.Prev):
		return errors.New("invalid previous hash")
	case !versionPattern.MatchString(p.AgentVersion):
		return errors.New("invalid agent version")
	case !commitPattern.MatchString(p.AgentCommit):
		return errors.New("invalid agent commit")
	case !buildPattern.MatchString(p.AgentBuild):
		return errors.New("invalid agent build time")
	case !hex64Pattern.MatchString(p.AgentSHA256):
		return errors.New("invalid agent checksum")
	}

	return nil
}

// ParsePayload decodes canonical bytes into a validated Payload.
func ParsePayload(data []byte) (Payload, error) {
	fields, err := Decode(data)
	if err != nil {
		return Payload{}, err
	}
	if len(fields) != 16 {
		return Payload{}, errors.New("payload must have exactly the version 1 fields")
	}
	var p Payload
	var errs []error
	str := func(name string) string {
		v, ok := fields[name].(string)
		if !ok {
			errs = append(errs, fmt.Errorf("field %q must be a string", name))
		}
		return v
	}
	num := func(name string) int64 {
		v, ok := fields[name].(int64)
		if !ok {
			errs = append(errs, fmt.Errorf("field %q must be an integer", name))
		}
		return v
	}
	p.Proto = num("proto")
	p.Type = str("type")
	p.Node = str("node")
	p.Key = str("key")
	p.Seq = num("seq")
	p.TS = num("ts")
	p.MonoMS = num("mono_ms")
	p.Boot = str("boot")
	p.Run = str("run")
	p.Status = str("status")
	p.ChecksFailed = num("checks_failed")
	p.Prev = str("prev")
	p.AgentVersion = str("agent_version")
	p.AgentCommit = str("agent_commit")
	p.AgentBuild = str("agent_build")
	p.AgentSHA256 = str("agent_sha256")
	if len(errs) > 0 {
		return Payload{}, errors.Join(errs...)
	}
	if err := p.Validate(); err != nil {
		return Payload{}, err
	}

	return p, nil
}

// Fingerprint returns the hex SHA-256 of a raw Ed25519 public key.
func Fingerprint(pub ed25519.PublicKey) string {
	sum := sha256.Sum256(pub)

	return hex.EncodeToString(sum[:])
}

// SigningMessage returns the exact bytes that are signed for a payload.
func SigningMessage(canonical []byte) []byte {
	msg := make([]byte, 0, len(SignatureContext)+len(canonical))
	msg = append(msg, SignatureContext...)

	return append(msg, canonical...)
}

// Sign signs canonical payload bytes.
func Sign(priv ed25519.PrivateKey, canonical []byte) []byte {
	return ed25519.Sign(priv, SigningMessage(canonical))
}

// VerifySignature checks an Ed25519 signature over canonical payload bytes.
func VerifySignature(pub ed25519.PublicKey, canonical, sig []byte) bool {
	if len(pub) != ed25519.PublicKeySize || len(sig) != ed25519.SignatureSize {
		return false
	}

	return ed25519.Verify(pub, SigningMessage(canonical), sig)
}

// PayloadHash returns hex SHA-256 of the canonical payload bytes.
func PayloadHash(canonical []byte) string {
	sum := sha256.Sum256(canonical)

	return hex.EncodeToString(sum[:])
}

// EventHash links an event into the chain: SHA-256(prev_hash || payload_hash), both
// as raw 32-byte values. The payload also contains prev, so the agent's signature
// covers the link as well.
func EventHash(prevHex, payloadHashHex string) (string, error) {
	prev, err := hex.DecodeString(prevHex)
	if err != nil || len(prev) != 32 {
		return "", errors.New("invalid previous hash")
	}
	ph, err := hex.DecodeString(payloadHashHex)
	if err != nil || len(ph) != 32 {
		return "", errors.New("invalid payload hash")
	}
	sum := sha256.Sum256(append(prev, ph...))

	return hex.EncodeToString(sum[:]), nil
}

// ValidNodeID reports whether s is a well-formed public node id.
func ValidNodeID(s string) bool { return nodePattern.MatchString(s) }
