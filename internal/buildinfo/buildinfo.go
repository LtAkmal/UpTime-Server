// Package buildinfo holds release metadata injected at build time with -ldflags
// (see Makefile). Unset values stay "dev"/"unknown": nothing is guessed.
package buildinfo

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"runtime"
)

var (
	// Version is the release version, e.g. "0.1.0".
	Version = "dev"
	// Commit is the full Git commit the binary was built from.
	Commit = "unknown"
	// BuildTime is the commit time in RFC 3339 (UTC), used instead of the wall clock so
	// that builds are reproducible.
	BuildTime = "unknown"
)

// GoVersion is the Go toolchain that built the binary.
func GoVersion() string { return runtime.Version() }

// ExecutableSHA256 hashes the running executable file.
func ExecutableSHA256() (string, error) {
	path, err := os.Executable()
	if err != nil {
		return "", err
	}
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}
