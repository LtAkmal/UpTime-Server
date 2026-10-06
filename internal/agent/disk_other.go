//go:build !linux

package agent

import "errors"

func diskUsedPercent(string) (float64, error) {
	return 0, errors.New("disk checks are only supported on Linux")
}
