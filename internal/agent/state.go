package agent

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/LtAkmal/UpTime-Server/protocol"
)

const (
	keyFile        = "agent.key"
	enrollmentFile = "enrollment.json"
	stateFile      = "state.json"
	recentHashes   = 128
)

// Enrollment records which node this agent reports for. Written by "enroll" or
// "set-node"; contains no secrets.
type Enrollment struct {
	NodeID      string `json:"node_id"`
	Fingerprint string `json:"key_fingerprint"`
	EnrolledAt  string `json:"enrolled_at"`
}

// Pending is an event that was signed and persisted but not yet accepted.
type Pending struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
	EventHash string `json:"event_hash"`
	CreatedMS int64  `json:"created_ms"`
}

// State is the agent's position in its node's chain. It is written before every
// send, so a crash can never reuse a sequence number.
type State struct {
	NodeID   string   `json:"node_id"`
	Key      string   `json:"key_fingerprint"`
	Seq      int64    `json:"seq"`
	Head     string   `json:"head"`     // last event hash the collector accepted (as far as known)
	Boot     string   `json:"boot"`     // boot session of the last event
	Recent   []string `json:"recent"`   // hashes of events this agent signed recently
	Pending  *Pending `json:"pending"`  // signed but unconfirmed event
	Accepted int64    `json:"accepted"` // last acceptance time (Unix ms), informational
}

func (s *State) remember(hash string) {
	s.Recent = append(s.Recent, hash)
	if len(s.Recent) > recentHashes {
		s.Recent = s.Recent[len(s.Recent)-recentHashes:]
	}
}

func (s *State) signed(hash string) bool {
	for _, h := range s.Recent {
		if h == hash {
			return true
		}
	}

	return false
}

// writeFileAtomic writes data with mode 0600 via a synced temporary file and rename.
func writeFileAtomic(path string, data []byte) error {
	dir := filepath.Dir(path)
	tmp, err := os.CreateTemp(dir, ".tmp-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := os.Rename(tmp.Name(), path); err != nil {
		return err
	}
	if d, err := os.Open(dir); err == nil {
		_ = d.Sync()
		d.Close()
	}

	return nil
}

func readJSON(path string, v any) error {
	data, err := os.ReadFile(path)
	if err != nil {
		return err
	}

	return json.Unmarshal(data, v)
}

func writeJSON(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}

	return writeFileAtomic(path, append(data, '\n'))
}

// ensureStateDir creates the state directory with mode 0700 and refuses one that
// other users can read.
func ensureStateDir(dir string) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	st, err := os.Stat(dir)
	if err != nil {
		return err
	}
	if st.Mode().Perm()&0o077 != 0 {
		return fmt.Errorf("%s must not be accessible by other users (chmod 700)", dir)
	}

	return nil
}

// LoadKey reads the PKCS#8 PEM private key and refuses it if group/other can read it.
func LoadKey(stateDir string) (ed25519.PrivateKey, error) {
	path := filepath.Join(stateDir, keyFile)
	st, err := os.Stat(path)
	if err != nil {
		return nil, err
	}
	if st.Mode().Perm()&0o077 != 0 {
		return nil, fmt.Errorf("%s must only be readable by its owner (chmod 600)", path)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	block, _ := pem.Decode(data)
	if block == nil || block.Type != "PRIVATE KEY" {
		return nil, errors.New("agent key is not a PEM private key")
	}
	key, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return nil, err
	}
	priv, ok := key.(ed25519.PrivateKey)
	if !ok {
		return nil, errors.New("agent key is not an Ed25519 key")
	}

	return priv, nil
}

// GenerateKey creates a new Ed25519 key in stateDir (mode 0600). It never
// overwrites an existing key unless replace is true.
func GenerateKey(stateDir string, replace bool) (ed25519.PrivateKey, error) {
	if err := ensureStateDir(stateDir); err != nil {
		return nil, err
	}
	path := filepath.Join(stateDir, keyFile)
	if _, err := os.Stat(path); err == nil && !replace {
		return nil, fmt.Errorf("%s already exists", path)
	}
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, err
	}
	der, err := x509.MarshalPKCS8PrivateKey(priv)
	if err != nil {
		return nil, err
	}
	if err := writeFileAtomic(path, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der})); err != nil {
		return nil, err
	}

	return priv, nil
}

// PublicFingerprint returns the fingerprint of a private key's public half.
func PublicFingerprint(priv ed25519.PrivateKey) string {
	return protocol.Fingerprint(priv.Public().(ed25519.PublicKey))
}

func loadEnrollment(stateDir string) (*Enrollment, error) {
	var e Enrollment
	if err := readJSON(filepath.Join(stateDir, enrollmentFile), &e); err != nil {
		return nil, err
	}

	return &e, nil
}

func saveEnrollment(stateDir string, e Enrollment) error {
	if e.EnrolledAt == "" {
		e.EnrolledAt = time.Now().UTC().Format(time.RFC3339)
	}

	return writeJSON(filepath.Join(stateDir, enrollmentFile), e)
}

func loadState(stateDir string) (*State, error) {
	var s State
	err := readJSON(filepath.Join(stateDir, stateFile), &s)
	if errors.Is(err, os.ErrNotExist) {
		return &State{}, nil
	}

	return &s, err
}

func saveState(stateDir string, s *State) error {
	return writeJSON(filepath.Join(stateDir, stateFile), s)
}
