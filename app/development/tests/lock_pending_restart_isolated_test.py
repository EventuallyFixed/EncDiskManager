#!/usr/bin/env python3
"""Isolated restart fail-closed test: no NAS paths, no disk operations."""
import fcntl
import os
from pathlib import Path
import subprocess
import sys
import tempfile

COORDINATOR = r"""
import fcntl,os,sys
from pathlib import Path
root=Path(sys.argv[1])
lock=root/'disk-operations.lock'
pending=root/'pending'
fd=os.open(lock,os.O_RDWR|os.O_CREAT,0o600)
try:
    fcntl.flock(fd,fcntl.LOCK_EX)
    if pending.exists():
        print('BLOCKED: pending record exists',flush=True)
        sys.exit(12)
    if sys.argv[2]=='restart':
        print('AUTHORIZED: no pending record',flush=True)
        sys.exit(0)
    marker=os.open(pending,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    try:
        os.write(marker,b'pending\n')
        os.fsync(marker)
    finally:
        os.close(marker)
    directory=os.open(root,os.O_RDONLY|os.O_DIRECTORY)
    try: os.fsync(directory)
    finally: os.close(directory)
    print('PENDING: coordinator exits without clearing',flush=True)
finally:
    os.close(fd)
"""

with tempfile.TemporaryDirectory(prefix="edm-pending-restart-") as tmp:
    root=Path(tmp)
    def run(mode):
        return subprocess.run([sys.executable,"-c",COORDINATOR,str(root),mode],
                              capture_output=True,text=True)
    first=run("start")
    assert first.returncode==0,(first.stdout,first.stderr)
    assert (root/"pending").read_bytes()==b"pending\n"
    for _ in range(3):
        again=run("restart")
        assert again.returncode==12,(again.stdout,again.stderr)
        assert "BLOCKED" in again.stdout
    assert (root/"pending").read_bytes()==b"pending\n"
    print("PASS: fresh coordinators refuse unresolved pending record")
    print("LIMIT: orderly exit simulated; no power loss or live NAS tested")
