# Build and release

Run commands from the repository root. `tools/build.sh` requires Docker.

| Task | Command | Effect |
|---|---|---|
| Local package | `tools/build.sh --local` | Packages the current worktree into `dist/` and updates the local `.plg` checksum. Not for release. |
| Reproducible package | `tools/build.sh` | Packages committed source; requires clean build inputs and matching `VERSION`. |
| New version | `tools/release-new.sh [YYYY.MM.DD]` | Requires a clean default branch, runs checks, pushes the branch and a new tag, then dispatches CI for that tag. |
| Existing version | `tools/republish-old.sh YYYY.MM.DD` | Run from a clean committed branch whose `VERSION` matches. Pushes the branch, moves the existing tag to that commit, then dispatches CI for that tag. |

The version tag is always the workflow's build input. Republishing keeps the previous package and publishes the next package revision, such as `-noarch-2.txz`. The workflow verifies the new asset's SHA256 before replacing the Release's `unraid-certbot-YYYY.MM.DD.plg`, then moves the tag to a commit containing that matching `.plg`. When the tag points to the default branch's HEAD and its `VERSION` matches, the workflow also updates the default installation entry. Older versions leave that entry alone. Install an older version using the versioned `.plg` from its Release.
