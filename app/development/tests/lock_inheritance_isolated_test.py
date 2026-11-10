#!/usr/bin/env python3
"""Isolated Linux flock semantics experiment. No NAS paths or disk operations."""
import fcntl
import os
import pathlib
import subprocess
import sys
import tempfile

PROBE = """
import fcntl, os, sys
fd = os.open(sys.argv[1], os.O_RDWR)
try:
    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
except BlockingIOError:
    sys.exit(9)
else:
    fcntl.flock(fd, fcntl.LOCK_UN)
    sys.exit(0)
finally:
    os.close(fd)
"""

def probe(path):
    return subprocess.run([sys.executable, "-c", PROBE, str(path)], check=False).returncode

with tempfile.TemporaryDirectory(prefix="edm-flock-test-") as tmp:
    path = pathlib.Path(tmp) / "disk-operations.lock"
    path.touch(mode=0o600)
    fd = os.open(path, os.O_RDWR)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        assert probe(path) == 9, "independent process acquired held lock"
        print("PASS: independent process blocked by lock")
        # Inheriting a descriptor retains the same open-file-description lock.
        child = subprocess.Popen(
            [sys.executable, "-c", "import os,sys; os.fstat(int(sys.argv[1]))", str(fd)],
            pass_fds=(fd,),
        )
        assert child.wait(timeout=10) == 0
        assert probe(path) == 9, "child exit unexpectedly released parent lock"
        print("PASS: inherited FD survives child exit without unlocking parent")
        fcntl.flock(fd, fcntl.LOCK_UN)
        assert probe(path) == 0, "independent process could not acquire released lock"
        print("PASS: independent process acquires released lock")
    finally:
        os.close(fd)
# Separate experiment: closing the parent FD must not release an inherited
# open-file-description lock until the child also closes its copy.
with tempfile.TemporaryDirectory(prefix="edm-flock-child-") as tmp:
    path = pathlib.Path(tmp) / "disk-operations.lock"
    path.touch(mode=0o600)
    fd = os.open(path, os.O_RDWR)
    child = None
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        child_code = (
            "import os,sys; "
            "os.fstat(int(sys.argv[1])); "
            "print('READY', flush=True); "
            "sys.stdin.readline()"
        )
        child = subprocess.Popen(
            [sys.executable, "-c", child_code, str(fd)],
            pass_fds=(fd,),
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            text=True,
        )
        assert child.stdout.readline().strip() == "READY"
        os.close(fd)
        fd = -1
        assert probe(path) == 9, "parent close released child-inherited lock"
        print("PASS: child retains inherited lock after parent closes FD")
        child.stdin.write("\\n")
        child.stdin.flush()
        assert child.wait(timeout=10) == 0
        assert probe(path) == 0, "lock remained held after child exit"
        print("PASS: inherited lock released after final holder exits")
    finally:
        if fd >= 0:
            os.close(fd)
        if child is not None and child.poll() is None:
            child.kill()
            child.wait(timeout=10)
print("NOTE: Linux host experiment only; PHP/EX4100 semantics unverified")
