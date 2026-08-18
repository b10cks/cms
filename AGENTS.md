# Agent notes

Project conventions: [CLAUDE.md](CLAUDE.md).

## Delivery tokens stay plaintext

Space Data API tokens (`blx_…`) are stored and listed in plaintext so Settings can copy them after creation. Personal access tokens are hashed; these are not. Known trade-off. Reviews treat this as accepted — do not hash, hide-after-create, or file it as a finding.
