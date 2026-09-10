# Smart Login — project rules for Claude Code

WordPress / WooCommerce plugin. Shortcode `[smart_login_form]`: login + registration
with email verification (code + link), bot protection, lockout, forgot-password,
optional WooCommerce integration. Class prefix `SML_`. OOP, WordPress coding standards.

Repo: `github.com/maxazarpixel/smart-login` (private). This repo is the plugin root
(no wrapper folder). It was split out of the `smart_login` monorepo with history kept.

## Language

- **All project content is English** — code, comments, docblocks, commit messages,
  `readme.txt`, this file. Never write Persian in the repo.
- The user often writes to Claude in Persian for convenience; reply language may
  follow the user, but nothing Persian goes into a committed file.

## Git workflow — two Claude Code instances share this repo

Two machines/sessions work on this project. To stay in sync:

1. **At the start of every session and before every push**, run
   `git pull --rebase origin main`.
2. After each self-contained change: `git add -A` → `git commit` (English message)
   → `git pull --rebase origin main` → `git push origin main`. Do this automatically,
   without asking each time.
3. One logical change per commit — commit at natural stopping points, not after
   every tiny edit.
4. If `git pull --rebase` reports a conflict, or `git push` is rejected: **stop and
   surface it**. Never `git push --force` to get past a conflict.
5. Never run both Claude Code instances editing this working tree at the same time.
   Finish and push from one before starting the other.

## Versioning

- Follow semantic versioning. Bump the version on any user-facing or security change:
  `smart-login.php` header `Version:`, the `SML_VERSION` constant, and `readme.txt`
  `Stable tag:` must always match.
- Add a `readme.txt` changelog entry (newest first, under `== Changelog ==`) for
  every version bump.

## Build / packaging

- Do **not** create a zip file after developing the plugin.
- Keep to the standard WordPress plugin file layout already in place.

## Auth note

The `gh` CLI on at least one machine is signed in as a different GitHub account that
cannot see this private repo, so `gh api` / `gh pr create` may fail. Plain `git`
(clone / pull / push) works. For a PR, push the branch and open it in the browser.
On Windows, `git config core.longpaths true` is needed in this clone.
