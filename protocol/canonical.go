// Package protocol implements the UpTime-Server event protocol (version 1):
// canonical payload encoding, Ed25519 signatures, the per-node hash chain, the
// uptime formula and offline verification of published proofs.
//
// The same rules are implemented by the Laravel collector (collector/laravel) and
// are specified in docs/protocol.md. Shared test vectors live in testdata/vectors.
package protocol

import (
	"errors"
	"fmt"
	"sort"
	"strconv"
)

// MaxSafeInteger is the largest integer allowed in a payload (2^53 - 1), so every
// value round-trips exactly through JavaScript and PHP as well as Go.
const MaxSafeInteger = 1<<53 - 1

const (
	maxKeyLength    = 32
	maxStringLength = 256
	maxFields       = 32
)

// ErrNotCanonical is returned when bytes are valid-looking JSON but not in the
// canonical form that was signed.
var ErrNotCanonical = errors.New("payload is not in canonical form")

// Canonical payloads are flat JSON objects with these restrictions, which make the
// encoding unique and trivial to reproduce in any language:
//
//   - keys match [a-z0-9_]{1,32}, are unique and sorted by byte value;
//   - values are strings or non-negative integers;
//   - strings contain only printable ASCII (0x20-0x7E) except '"' and '\', so no
//     escaping is ever needed, and are at most 256 bytes;
//   - integers are decimal without sign or leading zeros, at most 2^53 - 1;
//   - no whitespace anywhere.
//
// A value is therefore either a string or an int64.

// Encode returns the canonical encoding of fields.
func Encode(fields map[string]any) ([]byte, error) {
	if len(fields) == 0 || len(fields) > maxFields {
		return nil, fmt.Errorf("payload must have 1 to %d fields", maxFields)
	}
	keys := make([]string, 0, len(fields))
	for k := range fields {
		if !validKey(k) {
			return nil, fmt.Errorf("invalid field name %q", k)
		}
		keys = append(keys, k)
	}
	sort.Strings(keys)

	out := make([]byte, 0, 512)
	out = append(out, '{')
	for i, k := range keys {
		if i > 0 {
			out = append(out, ',')
		}
		out = append(out, '"')
		out = append(out, k...)
		out = append(out, '"', ':')
		switch v := fields[k].(type) {
		case string:
			if !validString(v) {
				return nil, fmt.Errorf("field %q: string contains characters outside the allowed set or is too long", k)
			}
			out = append(out, '"')
			out = append(out, v...)
			out = append(out, '"')
		case int64:
			if v < 0 || v > MaxSafeInteger {
				return nil, fmt.Errorf("field %q: integer out of range", k)
			}
			out = strconv.AppendInt(out, v, 10)
		case int:
			if v < 0 || int64(v) > MaxSafeInteger {
				return nil, fmt.Errorf("field %q: integer out of range", k)
			}
			out = strconv.AppendInt(out, int64(v), 10)
		default:
			return nil, fmt.Errorf("field %q: unsupported type %T", k, v)
		}
	}
	out = append(out, '}')

	return out, nil
}

// Decode parses a canonical payload. It rejects anything that is not exactly the
// canonical encoding of the decoded fields (re-encoding must give the same bytes).
func Decode(data []byte) (map[string]any, error) {
	p := parser{data: data}
	fields, err := p.object()
	if err != nil {
		return nil, err
	}
	again, err := Encode(fields)
	if err != nil {
		return nil, err
	}
	if string(again) != string(data) {
		return nil, ErrNotCanonical
	}

	return fields, nil
}

func validKey(k string) bool {
	if len(k) == 0 || len(k) > maxKeyLength {
		return false
	}
	for i := 0; i < len(k); i++ {
		c := k[i]
		if !(c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '_') {
			return false
		}
	}

	return true
}

func validString(s string) bool {
	if len(s) > maxStringLength {
		return false
	}
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c < 0x20 || c > 0x7e || c == '"' || c == '\\' {
			return false
		}
	}

	return true
}

type parser struct {
	data []byte
	pos  int
}

func (p *parser) fail(msg string) error {
	return fmt.Errorf("%w: %s at byte %d", ErrNotCanonical, msg, p.pos)
}

func (p *parser) expect(c byte) error {
	if p.pos >= len(p.data) || p.data[p.pos] != c {
		return p.fail(fmt.Sprintf("expected %q", c))
	}
	p.pos++

	return nil
}

func (p *parser) object() (map[string]any, error) {
	if err := p.expect('{'); err != nil {
		return nil, err
	}
	fields := map[string]any{}
	for {
		key, err := p.str()
		if err != nil {
			return nil, err
		}
		if _, dup := fields[key]; dup {
			return nil, p.fail("duplicate field")
		}
		if err := p.expect(':'); err != nil {
			return nil, err
		}
		if p.pos >= len(p.data) {
			return nil, p.fail("unexpected end")
		}
		if p.data[p.pos] == '"' {
			v, err := p.str()
			if err != nil {
				return nil, err
			}
			fields[key] = v
		} else {
			v, err := p.integer()
			if err != nil {
				return nil, err
			}
			fields[key] = v
		}
		if len(fields) > maxFields {
			return nil, p.fail("too many fields")
		}
		if p.pos < len(p.data) && p.data[p.pos] == ',' {
			p.pos++
			continue
		}
		if err := p.expect('}'); err != nil {
			return nil, err
		}
		if p.pos != len(p.data) {
			return nil, p.fail("trailing data")
		}

		return fields, nil
	}
}

func (p *parser) str() (string, error) {
	if err := p.expect('"'); err != nil {
		return "", err
	}
	start := p.pos
	for p.pos < len(p.data) && p.data[p.pos] != '"' {
		p.pos++
	}
	if p.pos >= len(p.data) {
		return "", p.fail("unterminated string")
	}
	s := string(p.data[start:p.pos])
	p.pos++

	return s, nil
}

func (p *parser) integer() (int64, error) {
	start := p.pos
	for p.pos < len(p.data) && p.data[p.pos] >= '0' && p.data[p.pos] <= '9' {
		p.pos++
	}
	if start == p.pos || p.pos-start > 16 {
		return 0, p.fail("invalid integer")
	}
	v, err := strconv.ParseInt(string(p.data[start:p.pos]), 10, 64)
	if err != nil {
		return 0, p.fail("invalid integer")
	}

	return v, nil
}
