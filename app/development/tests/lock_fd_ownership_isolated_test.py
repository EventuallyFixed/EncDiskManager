#!/usr/bin/env python3
"""Linux flock semantics experiment, using temporary files only.

Shows that a competing independently opened descriptor can detect contention,
but that this is not authentication of the inherited descriptor's lock owner.
No NAS paths, privileged operations, or process termination.
"""
import fcntl
import os
from pathlib import Path
import subprocess
import sys
import tempfile

with tempfile.TemporaryDirectory(prefix="edm-flock-ownership-") as tmp:
    lock = Path(tmp) / "lock"
    lock.touch()
    parent_fd = os.open(lock, os.O_RDWR)
    try:
        def contender():
            code = (
                "import fcntl,os,sys;"
                "fd=os.open(sys.argv[1],os.O_RDWR);"
                "\ntry: fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)"
                "\nexcept BlockingIOError: sys.exit(10)"
                "\nelse: fcntl.flock(fd,fcntl.LOCK_UN);sys.exit(0)"
            )
            return subprocess.run([sys.executable, "-c", code, str(lock)],
                                  capture_output=True, text=True).returncode

        assert contender() == 0, "unlocked file unexpectedly contended"
        fcntl.flock(parent_fd, fcntl.LOCK_EX)
        assert contender() == 10, "independent contender entered held lock"

        # A child sharing the same open-file description may re-flock it.
        code = ("import fcntl,sys;"
                "fcntl.flock(int(sys.argv[1]),fcntl.LOCK_EX|fcntl.LOCK_NB)")
        subprocess.run([sys.executable, "-c", code, str(parent_fd)],
                       pass_fds=(parent_fd,), check=True)
        assert contender() == 10, "inherited re-flock released the lock"
        fcntl.flock(parent_fd, fcntl.LOCK_UN)
        assert contender() == 0, "lock remained after explicit unlock"
        print("PASS: isolated Linux inherited flock semantics")
        print("LIMIT: independent contention is not proof of descriptor ownership")
    finally:
        os.close(parent_fd)
