# Contributing

Issues and pull requests are welcome on `42bash/invoice-bridge`. A private report still goes to contact@42bash.com, as described in [SECURITY.md](SECURITY.md).

- Do not include tokens, real invoices, tax identifiers, or unsanitised logs in an issue, a pull request, or a screenshot.
- Add or update a PHPUnit test for a behaviour change.
- Do not add a `Co-authored-by` trailer.
- Keep Invoice Ninja and SUPER PDP as the only integrations.
- Commits on this project use the author name `42bash` and the email `contact@42bash.com`.

Run `vendor/bin/phpunit` before opening a pull request. The CI workflow runs the same suite.
