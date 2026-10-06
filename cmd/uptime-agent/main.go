// Command uptime-agent runs on a monitored node and sends Ed25519-signed,
// hash-chained heartbeats to an UpTime-Server collector.
package main

import (
	"bufio"
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"os/signal"
	"strings"
	"syscall"

	"github.com/LtAkmal/UpTime-Server/internal/agent"
	"github.com/LtAkmal/UpTime-Server/internal/buildinfo"
	"github.com/LtAkmal/UpTime-Server/protocol"
)

const usage = `uptime-agent: signed uptime heartbeats for UpTime-Server

Usage:
  uptime-agent run      [--config PATH]                     send heartbeats (systemd service)
  uptime-agent enroll   [--config PATH] --token-file PATH|- [--rotate-key]
                                                            register this node with a one-time token
  uptime-agent keygen   [--config PATH]                     create a key for manual registration
  uptime-agent set-node [--config PATH] --node ID           use a manually registered key for node ID
  uptime-agent status   [--config PATH]                     show local chain state (no secrets)
  uptime-agent version                                      show build metadata and binary SHA-256

Secrets are never accepted as command-line arguments. The enrollment token is read
from a file or standard input; the private key never leaves the state directory.
`

func main() {
	log.SetFlags(0)
	if len(os.Args) < 2 {
		fmt.Fprint(os.Stderr, usage)
		os.Exit(2)
	}
	cmd, args := os.Args[1], os.Args[2:]
	var err error
	switch cmd {
	case "run":
		err = runCmd(args)
	case "enroll":
		err = enrollCmd(args)
	case "keygen":
		err = keygenCmd(args)
	case "set-node":
		err = setNodeCmd(args)
	case "status":
		err = statusCmd(args)
	case "version", "--version", "-v":
		versionCmd()
	case "help", "--help", "-h":
		fmt.Print(usage)
	default:
		fmt.Fprint(os.Stderr, usage)
		os.Exit(2)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}

func flags(name string, args []string, extra func(*flag.FlagSet)) (*agent.Config, error) {
	fs := flag.NewFlagSet(name, flag.ExitOnError)
	path := fs.String("config", agent.DefaultConfigPath, "configuration file")
	if extra != nil {
		extra(fs)
	}
	if err := fs.Parse(args); err != nil {
		return nil, err
	}
	if fs.NArg() > 0 {
		return nil, fmt.Errorf("unexpected argument %q", fs.Arg(0))
	}

	return agent.LoadConfig(*path)
}

func runCmd(args []string) error {
	cfg, err := flags("run", args, nil)
	if err != nil {
		return err
	}
	a, err := agent.New(cfg, log.New(os.Stderr, "", 0))
	if err != nil {
		return err
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	return a.Run(ctx)
}

func enrollCmd(args []string) error {
	var tokenFile string
	var rotate bool
	cfg, err := flags("enroll", args, func(fs *flag.FlagSet) {
		fs.StringVar(&tokenFile, "token-file", "", "file containing the one-time enrollment token, or - for standard input")
		fs.BoolVar(&rotate, "rotate-key", false, "generate a new key and replace the current one")
	})
	if err != nil {
		return err
	}
	token, err := readToken(tokenFile)
	if err != nil {
		return err
	}
	for _, w := range cfg.InsecureWarnings() {
		fmt.Fprintln(os.Stderr, w)
	}
	resp, err := agent.Enroll(cfg, token, rotate)
	if err != nil {
		return err
	}
	fmt.Printf("Enrolled as node %s\nKey fingerprint: %s\n", resp.NodeID, resp.Fingerprint)
	if tokenFile != "" && tokenFile != "-" {
		fmt.Printf("The token is now used up; delete %s.\n", tokenFile)
	}

	return nil
}

func readToken(path string) (string, error) {
	var r io.Reader
	switch path {
	case "":
		return "", errors.New("--token-file is required (use - to read the token from standard input)")
	case "-":
		r = os.Stdin
	default:
		f, err := os.Open(path)
		if err != nil {
			return "", err
		}
		defer f.Close()
		r = f
	}
	line, err := bufio.NewReader(io.LimitReader(r, 512)).ReadString('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		return "", err
	}

	return strings.TrimSpace(line), nil
}

func keygenCmd(args []string) error {
	cfg, err := flags("keygen", args, nil)
	if err != nil {
		return err
	}
	key, err := agent.GenerateKey(cfg.StateDir, false)
	if err != nil {
		return err
	}
	pub := key.Public().(ed25519.PublicKey)
	fmt.Printf("Public key (register this in the collector): %s\nFingerprint: %s\n", base64.StdEncoding.EncodeToString(pub), protocol.Fingerprint(pub))

	return nil
}

func setNodeCmd(args []string) error {
	var node string
	cfg, err := flags("set-node", args, func(fs *flag.FlagSet) { fs.StringVar(&node, "node", "", "public node id") })
	if err != nil {
		return err
	}
	fp, err := agent.SetNode(cfg, node)
	if err != nil {
		return err
	}
	fmt.Printf("This agent now reports for node %s with key %s.\n", node, fp)

	return nil
}

func statusCmd(args []string) error {
	cfg, err := flags("status", args, nil)
	if err != nil {
		return err
	}
	a, err := agent.New(cfg, log.New(io.Discard, "", 0))
	if err != nil {
		return err
	}
	fmt.Print(a.Status())

	return nil
}

func versionCmd() {
	sum, err := buildinfo.ExecutableSHA256()
	if err != nil {
		sum = "unavailable: " + err.Error()
	}
	fmt.Printf("uptime-agent %s\ncommit:     %s\nbuilt:      %s\ngo:         %s\nprotocol:   %d\nsha256:     %s\n",
		buildinfo.Version, buildinfo.Commit, buildinfo.BuildTime, buildinfo.GoVersion(), protocol.Version, sum)
}
