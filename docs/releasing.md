# Cutting a release

How a version of Ticktz gets published, and what has to be true in GitHub for
it to work. Written for the person who owns the repository.

The short version: **push a tag, then publish the draft.** Everything between
those two is the workflow's job.

---

## What one release produces

| | |
| --- | --- |
| `ghcr.io/<owner>/ticktz:<version>` | The container image, for `linux/amd64` and `linux/arm64` |
| `ticktz-<version>.zip` | The whole application, ready to run — `vendor` and the built assets included |
| `ticktz-<version>.zip.sha256` | The digest an in-place upgrade checks the archive against |
| A build attestation | Signed proof the archive came out of this repository, at this commit |

The zip is the one that matters for upgrading from the browser. A GitHub source
archive carries neither `vendor` nor the built assets, so installing one would
mean running Composer and npm on somebody's server, against two package
registries, at whatever version of each tool happens to be there. The archive
built here is the application itself, so an upgrade is a download and a swap.

It is the same shape Nextcloud and Matomo publish, and for the same reason.

---

## Setting it up in GitHub

Four settings. Three of them are one click, and none of them is a secret you
have to store.

### 1. Let Actions write releases and packages

**Settings → Actions → General → Workflow permissions**

Choose **Read and write permissions**.

The workflow also asks for what it needs explicitly:

```yaml
permissions:
  contents: write     # create the release, attach the archive
  packages: write     # push the image to ghcr.io
  id-token: write     # sign the attestation
  attestations: write
```

An organisation can forbid write permissions at the organisation level, and
that setting wins. If releases fail with `Resource not accessible by
integration`, that is where to look — not at the workflow.

### 2. Nothing to configure for the container registry

`GITHUB_TOKEN` already has access to `ghcr.io` for its own repository. There is
no secret to create and no password to rotate.

The first image published to a repository is **private**. To let people pull it
without a login:

**Your profile (or the organisation) → Packages → ticktz → Package settings →
Change visibility → Public**

Once. It stays public for every version after.

### 3. Nothing to configure for attestations

`actions/attest-build-provenance` signs with a short-lived certificate issued
to the workflow itself and records it in a public transparency log. There is no
private key, so there is nothing to store, rotate or lose.

Anybody can check an archive against it:

```bash
gh attestation verify ticktz-1.1.0.zip --repo <owner>/ticktz
```

That answers a different question from the checksum, and a more useful one. A
checksum says the bytes did not change on the way from GitHub. An attestation
says the bytes were built by this repository's workflow from a named commit —
which is the question you actually want answered before you replace a running
application with them.

### 4. Protect the tag, if more than one person can push

**Settings → Rules → Rulesets → New ruleset → Tag**

Target `v*` and restrict who may create a matching tag. A tag is what starts a
release, so whoever can push one can publish one.

---

## Cutting a release

```bash
# 1. The version lives in two files. They must agree — the update screen reads
#    the first and the built assets carry the second.
#    config/ticktz.php  →  'version' => env('TICKTZ_VERSION', '1.1.0')
#    package.json       →  "version": "1.1.0"

# 2. Write the changelog entry. The generated notes are a commit list; the
#    paragraph a person reads is the one you write.
$EDITOR CHANGELOG.md

git commit -am "Release 1.1.0"
git push

# 3. Tag it. This is the trigger.
git tag -a v1.1.0 -m "Ticktz 1.1.0"
git push origin v1.1.0
```

The workflow then runs the full suite, builds and pushes the image, builds the
archive, and opens a **draft** release with everything attached.

**The draft is deliberate.** The generated notes are a list of commits, and a
release worth tagging is worth a paragraph. Open it, write the paragraph, press
**Publish release**.

Nothing reaches an instance before you publish: the update check reads
published releases only, and a draft is not one.

### Publishing from the browser instead

**Actions → Release → Run workflow**, and give it the tag. Same result, useful
when the tag already exists and something needs rebuilding.

---

## What a version number means here

Semantic versioning, and the promise is what makes automatic checking safe:

