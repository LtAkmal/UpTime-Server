// Package agent implements the UpTime-Server node agent: local key management,
// enrollment, signed heartbeats and the persistent chain state.
package agent

import (
	"bufio"
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config is the agent configuration file (key = value lines, '#' comments). It holds
// no secrets: the private key and enrollment data live in StateDir.
type Config struct {
	CollectorURL      string
	StateDir          string
	Interval          time.Duration
	RequestTimeout    time.Duration
	CAFile            string
	InsecureSkipTLS   bool // development only: do not verify the collector certificate
	AllowInsecureHTTP bool // development only: allow plain http:// collector URLs
	TCPChecks         []TCPCheck
	DiskChecks        []DiskCheck
}

// TCPCheck is a local service that must accept TCP connections (e.g. Wings).
// Only the number of failed checks is ever reported, never the address or label.
type TCPCheck struct {
	Label   string
	Address string
}

// DiskCheck fails when the file system holding Path is fuller than MaxPercent.
type DiskCheck struct {
	Path       string
	MaxPercent float64
}

// DefaultConfigPath is where the systemd unit expects the configuration.
const DefaultConfigPath = "/etc/uptime-agent/agent.conf"

// LoadConfig reads and validates a configuration file.
func LoadConfig(path string) (*Config, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	if st, err := f.Stat(); err == nil && st.Mode().Perm()&0o002 != 0 {
		return nil, fmt.Errorf("%s is world-writable; fix its permissions (chmod 640)", path)
	}

	cfg := &Config{
		StateDir:       "/var/lib/uptime-agent",
		Interval:       30 * time.Second,
		RequestTimeout: 10 * time.Second,
	}
	scanner := bufio.NewScanner(f)
	line := 0
	for scanner.Scan() {
		line++
		text := strings.TrimSpace(scanner.Text())
		if text == "" || strings.HasPrefix(text, "#") {
			continue
		}
		key, value, ok := strings.Cut(text, "=")
		if !ok {
			return nil, fmt.Errorf("%s:%d: expected key = value", path, line)
		}
		key, value = strings.TrimSpace(key), strings.TrimSpace(value)
		if err := cfg.set(key, value); err != nil {
			return nil, fmt.Errorf("%s:%d: %w", path, line, err)
		}
	}
	if err := scanner.Err(); err != nil {
		return nil, err
	}

	return cfg, cfg.Validate()
}

func (c *Config) set(key, value string) error {
	switch key {
	case "collector_url":
		c.CollectorURL = strings.TrimRight(value, "/")
	case "state_dir":
		c.StateDir = value
	case "interval":
		d, err := time.ParseDuration(value)
		if err != nil {
			return fmt.Errorf("interval: %w", err)
		}
		c.Interval = d
	case "request_timeout":
		d, err := time.ParseDuration(value)
		if err != nil {
			return fmt.Errorf("request_timeout: %w", err)
		}
		c.RequestTimeout = d
	case "ca_file":
		c.CAFile = value
	case "insecure_skip_tls_verify":
		b, err := strconv.ParseBool(value)
		if err != nil {
			return fmt.Errorf("insecure_skip_tls_verify: %w", err)
		}
		c.InsecureSkipTLS = b
	case "allow_insecure_http":
		b, err := strconv.ParseBool(value)
		if err != nil {
			return fmt.Errorf("allow_insecure_http: %w", err)
		}
		c.AllowInsecureHTTP = b
	case "check_tcp":
		label, addr, ok := strings.Cut(value, " ")
		addr = strings.TrimSpace(addr)
		if !ok || label == "" {
			return errors.New("check_tcp: expected \"<label> <host:port>\"")
		}
		if _, _, err := net.SplitHostPort(addr); err != nil {
			return fmt.Errorf("check_tcp: %w", err)
		}
		c.TCPChecks = append(c.TCPChecks, TCPCheck{Label: label, Address: addr})
	case "check_disk":
		path, pct, ok := strings.Cut(value, " ")
		v, err := strconv.ParseFloat(strings.TrimSpace(pct), 64)
		if !ok || err != nil || v <= 0 || v > 100 || !strings.HasPrefix(path, "/") {
			return errors.New("check_disk: expected \"<absolute path> <max used percent>\"")
		}
		c.DiskChecks = append(c.DiskChecks, DiskCheck{Path: path, MaxPercent: v})
	default:
		return fmt.Errorf("unknown setting %q", key)
	}

	return nil
}

// Validate checks the settings. Plain HTTP and unverified TLS need an explicit
// development opt-in.
func (c *Config) Validate() error {
	if c.CollectorURL == "" {
		return errors.New("collector_url is required")
	}
	u, err := url.Parse(c.CollectorURL)
	if err != nil || u.Host == "" || (u.Scheme != "https" && u.Scheme != "http") || u.User != nil || u.RawQuery != "" {
		return errors.New("collector_url must be an http(s) URL without credentials or query")
	}
	if u.Scheme == "http" && !c.AllowInsecureHTTP {
		return errors.New("collector_url uses plain http; set allow_insecure_http = true only for local development")
	}
	if c.Interval < 5*time.Second || c.Interval > 10*time.Minute {
		return errors.New("interval must be between 5s and 10m")
	}
	if c.RequestTimeout < time.Second || c.RequestTimeout > time.Minute {
		return errors.New("request_timeout must be between 1s and 1m")
	}
	if !strings.HasPrefix(c.StateDir, "/") {
		return errors.New("state_dir must be an absolute path")
	}
	if len(c.TCPChecks)+len(c.DiskChecks) > 64 {
		return errors.New("too many health checks (max 64)")
	}

	return nil
}

// InsecureWarnings lists development settings that weaken transport security.
func (c *Config) InsecureWarnings() []string {
	var w []string
	if strings.HasPrefix(c.CollectorURL, "http://") {
		w = append(w, "INSECURE DEVELOPMENT MODE: heartbeats are sent over plain HTTP. Signatures still protect integrity, but use HTTPS for any public deployment.")
	}
	if c.InsecureSkipTLS {
		w = append(w, "INSECURE DEVELOPMENT MODE: the collector TLS certificate is not verified.")
	}

	return w
}
