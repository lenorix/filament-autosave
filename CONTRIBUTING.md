# Contributing

Pull requests are understood to be offered under the same license as the project ([Unlicense](LICENSE.md)).

Thanks for reading this before opening an issue or a pull request.

## Be kind

This project is maintained in spare time. Treat maintainers as the people they are: respectful issues and PRs get
read; rants don't. And no is not personal — it's about keeping the project healthy.

## Viability

Before requesting or submitting a feature, ask yourself if others would actually use it. Niche features are hard to
maintain.

## Before you report or open a PR

- Reproduce the bug against the latest release, to rule out a one-off.
- Check the code and open issues/pull requests — it may already exist.
- For PRs, run the checks below first, so CI doesn't catch them for you.

## Requirements to contribute

- **Formatting.** [Laravel Pint](https://laravel.com/docs/12.x/pint), default preset (PSR-12):
  `vendor/bin/pint --test` to check, `vendor/bin/pint` to fix.
- **Static analysis.** `composer phpstan` (level 6); don't fix errors by adding to the baseline.
- **Tests.** Add Pest tests in the matching suite that exercise the case you're fixing; run `composer test` and
  `composer test:browser` (needs Node + `npx playwright install chromium`).
- **Docs.** Update `README.md` for user-visible changes and add a line under `Unreleased` in `CHANGELOG.md`.

Happy hacking!
