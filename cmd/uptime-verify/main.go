// Command uptime-verify independently checks an UpTime-Server proof: every Ed25519
// signature, every hash-chain link, sequence numbers, key validity and the published
// uptime summary. It trusts nothing the collector computed.
package main

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"

	"github.com/LtAkmal/UpTime-Server/internal/buildinfo"
	"github.com/LtAkmal/UpTime-Server/protocol"
)

const usage = `uptime-verify: independent verification of UpTime-Server proofs

Usage:
  uptime-verify [--json] [--allow-http] [--max-pages N] <proof.json | proof URL>
  uptime-verify fingerprint <base64 public key>
  uptime-verify version

Exit status: 0 valid, 1 invalid, 2 usage or fetch error.
`

func main() {
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "fingerprint":
			if len(os.Args) != 3 {
				fmt.Fprint(os.Stderr, usage)
				os.Exit(2)
			}
			raw, err := base64.StdEncoding.DecodeString(os.Args[2])
			if err != nil || len(raw) != ed25519.PublicKeySize {
				fmt.Fprintln(os.Stderr, "error: not a base64 Ed25519 public key")
				os.Exit(2)
			}
			fmt.Println(protocol.Fingerprint(raw))
			return
		case "version":
			fmt.Printf("uptime-verify %s (commit %s, built %s, %s)\n", buildinfo.Version, buildinfo.Commit, buildinfo.BuildTime, buildinfo.GoVersion())
			return
		}
	}

	fs := flag.NewFlagSet("uptime-verify", flag.ExitOnError)
	asJSON := fs.Bool("json", false, "print the report as JSON")
	allowHTTP := fs.Bool("allow-http", false, "allow fetching proofs over plain HTTP (local development)")
	maxPages := fs.Int("max-pages", 500, "maximum number of proof pages to fetch")
	fs.Usage = func() { fmt.Fprint(os.Stderr, usage) }
	_ = fs.Parse(os.Args[1:])
	if fs.NArg() != 1 {
		fs.Usage()
		os.Exit(2)
	}

	proof, err := load(fs.Arg(0), *allowHTTP, *maxPages)
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(2)
	}
	report := protocol.VerifyProof(proof)

	if *asJSON {
		enc := json.NewEncoder(os.Stdout)
		enc.SetIndent("", "  ")
		_ = enc.Encode(report)
	} else {
		printReport(proof, report)
	}
	if !report.Valid {
		os.Exit(1)
	}
}

func load(src string, allowHTTP bool, maxPages int) (*protocol.Proof, error) {
	if !strings.HasPrefix(src, "http://") && !strings.HasPrefix(src, "https://") {
		data, err := os.ReadFile(src)
		if err != nil {
			return nil, err
		}
		var p protocol.Proof
		if err := json.Unmarshal(data, &p); err != nil {
			return nil, fmt.Errorf("invalid proof JSON: %w", err)
		}
		return &p, nil
	}

	client := &http.Client{Timeout: 30 * time.Second}
	var merged *protocol.Proof
	next := src
	for page := 0; next != ""; page++ {
		if page >= maxPages {
			return nil, fmt.Errorf("more than %d pages; use --max-pages or a shorter range", maxPages)
		}
		u, err := url.Parse(next)
		if err != nil {
			return nil, err
		}
		if u.Scheme != "https" && !(allowHTTP && u.Scheme == "http") {
			return nil, errors.New("refusing a non-HTTPS URL (use --allow-http for local development)")
		}
		p, err := fetch(client, u.String())
		if err != nil {
			return nil, err
		}
		if merged == nil {
			merged = p
		} else {
			if p.Node.ID != merged.Node.ID || p.Range != merged.Range {
				return nil, errors.New("pages describe different nodes or ranges")
			}
			merged.Events = append(merged.Events, p.Events...)
			merged.Tail = p.Tail
			merged.Page = p.Page
			merged.Keys = p.Keys
		}
		next = ""
		if !p.Page.Complete && p.Page.Next != "" {
			ref, err := u.Parse(p.Page.Next)
			if err != nil {
				return nil, err
			}
			if ref.Host != u.Host {
				return nil, errors.New("next page points to a different host")
			}
			next = ref.String()
		}
	}

	return merged, nil
}

func fetch(client *http.Client, u string) (*protocol.Proof, error) {
	resp, err := client.Get(u)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("GET %s: %s", u, resp.Status)
	}
	var p protocol.Proof
	if err := json.NewDecoder(io.LimitReader(resp.Body, 64<<20)).Decode(&p); err != nil {
		return nil, fmt.Errorf("invalid proof JSON: %w", err)
	}

	return &p, nil
}

func ms(v int64) string {
	if v == 0 {
		return "—"
	}

	return time.UnixMilli(v).UTC().Format("2006-01-02 15:04:05 UTC")
}

func printReport(p *protocol.Proof, r protocol.Report) {
	fmt.Printf("Node:        %s (%s)\n", p.Node.ID, p.Node.Name)
	fmt.Printf("Range:       %s → %s\n", ms(p.Range.From), ms(p.Range.To))
	fmt.Printf("Collector:   %s %s (commit %s) — self-reported\n", p.Collector.Software, p.Collector.Version, p.Collector.Commit)
	for _, k := range p.Keys {
		state := "active"
		if k.RevokedAt != nil {
			state = "revoked " + ms(*k.RevokedAt)
		}
		fmt.Printf("Key:         %s (registered %s, %s)\n", k.Fingerprint, ms(k.RegisteredAt), state)
	}
	if r.FromGenesis {
		fmt.Println("Chain:       verified from the first event of the node (genesis)")
	} else if p.Anchor != nil {
		fmt.Printf("Chain:       verified from anchor event %d (%s)\n", p.Anchor.ID, p.Anchor.EventHash)
	}
	fmt.Printf("Events:      %d checked (signature, payload hash, chain link, sequence, key validity, clock window)\n", r.EventsChecked)
	if r.SummaryChecked {
		up := "no data"
		if r.Recomputed.UptimePercent != nil {
			up = fmt.Sprintf("%.4f%%", *r.Recomputed.UptimePercent)
		}
		fmt.Printf("Uptime:      %s (covered %s, confirmed downtime %s, coverage %.2f%%)\n", up,
			time.Duration(r.Recomputed.CoveredMS)*time.Millisecond, time.Duration(r.Recomputed.DowntimeMS)*time.Millisecond, r.Recomputed.CoveragePercent)
	}
	for _, w := range r.Warnings {
		fmt.Printf("Warning:     %s\n", w)
	}
	if r.Valid {
		fmt.Println("Result:      VALID — every signature and chain link checks out and the published summary matches.")
		return
	}
	f := r.FirstFailure
	if f.EventID != 0 {
		fmt.Printf("Result:      INVALID at event %d (sequence %d): %s\n", f.EventID, f.Seq, f.Problem)
	} else {
		fmt.Printf("Result:      INVALID: %s\n", f.Problem)
	}
}
