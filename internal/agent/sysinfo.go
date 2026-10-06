package agent

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"strconv"
	"strings"
	"time"
)

// processStart anchors the fallback monotonic clock on systems without /proc.
var processStart = time.Now()

// processBoot is a random boot session used when the kernel boot id is unavailable.
var processBoot = randomHex(16)

func randomHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}

	return hex.EncodeToString(b)
}

// SystemInfo reads the local clocks the agent reports. Paths are fields so tests
// can point them at fixtures.
type SystemInfo struct {
	BootIDPath string // /proc/sys/kernel/random/boot_id
	UptimePath string // /proc/uptime
}

// DefaultSystemInfo uses the Linux kernel interfaces.
var DefaultSystemInfo = SystemInfo{
	BootIDPath: "/proc/sys/kernel/random/boot_id",
	UptimePath: "/proc/uptime",
}

// BootSession returns a 32-hex id that changes on every reboot. It is a hash of the
// kernel's random boot id (never the raw id). Without one, a random per-process id
// is used, which makes every agent start look like a new boot session.
func (s SystemInfo) BootSession() (string, bool) {
	data, err := os.ReadFile(s.BootIDPath)
	id := strings.TrimSpace(string(data))
	if err != nil || id == "" {
		return processBoot, false
	}
	sum := sha256.Sum256([]byte("uptime-server boot\n" + id))

	return hex.EncodeToString(sum[:16]), true
}

// MonotonicMS returns the time since boot in milliseconds (Linux CLOCK_BOOTTIME via
// /proc/uptime, which keeps counting during suspend and ignores wall-clock changes).
// The fallback is the agent's own monotonic run time.
func (s SystemInfo) MonotonicMS() (int64, bool) {
	ms, err := readUptime(s.UptimePath)
	if err != nil {
		return time.Since(processStart).Milliseconds(), false
	}

	return ms, true
}

func readUptime(path string) (int64, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return 0, err
	}
	fields := strings.Fields(string(data))
	if len(fields) == 0 {
		return 0, errors.New("empty uptime")
	}
	secs, err := strconv.ParseFloat(fields[0], 64)
	if err != nil || secs < 0 {
		return 0, errors.New("invalid uptime")
	}

	return int64(secs * 1000), nil
}
