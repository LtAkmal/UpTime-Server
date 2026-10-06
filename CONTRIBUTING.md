# Contributing

Thanks for helping. Keep changes small and focused.

- Run `make test` (Go) and, for collector changes, the PHP tests described in
  [docs/testing.md](docs/testing.md).
- Protocol changes must update `docs/protocol.md`, both implementations (Go and PHP) and
  the test vectors (`make vectors`), and must not silently change how existing proofs
  verify.
- Never weaken the honesty of user-facing wording: no "tamper-proof", no claims of
  independence that are not true.
- Do not commit secrets, real addresses, production configuration or customer data.
- The Go code uses the standard library only; please keep it that way.