| | |
| --- | --- |
| **Patch** `1.1.0 → 1.1.1` | Fixes. Never a manual step, never a database change that matters |
| **Minor** `1.1.1 → 1.2.0` | Features. Never a manual step |
| **Major** `1.2.0 → 2.0.0` | May need a manual step during the upgrade |

The update screen leads with which of those a given upgrade is, so keeping the
promise is what keeps that screen honest.

A pre-release is a tag like `v1.2.0-beta.1`. Mark it as a pre-release on the
GitHub release, and instances only see it if their operator asked for
pre-releases.

---

## Checking a release without installing it

```bash
gh release download v1.1.0 --repo <owner>/ticktz --pattern 'ticktz-*'

# The digest the updater checks.
sha256sum -c <(printf '%s  %s\n' "$(cat ticktz-1.1.0.zip.sha256)" ticktz-1.1.0.zip)

# Where it was built, and from what.
gh attestation verify ticktz-1.1.0.zip --repo <owner>/ticktz

# What is in it.
unzip -l ticktz-1.1.0.zip | head -30
```

The archive should contain one top-level `ticktz/` directory, a `build.json`
naming the version and commit, a `vendor/`, a `public/build/`, and a `storage/`
that is empty. It should contain no `.env`, no `tests`, no `node_modules` and
no `.git`.

---

## What it costs

`rorymeijer/ticktz` is a **private** repository, so Actions minutes come out of
the account's monthly allowance. On a public repository all of this is free and
none of the below applies.

Check the real numbers against your own plan — GitHub moves them, and this was
written in September 2026:

**Settings → Billing and licensing → Usage**

Roughly, at the time of writing: a Free account gets 2,000 minutes a month, Pro
and Team 3,000. Every job here runs on Linux, which bills at ×1 — macOS is ×10
and Windows ×2, so it is worth noticing that nothing here needs either.

A release costs somewhere around 25–40 minutes. Most of that is the container
image: `linux/arm64` is built under emulation on an amd64 runner, which is slow
in a way nothing else here is. The archive and the test suite are a few minutes
each.

**The recurring cost is CI, not releases.** A full run is about ten minutes, and
it runs on every push to an open pull request. Ten pushes in a day is roughly a
hundred minutes.

Two things keep that down, both already in place:

- **One run per commit.** `push` limited to `main`, everything else checked
  through the pull request. Running both — which is the default shape people
  write — costs double, and the concurrency group does not collapse the pair
  because a push and a pull_request carry different refs.
- **Superseded runs are cancelled.** Pushing three times in a row leaves one
  run standing, not three.

Storage is separate from minutes and easier to overlook. Release assets are
free and unlimited; **Actions artifacts are not** and count against the
account's storage quota, which starts at 500 MB on Free. The installable
archive is uploaded as an artifact only to hand it from one job to the next, so
it is kept for a day rather than a week. Container images in `ghcr.io` count
too when the package is private, which is one more reason to make it public.

## When it goes wrong

**`Resource not accessible by integration`** — workflow permissions, step 1
above. Check the organisation-level setting as well as the repository one.

**The image pushes but nobody can pull it** — the package is still private,
step 2.

**The release has no `ticktz-*.zip`** — the `bundle` job failed. It runs after
`verify`, so a failing test stops it; the image is built in parallel and can
succeed while the archive does not, which is why a release can appear with an
image and no archive. Instances see that and say the release cannot be
installed in place rather than installing something they did not verify.

**`npm run build` fails in `bundle` but works locally** — `npm ci` installs
what `package-lock.json` says, exactly. If a dependency was added with
`npm install` and the lockfile was not committed, the runner does not have it.

---

## Where the other half lives

What an instance does with all this — checking for a new release, deciding
whether it can install one, and installing it — is in
[`docs/self-hosting.md`](self-hosting.md#upgrading) and in the decisions
record. The short version: a source installation can do it from the browser, a
Docker installation cannot and is shown the command instead, because replacing
a running container needs the daemon and a web-facing PHP process must never be
able to reach that.
