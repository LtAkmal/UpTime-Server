package agent

import (
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/LtAkmal/UpTime-Server/internal/buildinfo"
	"github.com/LtAkmal/UpTime-Server/protocol"
)

var tokenPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{20,128}$`)

// Enroll registers this agent with a one-time token. A key is generated locally if
// none exists; with rotate, a new key replaces the current one and the old private
// key file is removed after the collector accepted the new key.
func Enroll(cfg *Config, token string, rotate bool) (*EnrollResponse, error) {
	token = strings.TrimSpace(token)
	if !tokenPattern.MatchString(token) {
		return nil, errors.New("the enrollment token has an invalid format")
	}
	if err := ensureStateDir(cfg.StateDir); err != nil {
		return nil, err
	}

	keyPath := filepath.Join(cfg.StateDir, keyFile)
	newKeyPath := keyPath + ".new"
	var key ed25519.PrivateKey
	var err error
	_, statErr := os.Stat(keyPath)
	switch {
	case rotate && statErr == nil:
		// Generate the replacement next to the current key; swap only on success.
		tmpDir := filepath.Join(cfg.StateDir, ".rotate")
		_ = os.RemoveAll(tmpDir)
		if key, err = GenerateKey(tmpDir, true); err != nil {
			return nil, err
		}
		if err = os.Rename(filepath.Join(tmpDir, keyFile), newKeyPath); err != nil {
			return nil, err
		}
		_ = os.RemoveAll(tmpDir)
	case statErr == nil:
		if key, err = LoadKey(cfg.StateDir); err != nil {
			return nil, err
		}
	default:
		if key, err = GenerateKey(cfg.StateDir, false); err != nil {
			return nil, err
		}
	}

	client, err := NewClient(cfg)
	if err != nil {
		return nil, err
	}
	pub := key.Public().(ed25519.PublicKey)
	resp, err := client.Enroll(EnrollRequest{
		Token:        token,
		PublicKey:    base64.StdEncoding.EncodeToString(pub),
		Protocol:     protocol.Version,
		AgentVersion: buildinfo.Version,
	})
	if err != nil {
		_ = os.Remove(newKeyPath)
		return nil, err
	}
	fp := protocol.Fingerprint(pub)
	if resp.Fingerprint != fp {
		_ = os.Remove(newKeyPath)
		return nil, errors.New("collector registered a different key fingerprint")
	}
	if rotate && statErr == nil {
		if err := os.Rename(newKeyPath, keyPath); err != nil {
			return nil, fmt.Errorf("new key registered but could not be installed: %w", err)
		}
	}
	if err := saveEnrollment(cfg.StateDir, Enrollment{NodeID: resp.NodeID, Fingerprint: fp, EnrolledAt: time.Now().UTC().Format(time.RFC3339)}); err != nil {
		return nil, err
	}
	// The chain continues from the node's current head with this key's own sequence.
	state, _ := loadState(cfg.StateDir)
	next := &State{NodeID: resp.NodeID, Key: fp, Head: resp.Head, Boot: state.Boot}
	next.remember(resp.Head)
	if err := saveState(cfg.StateDir, next); err != nil {
		return nil, err
	}

	return resp, nil
}

// SetNode records the node id for a key that an administrator registered manually
// (instead of using an enrollment token).
func SetNode(cfg *Config, nodeID string) (string, error) {
	key, err := LoadKey(cfg.StateDir)
	if err != nil {
		return "", err
	}
	if !protocol.ValidNodeID(nodeID) {
		return "", errors.New("invalid node id")
	}
	fp := PublicFingerprint(key)
	if err := saveEnrollment(cfg.StateDir, Enrollment{NodeID: nodeID, Fingerprint: fp}); err != nil {
		return "", err
	}
	// Force a head lookup on the next start.
	if err := saveState(cfg.StateDir, &State{}); err != nil {
		return "", err
	}

	return fp, nil
}
