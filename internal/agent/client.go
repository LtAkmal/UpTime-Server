package agent

import (
	"bytes"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"time"

	"github.com/LtAkmal/UpTime-Server/internal/buildinfo"
)

const maxResponseBytes = 64 << 10

// Client talks to the collector's agent API.
type Client struct {
	base string
	http *http.Client
}

// APIError is a non-2xx collector response.
type APIError struct {
	Status  int
	Code    string `json:"error"`
	Message string `json:"message"`
	Head    string `json:"head"`
	LastSeq int64  `json:"last_seq"`
}

func (e *APIError) Error() string {
	if e.Message != "" {
		return fmt.Sprintf("collector returned %d %s: %s", e.Status, e.Code, e.Message)
	}

	return fmt.Sprintf("collector returned %d %s", e.Status, e.Code)
}

// Temporary reports whether retrying the same request may succeed.
func (e *APIError) Temporary() bool {
	return e.Status == http.StatusTooManyRequests || e.Status >= 500
}

// NewClient builds an HTTP client with the configured TLS settings.
func NewClient(cfg *Config) (*Client, error) {
	tlsCfg := &tls.Config{MinVersion: tls.VersionTLS12}
	if cfg.CAFile != "" {
		pem, err := os.ReadFile(cfg.CAFile)
		if err != nil {
			return nil, fmt.Errorf("ca_file: %w", err)
		}
		pool := x509.NewCertPool()
		if !pool.AppendCertsFromPEM(pem) {
			return nil, errors.New("ca_file contains no certificates")
		}
		tlsCfg.RootCAs = pool
	}
	if cfg.InsecureSkipTLS {
		tlsCfg.InsecureSkipVerify = true //nolint:gosec // explicit development opt-in, warned at startup
	}
	transport := &http.Transport{
		Proxy:                 http.ProxyFromEnvironment,
		TLSClientConfig:       tlsCfg,
		MaxIdleConns:          2,
		IdleConnTimeout:       90 * time.Second,
		TLSHandshakeTimeout:   cfg.RequestTimeout,
		ResponseHeaderTimeout: cfg.RequestTimeout,
	}

	return &Client{
		base: cfg.CollectorURL,
		http: &http.Client{
			Timeout:   cfg.RequestTimeout,
			Transport: transport,
			// Never follow redirects: a redirect could move signed data or the
			// enrollment token to another host.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		},
	}, nil
}

func (c *Client) do(method, path string, body, out any) error {
	var reader io.Reader
	if body != nil {
		data, err := json.Marshal(body)
		if err != nil {
			return err
		}
		reader = bytes.NewReader(data)
	}
	req, err := http.NewRequest(method, c.base+path, reader)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "uptime-agent/"+buildinfo.Version)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.http.Do(req)
	if err != nil {
		// Strip the URL from transport errors; it is configuration, not secret, but
		// errors are logged and the message is enough.
		var uerr *url.Error
		if errors.As(err, &uerr) {
			return fmt.Errorf("%s request failed: %w", method, uerr.Err)
		}
		return err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseBytes))
	if err != nil {
		return err
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		apiErr := &APIError{Status: resp.StatusCode}
		_ = json.Unmarshal(data, apiErr)
		if apiErr.Code == "" {
			apiErr.Code = http.StatusText(resp.StatusCode)
		}
		return apiErr
	}
	if out != nil {
		if err := json.Unmarshal(data, out); err != nil {
			return fmt.Errorf("invalid collector response: %w", err)
		}
	}

	return nil
}

// EnrollRequest is sent once with a one-time token.
type EnrollRequest struct {
	Token        string `json:"token"`
	PublicKey    string `json:"public_key"`
	Protocol     int    `json:"protocol"`
	AgentVersion string `json:"agent_version"`
}

// EnrollResponse tells the agent which node it reports for and where its chain is.
type EnrollResponse struct {
	NodeID      string `json:"node_id"`
	Fingerprint string `json:"fingerprint"`
	Head        string `json:"head"`
	IntervalS   int    `json:"interval_s"`
}

// Enroll registers the public key with a one-time token.
func (c *Client) Enroll(req EnrollRequest) (*EnrollResponse, error) {
	var out EnrollResponse
	if err := c.do(http.MethodPost, "/api/uptime/agent/enroll", req, &out); err != nil {
		return nil, err
	}

	return &out, nil
}

// HeadResponse is the node's current chain head and this key's last sequence.
type HeadResponse struct {
	Head    string `json:"head"`
	LastSeq int64  `json:"last_seq"`
}

// Head fetches public chain position data for a node and key.
func (c *Client) Head(nodeID, fingerprint string) (*HeadResponse, error) {
	var out HeadResponse
	path := "/api/uptime/agent/nodes/" + url.PathEscape(nodeID) + "/head?key=" + url.QueryEscape(fingerprint)
	if err := c.do(http.MethodGet, path, nil, &out); err != nil {
		return nil, err
	}

	return &out, nil
}

// EventRequest carries the exact signed bytes.
type EventRequest struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}

// EventResponse confirms an accepted (or already accepted) event.
type EventResponse struct {
	Status    string `json:"status"` // accepted | duplicate
	EventHash string `json:"event_hash"`
	Seq       int64  `json:"seq"`
}

// SendEvent submits one signed event.
func (c *Client) SendEvent(req EventRequest) (*EventResponse, error) {
	var out EventResponse
	if err := c.do(http.MethodPost, "/api/uptime/agent/events", req, &out); err != nil {
		return nil, err
	}

	return &out, nil
}
