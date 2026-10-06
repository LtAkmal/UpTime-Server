//go:build linux

package agent

import "syscall"

func diskUsedPercent(path string) (float64, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, err
	}
	used := float64(st.Blocks - st.Bfree)
	total := used + float64(st.Bavail)
	if total == 0 {
		return 0, nil
	}

	return used / total * 100, nil
}
