package agent

import (
	"net"
	"time"
)

// RunChecks returns how many configured local health checks failed. Only this count
// is reported; labels, addresses and paths stay on the node.
func RunChecks(cfg *Config) int64 {
	var failed int64
	for _, c := range cfg.TCPChecks {
		conn, err := net.DialTimeout("tcp", c.Address, 2*time.Second)
		if err != nil {
			failed++
			continue
		}
		conn.Close()
	}
	for _, c := range cfg.DiskChecks {
		used, err := diskUsedPercent(c.Path)
		if err != nil || used > c.MaxPercent {
			failed++
		}
	}

	return failed
}

// FailedCheckLabels names the failing checks for the local log only.
func FailedCheckLabels(cfg *Config) []string {
	var labels []string
	for _, c := range cfg.TCPChecks {
		conn, err := net.DialTimeout("tcp", c.Address, 2*time.Second)
		if err != nil {
			labels = append(labels, c.Label)
			continue
		}
		conn.Close()
	}
	for _, c := range cfg.DiskChecks {
		if used, err := diskUsedPercent(c.Path); err != nil || used > c.MaxPercent {
			labels = append(labels, "disk "+c.Path)
		}
	}

	return labels
}
