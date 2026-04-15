# Security

## Composer audit (PBP-S8)

`composer audit` runs as a required CI gate. Any advisory on a dependency
in `composer.lock` — runtime or dev — fails the build.

### Triage

```bash
# Reproduce locally
composer audit --no-interaction

# Narrow to runtime deps only (what ships to production)
composer audit --no-dev

# Show only high-severity advisories
composer audit --abandoned=ignore
```

When the CI step fails:

1. **Read the advisory** — copy the GHSA/CVE link from the output into
   your browser. Skim the affected-versions range and the patched release.
2. **Check if we're actually affected** — not every advisory matches
   our usage. `composer why vendor/package` shows what pulls it in.
3. **Decide the fix**:
   - **Patch release available**: `composer require vendor/package:^X.Y.Z`
     then `composer update vendor/package --with-dependencies`.
   - **Only a major-version bump patches it**: evaluate the upgrade path,
     open a follow-up issue, and — if the risk is acceptable until the
     upgrade lands — add the advisory to `composer.json`'s `config.audit`
     ignore list **with a TODO referencing the upgrade issue**.
   - **Advisory is a false positive for our usage**: same treatment — add
     to the ignore list with a comment.
4. **Verify the fix**: `composer audit` should return
   `No security vulnerability advisories found.` locally before pushing.
5. **If a secret was exposed**: rotate it in every environment. The
   advisory text will tell you whether credential exposure is the
   concern — if so, treat it as an incident.

### Platform guards

- `composer.json` `config.platform-check: true` — the generated
  `vendor/composer/platform_check.php` asserts the runtime PHP matches
  `"php": ">=8.3"` at every autoload. Mismatch dies immediately with a
  clear message instead of producing a cryptic syntax error deep in a
  request path.
- `composer.json` `config.classmap-authoritative: true` — the autoloader
  only uses the pre-built classmap and never falls back to scanning the
  filesystem. Adds one line of dev friction (`composer dump-autoload`
  after creating a new class) and removes a class of production
  performance + security tail risks.
- Production Docker builds pass `--classmap-authoritative` explicitly
  too, so even images built from a checkout with looser dev config get
  the strict autoloader.

### Sensitive-param + clock-injection guards

Run alongside composer audit in CI:

- `composer check-sensitive-params` — every parameter named
  `$password|$token|$secret|$apiKey|$plaintext|...` must carry
  `#[\SensitiveParameter]` (PBP-S1).
- `composer check-clock-injection` — no `new \DateTimeImmutable()` /
  `'now'` / `'today'` / `'+X'` / `'-X'` in business logic; use the
  injected `Psr\Clock\ClockInterface` (PBP-S5).
