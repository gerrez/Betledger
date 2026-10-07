# One-time setup (owner)

Steps that need you, in order. Claude can't (and shouldn't be able to) do these.

## 1. Create the GitHub repo and push

From the project folder:

```bash
gh repo create betledger --private --source . --remote origin --push
```

## 2. Protect `main`

This is the real safety net. `.claude/settings.json` blocks pushing to `main`, but only
GitHub can *enforce* it.

GitHub → repo → **Settings → Rules → Rulesets → New branch ruleset**:

- Target: default branch (`main`), enforcement **Active**
- ✅ Restrict deletions
- ✅ Require a pull request before merging (0 approvals is fine when you're solo; you are
  the one clicking merge)
- ✅ Require status checks to pass → add **`checks`** (the job in `ci.yml`; it shows up
  after the first CI run)
- ✅ Block force pushes

> Rulesets on **private** repos need GitHub Pro (or Team). On GitHub Free, either upgrade,
> make the repo public, or accept that the Claude-side deny rules are the only guard.

## 3. Starter kit CI files

The starter kit shipped `.github/workflows/tests.yml` and `lint.yml`. `ci.yml` covers both
(plus static analysis), and `lint.yml` expects Flux Pro secrets you don't need. Suggested:
delete both so each PR runs one workflow. Claude left them for you to decide.

## 4. Connect Claude Code on the web

1. Go to <https://claude.ai/code> and connect GitHub.
2. Install the Claude GitHub app on **only** the `betledger` repository.
3. Create a cloud environment for the repo:
   - **Network access: Limited** (the default allowlist covers package registries such as
     Packagist and npm). Full internet isn't needed.
   - No environment variables or secrets are needed; `.env` is generated from
     `.env.example` by `.claude/hooks/session-start.sh`.
   - If the first session reports that `php`, `composer` or `node` is missing, add an
     install step to the environment's setup script.

## 5. First cloud session (smoke test)

Prompt: _"Do the next unchecked task in docs/roadmap.md."_

That's the harness smoke-test task. Check that the PR appears, CI is green, and the
description reports the hook ran. Then merge it yourself.

## Day-to-day loop

1. Start a cloud session: "Do the next unchecked task in docs/roadmap.md" (or a specific one).
2. Review the PR on GitHub. Request changes in the session or with PR comments.
3. Merge when CI is green and you're happy.

## Running locally

```bash
composer run dev
```

Then open <http://localhost:8000> and register an account.
