#!/usr/bin/env python3
"""Normalize a staged tar stream into a byte-stable Slackware tar.xz package."""

import io
import lzma
import sys
import tarfile


def main() -> None:
    commit_epoch = int(sys.argv[1])
    with tarfile.open(fileobj=io.BytesIO(sys.stdin.buffer.read()), mode="r:") as source:
        members = sorted(source.getmembers(), key=lambda member: member.name.rstrip("/"))
        names = {member.name.rstrip("/") for member in members}
        if "./install/slack-desc" not in names or "./usr/local/emhttp/plugins/unraid-certbot" not in names:
            raise RuntimeError("incomplete package staging tree")

        with lzma.LZMAFile(sys.stdout.buffer, mode="wb", preset=6) as compressed:
            with tarfile.open(fileobj=compressed, mode="w|", format=tarfile.GNU_FORMAT) as output:
                for member in members:
                    member.uid = member.gid = 0
                    member.uname = member.gname = ""
                    member.mtime = commit_epoch
                    member.pax_headers = {}
                    if member.isdir():
                        member.mode = 0o755
                    elif member.isfile():
                        executable = member.name == "./install/doinst.sh" or "/scripts/" in member.name or "/event/" in member.name
                        member.mode = 0o755 if executable else 0o644
                    output.addfile(member, source.extractfile(member) if member.isfile() else None)


if __name__ == "__main__":
    main()
