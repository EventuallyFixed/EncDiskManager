#!/usr/bin/env python3
"""Temporary-file-only native FD identity regression; no NAS paths."""
import fcntl
import os
from pathlib import Path
import subprocess
import tempfile

source = Path(__file__).resolve().parents[1] / "native/lock_fd_probe_isolated.c"
with tempfile.TemporaryDirectory(prefix="edm-fd-probe-") as tmp:
    root = Path(tmp)
    binary = root / "probe"
    subprocess.run(["cc", "-std=c11", "-Wall", "-Wextra", "-Werror",
                    "-pedantic", str(source), "-o", str(binary)], check=True)
    lock = root / "lock"
    wrong = root / "wrong"
    lock.touch()
    wrong.touch()
    fd = os.open(lock, os.O_RDWR)
    try:
        def check(args, expected, inherit=(fd,)):
            result = subprocess.run([str(binary), *args], pass_fds=inherit,
                                    capture_output=True, text=True)
            assert (result.returncode == 0) == expected, (
                args, result.returncode, result.stdout, result.stderr)
        check([str(fd), str(lock)], True)
        check([str(fd), str(wrong)], False)
        check(["-1", str(lock)], False)
        check([str(fd), str(lock)], False, ())
        link = root / "symlink"
        link.symlink_to(lock)
        check([str(fd), str(link)], False)
        moved = root / "moved"
        lock.rename(moved)
        lock.touch()
        check([str(fd), str(lock)], False)
        check([str(fd), str(moved)], True)
        # Identity alone cannot prove flock ownership: an unlocked FD passes.
        print("PASS: inherited FD identity and negative-path checks")
        print("NOTE: unlocked FD also passes; ownership remains unverified")
        fcntl.flock(fd, fcntl.LOCK_EX)
        check([str(fd), str(moved)], True)
        fcntl.flock(fd, fcntl.LOCK_UN)
    finally:
        os.close(fd)
